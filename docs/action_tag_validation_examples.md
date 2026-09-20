# Action Tag Validation Examples

A worked, parameter-by-parameter guide to the five action tags of the **Universal
Field Validator** module (current implementation). Every tag is shown from its
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
- [`@UVCHOICES` — dropdowns and autocomplete](#uvchoices--dropdowns-autocomplete-radio-and-checkbox-choices)
- [Several ID formats (`alternates`)](#several-formats-on-one-field-alternates)
- [Validation across events and repeating instruments](#validation-across-events-and-repeating-instruments)
- [Combining tags on one field](#combining-tags-on-one-field)
- [Branching — several tags of the same kind](#branching--several-tags-of-the-same-kind)
- [The `when` condition language](#the-when-condition-language)
- [Examples cookbook](#examples-cookbook)
  - [`@UVALIDATE` recipes](#uvalidate-recipes)
  - [`@UVASSERT` recipes](#uvassert-recipes)
  - [`@UVREQUIRED` recipes](#uvrequired-recipes)
  - [`@UVUNIQUE` recipes](#uvunique-recipes)
  - [Combination recipes](#combination-recipes)
- [Tags the module refuses](#tags-the-module-refuses)
- [The Configure dialog, setting by setting](#the-configure-dialog-setting-by-setting)
- [Parameter reference tables](#parameter-reference-tables)
- [Copy-paste cheat sheet](#copy-paste-cheat-sheet)

---

## The five tags at a glance

| Tag             | What it checks                                                                  | Field types it may sit on                                      |
| --------------- | ------------------------------------------------------------------------------- | -------------------------------------------------------------- |
| `@UVALIDATE`  | The value's**check character and/or format** (an ID is well-formed)       | Text, Notes                                                    |
| `@UVASSERT`   | A**condition across fields** holds (end ≥ start, dose ≤ max)            | Text, Notes, dropdown, radio, yes/no, true/false, calc, slider |
| `@UVREQUIRED` | The field is**not blank**, optionally only while a condition is true      | Same as above,**minus calc**                             |
| `@UVUNIQUE`   | The value is**not used by another record**                                | Same as above,**minus calc**                             |
| `@UVCHOICES`  | Which**options are offered** — show/hide choices while a condition holds | radio, dropdown, checkbox (not matrix)                         |

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

| Value                 | Behavior                                         | Dialog label  |
| --------------------- | ------------------------------------------------ | ------------- |
| `off` *(default)* | Shows the message; the save proceeds             | Informational |
| `confirm`           | Asks "save anyway?" — the user may override     | Advisory      |
| `hard`              | Blocks the browser save until the value is fixed | Compulsory    |

Enforcement is a **browser behavior**. It does not block API or Data Import Tool
writes; those are covered by the post-save audit (coverage is REDCap-version
dependent) and reliably by the **Validation scan** page. Read-only fields show the
notice but never block a save.

**`when` gates any rule.** Any tag may carry a `when` condition; the rule validates
only while that condition is true. A false `when` skips the rule — it never erases
the value. See [The `when` condition language](#the-when-condition-language).

**Text in conditions ignores letter case.** `[status]='active'` is true for `Active`
and `ACTIVE`, in every tag's `when`, in branch selectors and in `@UVASSERT`. Add
`"caseSensitive":true` to a tag's JSON for exact matching. See
[Letter case](#letter-case).

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

Every algorithm, once by canonical name and once by each accepted shorthand. Pick the
line that matches how your IDs were minted; the lines on one row are equivalent.

```text
@UVALIDATE=iso7064_mod37_36     same as: 3736  37,36  37_36  37-36  mod37_36  mod3736
@UVALIDATE=iso7064_mod11_10     same as: 1110  11,10  11_10  11-10  mod11_10  mod1110
@UVALIDATE=iso7064_mod97_10     same as: 9710  97,10  97_10  97-10  mod97_10  mod9710
@UVALIDATE=iso7064_mod11_2      same as: 112   11,2   11_2   11-2   mod11_2   mod112
@UVALIDATE=iso7064_mod37_2      same as: 372   37,2   37_2   37-2   mod37_2   mod372
@UVALIDATE=iso7064_letters1     same as: letters1  letter1
@UVALIDATE=iso7064_letters2     same as: letters2  letter2
@UVALIDATE=damm                 no shorthand
@UVALIDATE=verhoeff             no shorthand
@UVALIDATE=luhn                 same as: mod10
@UVALIDATE=gs1_mod10            same as: gs1  gtin  ean  upc
@UVALIDATE=aba_mod10            same as: aba  routing
@UVALIDATE=mrz_mod10            same as: mrz  icao
@UVALIDATE=weighted_mod11       same as: isbn  mod11w  weighted11
```

The shorthands work in the short form and as the JSON `algorithm` value:

```text
@UVALIDATE=37,36
@UVALIDATE=mod1110
@UVALIDATE=97-10
@UVALIDATE=11_2
@UVALIDATE=mod372
@UVALIDATE=letters1
@UVALIDATE=letter2
@UVALIDATE=mod10
@UVALIDATE=gtin
@UVALIDATE=upc
@UVALIDATE=routing
@UVALIDATE=icao
@UVALIDATE=mod11w
@UVALIDATE={"algorithm":"weighted11","blockSave":"confirm"}
```

See [Algorithms](#algorithms) and [Algorithm shorthands](#algorithm-shorthands) below
for what each one accepts and a worked payload.

### Level 3 — enforcement

```text
@UVALIDATE={"blockSave":"off"}                        message only (the default, written out)
@UVALIDATE={"blockSave":"confirm"}                    "save anyway?" prompt
@UVALIDATE={"algorithm":"damm","blockSave":"hard"}    no browser save until fixed
```

Writing `"off"` explicitly is useful in a branch set, where one branch blocks and
another only informs.

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
@UVALIDATE={"algorithm":"3736","source":"normalized_id"}      TBABC-00239K   -> the algorithm sees TBABC00239K
@UVALIDATE={"algorithm":"mod11_10","source":"digits_only"}    KL2-0792       -> sees 20792 (every digit, letters dropped)
@UVALIDATE={"algorithm":"verhoeff","source":"sequence_only"}  KL2A-1234568   -> sees 1234568 (the last run of digits only)
```

| `source`                      | The algorithm sees                                               |
| ------------------------------- | ---------------------------------------------------------------- |
| `normalized_id` *(default)* | The whole normalized value                                       |
| `digits_only`                 | Only the digits — for a mixed ID whose check covers the numbers |
| `sequence_only`               | Only the sequence portion                                        |

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

`keepChars` (pooled rules only) lists extra characters to keep while a pooled field is
cleaned. Before splitting, a pooled value keeps only `A-Z`, `0-9`, the characters the
pattern itself spells out (such as `-`), and the check algorithm's own special
characters. Everything else, including spaces, commas and new lines, is removed.
Name a character in `keepChars` when it is part of an ID and nothing else protects it:

```text
# IDs printed as KLA.0792 — the dot is part of the ID, written as "." in the pattern class
@UVALIDATE={"type":"pooled","algorithm":"none","pattern":"[A-Z]{3}[.#][0-9]{4}","idLengths":[8],"keepChars":".#"}

# Pooled alternates where one format can end in "*" (Mod 37,2) and another cannot:
# declare the union so both formats are cleaned the same way
@UVALIDATE={"type":"pooled","keepChars":"*","alternates":[
  {"label":"OLD","pattern":"OL[0-9]{5}[0-9A-Z*]","algorithm":"372","lengths":[8]},
  {"label":"NEW","pattern":"NW[0-9]{5}[0-9A-Z]","algorithm":"3736","lengths":[8]}
]}
```

`keepChars` takes up to 64 printable ASCII characters.

### Level 8 — pooled fields (many IDs in one box)

A pooled rule reads several IDs from one field.

```text
@UVALIDATE={"type":"pooled","idLengths":[9],"expectedIds":3}
@UVALIDATE={"type":"pooled","algorithm":"none","pattern":"FC[0-9]{4}","idLengths":[6]}
@UVALIDATE={"type":"pooled","idMinLen":9,"idMaxLen":12,"blockSave":"confirm"}
```

| Option          | Default    | Meaning                                                          |
| --------------- | ---------- | ---------------------------------------------------------------- |
| `idLengths`   | *(none)* | Exact length(s):`[9]`, `[10,12]`, or the string `"10, 12"` |
| `idMinLen`    | `8`      | Minimum length when no exact lengths are given                   |
| `idMaxLen`    | `14`     | Maximum length; must be**less than 2× the minimum**       |
| `expectedIds` | *(none)* | How many IDs the field should contain; a mismatch is reported    |

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

### Several formats on one field (`alternates`)

Use one `alternates` list when the **entered ID itself** determines its format.
Each entry has its own regex and check-character algorithm; a value is valid when
one complete alternate passes. This differs from repeated tags with `when`, where
another answer chooses the rule. Do not create several unconditional `@UVALIDATE`
tags to express alternatives: those tags conflict.

One ID per field:

```text
@UVALIDATE={"strip":"-","blockSave":"hard","alternates":[
  {"label":"GHIT","pattern":"FC[1-9]-[0-9]{4}","algorithm":"none"},
  {"label":"START4KIDS","pattern":"SK[1-5]-[0-9]{4}[0-9A-Z]","algorithm":"3736"},
  {"label":"DARETB","pattern":"DT[1-2]-[0-9]{5}[0-9A-Z]","algorithm":"3736"},
  {"label":"SCREENTB","pattern":"ST[1-5]-[0-9]{5}[0-9A-Z]","algorithm":"3736"}
]}
```

Several IDs in a Text or Notes field, including a mixture of studies:

```text
@UVALIDATE={"type":"pooled","strip":"-","blockSave":"hard","alternates":[
  {"label":"GHIT","pattern":"FC[1-9]-[0-9]{4}","algorithm":"none","lengths":[8]},
  {"label":"START4KIDS","pattern":"SK[1-5]-[0-9]{4}[0-9A-Z]","algorithm":"3736","lengths":[9]},
  {"label":"DARETB","pattern":"DT[1-2]-[0-9]{5}[0-9A-Z]","algorithm":"3736","lengths":[10]},
  {"label":"SCREENTB","pattern":"ST[1-5]-[0-9]{5}[0-9A-Z]","algorithm":"3736","lengths":[10]}
]}
```

`GHIT` checks format only; the other three also verify ISO 7064 MOD 37,36.
A matching regex alone does not make their final character valid.

| Alternate key         | Meaning                                                                                                                                                               |
| --------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `label`             | Human-readable format name in validation feedback.                                                                                                                    |
| `pattern`           | Required whole-ID regex, checked before separator stripping.                                                                                                          |
| `algorithm`         | This format's algorithm; inherits the rule's algorithm when omitted. Use`none` explicitly for format-only IDs.                                                      |
| `source`, `strip` | Optional per-format overrides of the rule's normalization settings.                                                                                                   |
| `lengths`           | Required for each pooled alternate: candidate lengths including characters retained by its pattern, such as the hyphen in these examples. Omit for a single-ID field. |

The lengths above are **8, 9, 10 and 10**, including the hyphen. `strip:"-"`
removes that hyphen for the check-character calculation, not for the regex or pooled
candidate length. Keep lengths inside the alternates; do not also set rule-level
`idLengths`, `idMinLen`, `idMaxLen`, or `pattern`. An optional rule-level
`expectedIds` requires that many valid members. Use a Notes field for longer lists.
Put the most specific formats first if patterns overlap; each accepted member must
pass one complete format. The existing pooled work limits and ambiguity checks apply.

### Full `@UVALIDATE` JSON keys

`type`, `algorithm`, `source`, `pattern`, `strip`, `keepChars`, `idLengths`,
`idMinLen`, `idMaxLen`, `expectedIds`, `blockSave`, `when`, `suggestFix`, `note`,
`caseSensitive`, `alternates`, `references`.

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
  Most field types may be *referenced* inside the condition, checkbox included (as
  `[field(code)]`). The two exceptions are **file** and **descriptive** fields:
  they have no comparable value, and referencing one is a configuration error when the
  rule is saved (`Logic::checkRefs`).
- The server audit honors the constraint against saved values (logged as
  `type: constraint`).
- **Cross-form (1.6.0).** A comparison between the field you are typing in and a field
  on **another instrument in the same event** is checked **live**, provided you are
  entitled to read that instrument — staff data entry, with REDCap rights to it. The
  value is resolved on the server and baked into the condition, so the check reacts to
  every keystroke exactly as a same-instrument one does.
- **A failure names what it was compared against.** On a staff form the message ends
  with *"(compared against `scr_screen_date`, read when this page was opened — reload if
  it has changed since.)"*, so a stale snapshot is distinguishable from a real
  violation. Survey respondents get the plain message, without field names.
- On a **survey**, or for a user **without rights** to the referenced instrument, the
  value is never sent to the page. The rule is then **deferred**.
- **Deferred means detection, not prevention.** A deferred rule shows no verdict and
  never blocks, so the save is **accepted** and the value is written.
  `redcap_save_record` fires *after* the write: the audit logs the violation to the
  module log once it has happened, and the Validation scan finds it later on demand.
  Deferring costs live feedback **and** the block; someone has to read the log or run
  the scan.
- **Unresolvable references are refused, not guessed (1.6.0).** See
  [the condition language](#the-when-condition-language) for the three cases — a
  different repeating instrument, a field not collected in this event, and a failed
  read — and what still resolves normally.

Text in the condition ignores letter case (`[answer]='yes'` accepts `YES`); set
`"caseSensitive":true` for exact matching. See [Letter case](#letter-case).

### `@UVASSERT` JSON keys

`assert`, `message`, `blockSave`, `when`, `caseSensitive`. Any other key is a
configuration error.

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

`when`, `message`, `blockSave`, `caseSensitive`. Any other key is a configuration error.

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

`with`, `scope`, `when`, `message`, `blockSave`, `surveys`, `caseSensitive`. Any other
key is a configuration error. `caseSensitive` governs the `when` only; the duplicate
check itself always compares values exactly.

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
  site and the stale site stays visible (a dropdown keeps it in place, greyed
  but still enabled, because a browser does not submit a disabled selected
  option), the field is flagged with your message, and `blockSave` decides
  whether the save is challenged. The module never erases an entered value.
- A value outside the field's choice list entirely (a missing-data code such
  as `-99`) is out of the filter's scope and never flagged.
- Conditions may reference fields on other instruments in the same event; they
  are resolved server-side against saved values (see the `when` semantics above
  for when such a comparison stays live and when it is settled at page load).
- Not available on yes/no, true/false, sql, or matrix fields — the tag is
  refused there with a configuration error.
- The post-save audit logs a saved hidden choice as `type: choices`,
  `reason: hidden-choice`; the Validation scan reports the same.

### `@UVCHOICES` JSON keys

`show` **or** `hide` (exactly one, a list of choice codes), `when`, `message`,
`blockSave`, `caseSensitive`. Any other key is a configuration error.

---

## `@UVCHOICES` — dropdowns, autocomplete, radio and checkbox choices

Attach the rules to the field being filtered (`site` below), not the controlling
field (`region`). Use stored **codes**, not labels; uppercase codes such as `1BAM`
are significant. The codes must already exist in the target field's choice list.
The same annotation applies to radio buttons and single-answer dropdowns, with or
without **Enable auto-complete for this drop-down**. Matrix fields are unsupported.

```text
@UVCHOICES={"when":"[region]='1'","show":["1BAM","1CME"],"blockSave":"hard"}
@UVCHOICES={"when":"[region]='2'","show":["2ADI","2ARI"],"blockSave":"hard"}
```

For each active branch, `show` keeps its listed codes; `hide` hides listed codes.
Use exactly one of these keys. With no matching branch and no fallback, all original
choices remain available. To require a region first, use REDCap field branching
`[region]<>''` on `site`, and add `@UVREQUIRED` if a site answer is mandatory.
`@UVCHOICES` alone does not require an answer.

Changing region never deletes a site already entered. A site excluded by the new
branch stays visible and is marked invalid; `blockSave:"hard"` challenges the browser
save until it is corrected. Dropdowns keep that stale option greyed and marked
`data-uv-stale`, never disabled, so the answer on screen is the answer REDCap
receives; autocomplete keeps the entered text but excludes it from new suggestions.
On a multi-page survey, put the controlling field on the same page as the filtered
field: a field answered on an earlier page is not in the page's form, so the filter
cannot read it there and only the post-save audit checks that answer. Clearing or correcting
the answer releases the choice-filter block. Off-page/extended conditions remain
advisory even if `hard` was authored. Audits and scans detect saved hidden choices.

### Complete region/site example

These are the six region lists from the worked example. Region 3 deliberately uses
`8...` site codes, region 4 uses `6BAR`, region 5 uses `4...`, and region 6 uses `7...`:
the rule follows explicit codes, not a prefix inferred from the region number.
Paste this clean block once into `site`'s Field Annotation:

```text
@UVCHOICES={"when":"[region]='1'","show":["1BAM","1CME","1COT","1CPS","1CYA","1DAR","1DOG","1KGA","1KRG","1MAA","1MOO","1MRA","1NMG","1SBL","1TKB","1ZIL","1DJI","1FDR","1KAT","1DOU","1CMK","1SAL","1CML","1DJN","1DOM","1EJM","1GUI","1HDT"],"blockSave":"hard"}
@UVCHOICES={"when":"[region]='2'","show":["2ADI","2ARI","2BOA","2FOB","2GAL","2GOU","2JOL","2JSG","2KOE","2KOT","2LND","2NGU","2PRE","2RDE","2TRE","2LIB","2CHI","2TAK","2ORD"],"blockSave":"hard"}
@UVCHOICES={"when":"[region]='3'","show":["8BMD","8CBG","8LMA","8NDE","8SBG","8DAG","8SON","8BNK","8MBE","8CGT"],"blockSave":"hard"}
@UVCHOICES={"when":"[region]='4'","show":["6BAR"],"blockSave":"hard"}
@UVCHOICES={"when":"[region]='5'","show":["4BDA","4FUN"],"blockSave":"hard"}
@UVCHOICES={"when":"[region]='6'","show":["7SAB","7HBO","7KET","7KTZ","7NGA","7HML"],"blockSave":"hard"}
```

Keep one complete JSON object per tag. A duplicated `@UVCHOICES` pasted inside a
quoted site code is invalid JSON; repeating the same region condition as a separate
tag can also create a branch configuration error. Save the dictionary change and
reload the data-entry/survey page before testing. The Online Designer defines the
rule; test its live filtering on the actual data-entry form or survey.

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

The confirmation ignores letter case, so `tb-000123` confirms `TB-000123`. If the two
entries must match character for character, add `"caseSensitive":true` to the
`@UVASSERT`.

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

| Supported                                                                                        | Rejected when the rule is saved                      |
| ------------------------------------------------------------------------------------------------ | ---------------------------------------------------- |
| `[field]` and `[checkbox(code)]` references                                                  | Arbitrary functions (`datediff(...)`, etc.)        |
| Text/number literals, comparisons and`and` / `or` / `not`                                  | Arithmetic and piping                                |
| With extended references enabled:`[event][field]`, instance selectors and `{alias}` bindings | Unsupported smart variables such as`[record-name]` |

The semantics below describe **plain legacy references**. For opt-in qualified
references and bindings, use [the event/repeat examples](#validation-across-events-and-repeating-instruments).

A bare `[field]` with no comparison is an error — write `[field]<>''`.

Semantics:

- **Comparisons are numeric when both sides look numeric** (`[age]>'9'` with age `10` is
  true, not a lexicographic accident), and text when neither side does. A `when`
  condition and an `@UVASSERT` test both fold A-Z to lower case first unless the rule
  sets `"caseSensitive":true` (see [Letter case](#letter-case)).
- **Mixed domains do not order.** One numeric side and one non-numeric side, both filled
  in: `=` and `<>` still answer by string identity, but `<` `>` `<=` `>=` are false
  whichever way round you ask them. Ordering across domains produced cycles — with
  `'2'`, `'10'` and `'1e1'` every one of `a<=b`, `b<=c` and `a>c` was true — and a rule
  built on that is unsatisfiable in a way no message can explain.
- **An empty side has no answer, and the role decides what that means.** Empty is
  absence, not a rival domain, so there is nothing to order it against. In an
  `@UVASSERT` test a value nobody has entered cannot violate a constraint, so the
  comparison passes — `[end_date]>=[start_date]` with `start_date` not yet entered
  still passes, and so do `[start_date]<=[end_date]` and `not([end_date]<[start_date])`,
  which ask the same question. In a `when` gate or a branch selector it means the
  module cannot tell whether the rule applies, so the rule stays inert:
  `when:"[age]<=18"` does not fire on a record whose age is blank. Before 1.11.0 the
  answer came from byte order instead, where `''` sorts lowest — so `>=` recipes
  passed and `<=` recipes reported a violation on a field nobody had filled in.
  `=` and `<>` never consulted it and still do not: `[field]<>''` is the way to ask
  whether a field has been filled in.
- A missing or empty field reads as `''`. A checkbox reference `[f(code)]` reads `'1'`
  when checked, `'0'` otherwise.
- **Fields on the same instrument react live.** A calc field updates without DOM events,
  so a calc reference refreshes at the next event on any watched field.
- **Fields on other instruments.** Such a field cannot change while this page is open, so
  the server resolves it against the record's saved values. What happens next depends on
  the shape of the comparison:

  - Compared against a **literal** (`[baseline_eligible]='1'`) there is no live side at
    all, so the whole comparison is settled on the server and sent as a `true`/`false`.
    Correct as of page load, and nothing on this page can change it — which is exactly
    why it never blocks a save (1.6.0). Someone editing that other form in another tab
    makes the constant stale, and a stale `true` would silently accept an invalid save
    while a stale `false` would block a valid one with no way out. The rule stays
    advisory and names the fields it was read from, so you can reload if they have
    changed since.
  - Compared against a field **on this instrument** (`[end_date]>=[start_date]`) the
    comparison is kept **live** since 1.6.0: the off-page value is baked in as a literal
    and the browser re-checks on every keystroke, exactly like a same-instrument rule.
    This only happens when you are entitled to read that instrument — authenticated data
    entry, with REDCap rights to it. Otherwise the value is withheld and the rule is
    **deferred**: no verdict, never blocks, and **the save is accepted**. The audit runs
    after the write and logs it; the Validation scan finds it later. Detection, not
    prevention.

  Either way the page carries field names, your literals and booleans — plus, for the
  live case, values you already have the right to read. A survey respondent, or a user
  without rights to that instrument, never receives one. A brand-new record has no saved
  values, so such references resolve as `''`.
- **A reference the module cannot resolve is refused, not treated as blank (1.6.0).**
  Before 1.6.0 an unreadable reference was indistinguishable from a genuine blank, so a
  rule could be checked against a `''` that had never been read, in the browser and in
  the audit alike. Three cases are now detected positively:

  - The field is on a **different repeating instrument**. Instance 3 of form A has no
    defined pairing with any instance of form B — REDCap itself needs
    an explicit address or pairing to cross that boundary — a plain reference
    refuses rather than picking an instance for you. Enable extended references
    and use a selector or shared-key binding to express the pairing.
  - The field is **not collected in this event**, and the project's instrument-event
    mapping says so. Where that mapping cannot be read (a classic project, or a REDCap
    build that does not expose it) the reference still reads as empty, so keep both
    fields in one event either way.
  - The **read failed** — a `getData` error, or a malformed result. The value is not
    taken as blank.

  What still resolves normally: a repeating instrument reading a non-repeating one, the
  event's base row, two fields inside the **same** repeating instrument, and repeating
  **events** (every form in the event shares the instance).

  A refused reference makes the whole rule **deferred** — sibling terms of an `and`/`or`
  still fold on their own merits, but no verdict is shown for the rule and nothing is
  blocked. The reason names the field and the fix. For `@UVASSERT` the server reports it
  as well: a `uvalidate-unconfigurable` module-log entry after a save (all three cases),
  and a *Rule problems* line on the Validation scan page (the cross-repeating-instrument
  case — the scan reads a whole record at once, so the other two cannot arise there).
- **A `when` gate on another instrument makes the rule advisory too.** Stale applicability
  is as wrong as a stale verdict: the gate decides whether the rule applies at all, and it
  was read once when the page opened. The rule still gives live feedback; it does not
  block. Same for a branched rule whose branch selector lives off-page — the branch that
  gets chosen rests on page-load truth, so no branch of that rule blocks.
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

### Letter case

Text in a condition is compared without regard to the case of A-Z. All of these
match `Yes`, `yes` and `YES`:

```text
@UVREQUIRED="[consent]='yes'"
@UVALIDATE={"algorithm":"verhoeff","when":"[specimen_type]='sputum'"}
@UVASSERT={"assert":"[answer]='yes' or [answer]='no'","message":"Answer yes or no"}
```

Set `"caseSensitive":true` when case is part of the value:

```text
@UVASSERT={"assert":"[lot_code]=[lot_code_confirm]","caseSensitive":true,"message":"Lot codes must match exactly"}
@UVREQUIRED={"when":"[grade]='A'","caseSensitive":true}
```

- Every tag with a `when` accepts it: `@UVALIDATE`, `@UVASSERT`, `@UVREQUIRED`,
  `@UVUNIQUE`, `@UVCHOICES`. In the Configure dialog it is the "compare text
  case-sensitively" checkbox on the rule.
- It covers **every condition of that one rule**: its `when`, its `assert`, and, when
  several tags on a field branch, that branch's selector. Each branch uses its own
  tag's flag.
- The value must be an unquoted `true` or `false`; `"true"` or `1` is a configuration
  error. `false` is the default and changes nothing.
- Only A-Z are folded. Accented and other non-ASCII letters keep their case, because
  the browser and the server lowercase those differently and must reach the same verdict.
- Numbers are unaffected (`'2.50'` still equals `'2.5'`), and so is ordering between
  numbers and text.
- Branches that differ only in case, such as `[site]='a'` and `[site]='A'`, are both
  true at once under the default and are reported as a branch conflict.
- It does not change what `@UVUNIQUE` counts as a duplicate; that comparison is exact.

---

## Validation across events and repeating instruments

Enable **Enable event and instance references** in module settings before using these
examples. Replace the sample event names with your project's **unique event names**,
and designate each source instrument for the target event. Extended syntax is
inactive/unconfigured when the feature is off; existing plain-field rules retain
legacy behavior. See [the reference guide](EVENT-INSTANCE-REFERENCES.md) for selectors,
permissions, exact arithmetic, limits, audit/scan behavior and rollback.

All extended browser checks are **advisory**. Current-page values can stay live;
other events/instances are snapshots. A missing row, unavailable metadata or an
ambiguous match is unresolved, not a saved blank. An unresolved branch does not
activate its fallback. Save/reload to refresh snapshots, and run a scan to reconcile
imports, deleted instances or writes that do not invoke a save hook.

### Named events and relative events

```text
# On follow-up weight; baseline weight is on a non-repeating instrument.
@UVASSERT={"assert":"[weight]>=[baseline_arm_1][weight]","message":"Weight is below baseline; review the measurement"}

# Require a comment when the previous designated event reported an adverse event.
@UVREQUIRED={"when":"[previous-event-name][adverse_event]='1'","message":"Document follow-up of the prior adverse event"}

# Format rule gated by consent recorded at baseline.
@UVALIDATE={"algorithm":"none","pattern":"SK[1-5]-[0-9]{4}[0-9A-Z]","when":"[baseline_arm_1][consent]='1'"}

# A choices branch can also use a saved region in another event.
@UVCHOICES={"when":"[baseline_arm_1][region]='1'","show":["1BAM","1CME"]}
```

All five event selectors, one line each:

```text
# event-name: the event the form is open in (same as writing the plain [field])
@UVASSERT={"assert":"[event-name][weight]>0"}

# previous-event-name / next-event-name: the neighbouring designated event in this arm
@UVASSERT={"assert":"[visit_date]>=[previous-event-name][visit_date]","message":"Visit is dated before the previous visit"}
@UVASSERT={"assert":"[visit_date]<=[next-event-name][visit_date]","message":"Visit is dated after the next visit"}

# first-event-name / last-event-name: the first and last designated event in this arm
@UVASSERT={"assert":"[weight]>=[first-event-name][weight]"}
@UVREQUIRED={"when":"[last-event-name][study_complete]='1'","message":"Close-out comment needed once the final visit is complete"}

# a checkbox option in another event
@UVREQUIRED={"when":"[baseline_arm_1][symptoms(3)]='1'","message":"Follow up the fever reported at baseline"}
```

Relative events use the nearest designated event for the referenced instrument in
the current arm. An explicit unique event name may address another arm in the same
record. A repeating target also needs an instance selector or a matching binding.

### A particular, previous or last repeating instance

```text
# On a repeating measurements instrument: compare to its first saved instance.
@UVASSERT={"assert":"[weight]>=[weight][first-instance]"}

# Compare to instance number 2 at a named follow-up event.
@UVASSERT={"assert":"[weight]>=[followup_arm_1][weight][2]"}

# Require an explanation after a positive result in the preceding numbered instance.
@UVREQUIRED={"when":"[result][previous-instance]='1'","message":"Explain the follow-up of the preceding result"}

# Read the last existing instance of an independent repeating lab instrument.
@UVASSERT={"assert":"[dose]<=[followup_arm_1][maximum_dose][last-instance]"}
```

The remaining instance selectors, and the same selectors written as a binding's
`instance` key (use a binding when the expression would otherwise be hard to read, or
when the value also needs a `type`):

```text
# current-instance: this entry, stated explicitly
@UVASSERT={"assert":"[weight][current-instance]>0"}

# next-instance: the current number plus one
@UVASSERT={"assert":"[visit_date]<=[visit_date][next-instance]","message":"This entry is dated after the following one"}

# instance as a binding key: a number, or any relative selector
@UVASSERT={"assert":"[weight]>={first_weight}","references":{"first_weight":{"field":"weight","instance":"first-instance"}}}
@UVASSERT={"assert":"[weight]>={second}","references":{"second":{"field":"weight","event":"followup_arm_1","instance":2}}}
@UVASSERT={"assert":"[dose]<={latest_max}","references":{"latest_max":{"field":"maximum_dose","event":"followup_arm_1","instance":"last-instance"}}}
@UVREQUIRED={"when":"{prior}='1'","references":{"prior":{"field":"result","instance":"previous-instance"}},"message":"Explain the follow-up of the preceding result"}
```

`previous-instance` means current number minus one. From instance 3 it means 2,
not 1 when 2 was deleted. Instance 1 has no previous instance, so that reference is
unresolved. `first-instance`/`last-instance` use existing minimum/maximum numbers.
Instance selectors are invalid on non-repeating targets. Do not assume instance 2
on two independent repeating instruments refers to the same specimen.

### Match independent repeats by a shared specimen key

Here `result_specimen_id` belongs to the current results instrument; `specimen_id`
and `threshold` belong to the repeating collection instrument.

```text
@UVASSERT={"assert":"[result]>={matched_threshold}","references":{"matched_threshold":{"field":"threshold","event":"collection_arm_1","match":{"specimen_id":"[result_specimen_id]"}}},"message":"Result is below the matched specimen threshold"}
```

Matching preserves case and leading zeros. Zero matches, multiple matches and blank
keys are unresolved for a scalar lookup. Add further key fields inside `match` for
a composite key. Changing the current key defers the binding until save/reload.
Use a matching binding with `aggregate:"count"` or `aggregate:"exists"` when the
rule intentionally tests for absence instead of reading one matched value.

### Collections, totals and existence

```text
# At least one repeat has a positive result.
@UVASSERT={"assert":"[result][any-instance]='1'"}

# Every existing repeat is negative (an empty collection is unresolved).
@UVASSERT={"assert":"[result][all-instances]='0'"}

# Sum doses across explicitly selected events; compare against the current limit.
@UVASSERT={"assert":"{total_dose}<=[dose_limit]","references":{"total_dose":{"field":"dose","events":["baseline_arm_1","followup_arm_1"],"aggregate":"sum"}}}

# Require a note when no collection row matches this specimen.
@UVREQUIRED={"when":"{matching_rows}=0","references":{"matching_rows":{"field":"specimen_id","event":"collection_arm_1","match":{"specimen_id":"[result_specimen_id]"},"aggregate":"count"}}}

# Compare against the average of other measurements in this repeat bucket.
@UVASSERT={"assert":"[weight]<={other_mean}","references":{"other_mean":{"field":"weight","aggregate":"average","excludeCurrent":true}}}
```

Every `aggregate` value, one example each. The collection is the bound field across
the repeat entries (and events) the binding selects.

```text
# count: rows that exist, saved blanks included
@UVASSERT={"assert":"{visits}<=12","references":{"visits":{"field":"visit_date","aggregate":"count"}},"message":"More than 12 visit entries"}

# exists: 1 when at least one row exists, otherwise 0
@UVREQUIRED={"when":"{any_lab}=1","references":{"any_lab":{"field":"lab_date","event":"followup_arm_1","aggregate":"exists"}},"message":"Summarise the lab results"}

# populated-count: rows where the field is not blank
@UVASSERT={"assert":"{answered}>=2","references":{"answered":{"field":"bp_systolic","aggregate":"populated-count"}},"message":"Two blood pressure readings are needed"}

# distinct-count: different exact values (case and leading zeros count)
@UVASSERT={"assert":"{drugs}<=4","references":{"drugs":{"field":"drug_code","aggregate":"distinct-count"}},"message":"More than four different drugs recorded"}

# sum
@UVASSERT={"assert":"{total}<=[dose_limit]","references":{"total":{"field":"dose","aggregate":"sum"}}}

# minimum and maximum
@UVASSERT={"assert":"[weight]>={lowest}","references":{"lowest":{"field":"weight","aggregate":"minimum","excludeCurrent":true}}}
@UVASSERT={"assert":"[weight]<={highest}","references":{"highest":{"field":"weight","aggregate":"maximum","excludeCurrent":true}}}

# average (compared exactly, never rounded)
@UVASSERT={"assert":"[weight]<={mean}","references":{"mean":{"field":"weight","aggregate":"average"}}}

# any / all as a binding: the same test as [field][any-instance] and [field][all-instances],
# with room for events, arm and excludeCurrent
@UVASSERT={"assert":"{some_positive}='1'","references":{"some_positive":{"field":"result","events":["baseline_arm_1","followup_arm_1"],"aggregate":"any"}}}
@UVASSERT={"assert":"{others}='0'","references":{"others":{"field":"result","aggregate":"all","excludeCurrent":true}},"message":"Another entry is not negative"}
```

Choosing where the collection comes from:

```text
# event: one named event
@UVASSERT={"assert":"{n}>=1","references":{"n":{"field":"specimen_id","event":"collection_arm_1","aggregate":"count"}}}

# events: a list of named events
@UVASSERT={"assert":"{n}>=1","references":{"n":{"field":"specimen_id","events":["baseline_arm_1","followup_arm_1"],"aggregate":"count"}}}

# events "arm" + arm: every event of that arm that collects the instrument
@UVASSERT={"assert":"{total}<=[dose_limit]","references":{"total":{"field":"dose","events":"arm","arm":1,"aggregate":"sum"}}}

# instance "any-instance" / "all-instances" as a binding key
@UVASSERT={"assert":"{r}='1'","references":{"r":{"field":"result","event":"followup_arm_1","instance":"any-instance"}}}
@UVASSERT={"assert":"{r}='0'","references":{"r":{"field":"result","event":"followup_arm_1","instance":"all-instances"}}}
```

A binding takes `event` or `events`, never both. `events:"arm"` needs `arm`, and the
arm must contain an event that collects the instrument. Up to 20 bindings per rule;
alias names are lowercase letters, digits and `_`, starting with a letter.

Only one collection operand is allowed per comparison. Counts on an empty
collection are zero; other empty aggregates are unresolved. Numeric aggregates
ignore saved blanks and reject populated nonnumbers. Current members are included
once unless `excludeCurrent:true`; average comparisons do not round decimals.

### Typed dates and elapsed time

Use typed bindings for alternate display formats and calendar checks. Plain legacy
date strings do not acquire date semantics just because this feature is enabled.

```text
# Both fields use REDCap date validation; baseline consent is non-repeating.
@UVASSERT={"assert":"{visit}>={consent}","references":{"visit":{"field":"visit_date","type":"date"},"consent":{"field":"consent_date","event":"baseline_arm_1","type":"date"}}}

# The result must occur between 0 and 48 hours after the first collection.
@UVASSERT={"assert":"{hours}>=0 and {hours}<=48","references":{"hours":{"field":"result_time","type":"datetime","elapsedFrom":"[collection_arm_1][collection_time][first-instance]","unit":"hours"}}}
```

Each `type`, and each `unit`:

```text
# type "date" with unit "days": at most 30 calendar days after consent
@UVASSERT={"assert":"{d}>=0 and {d}<=30","references":{"d":{"field":"visit_date","type":"date","elapsedFrom":"[baseline_arm_1][consent_date]","unit":"days"}},"message":"Visit is outside the 30-day window"}

# type "datetime" (Y-M-D H:M) with unit "minutes"
@UVASSERT={"assert":"{m}<=90","references":{"m":{"field":"processed_at","type":"datetime","elapsedFrom":"[collected_at]","unit":"minutes"}},"message":"Processed more than 90 minutes after collection"}

# type "datetime_seconds" (Y-M-D H:M:S) with unit "seconds"
@UVASSERT={"assert":"{s}>=0","references":{"s":{"field":"stop_time","type":"datetime_seconds","elapsedFrom":"[start_time]","unit":"seconds"}},"message":"Stop time is before start time"}

# two typed scalars compared directly, no elapsed time
@UVASSERT={"assert":"{stop}>={start}","references":{"stop":{"field":"stop_time","type":"datetime_seconds"},"start":{"field":"start_time","type":"datetime_seconds"}}}
```

`unit` needs `elapsedFrom`, and `elapsedFrom` needs both `type` and `unit`.
`elapsedFrom` is one scalar reference: `any-instance` and `all-instances` are refused
there, and a typed binding cannot carry an `aggregate`. A date not entered yet is a
saved blank: an ordered test against it is not a violation and does not activate a
`when`. An impossible date, or a missing row, is unresolved.

Elapsed time is signed. Date-only elapsed checks use calendar days; datetime checks
use timezone-less wall-clock values. Invalid/missing dates are unresolved. Match
date with date and datetime with datetime; no generic `datediff()` is implemented.

### Uniqueness within one record

```text
# On specimen_id: no duplicate on this instrument across its events and instances.
@UVUNIQUE=record

# Alternative: specimen_id may recur for a different specimen_type.
@UVUNIQUE={"scope":"record","with":["specimen_type"]}
```

With every option a record-scope rule accepts:

```text
@UVUNIQUE={"scope":"record","with":["specimen_type"],"when":"[specimen_collected]='1'","message":"This specimen is already entered for this participant","blockSave":"hard","caseSensitive":true}
```

`caseSensitive` here governs the `when` text only. The duplicate comparison itself
always distinguishes case and leading zeros.

Choose one of these alternatives. Record scope excludes only the exact current
entry. It differs from `scope:"event"`, which checks other records within the event;
project/DAG/event scopes retain their existing cross-record behavior.

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

The plain-reference examples below use the **same event**. For other events or
independent repeats, use the opt-in [qualified references and matching bindings](#validation-across-events-and-repeating-instruments).

**1. Off-instrument field compared against a literal** — no live side, so the server
settles it and sends a `true`/`false`:

```text
# on: enrollment_id — baseline_eligible lives on the screening form
@UVASSERT={"assert":"[baseline_eligible]='1'","message":"This participant was not marked eligible at screening"}
```

- Correct as of page load; nothing on this page can change it, so it does not react live
  (and does not need to). The screening value never reaches the browser.
- On a **brand-new record** there are no saved values, so the reference resolves to `''`
  and the assertion is false — on a form where the host field is filled, every new record
  shows the message. Gate it (`{"when":"[record_status]<>''"}`) if that is not what you
  want.

**2. Off-instrument field compared against a field on THIS form** — checked **live**
since 1.6.0:

```text
# on: dx_specimen_date (diagnosis form) — scr_screen_date lives on screening
@UVASSERT={"assert":"[dx_specimen_date]>=[scr_screen_date]","message":"Specimen cannot be collected before the screening date","blockSave":"hard"}
```

- The screening date is resolved on the server and baked into the condition, so the check
  re-runs as the current value changes. It remains advisory because the other form
  is a page-load snapshot; even an authored `blockSave:"hard"` does not block here.
- A failure says which field it was compared against and that the value was read when the
  page opened, so you can tell a stale snapshot from a real violation and reload.
- This requires you to be entitled to read the screening form: authenticated data entry,
  with REDCap rights to that instrument. On a **survey**, or without those rights, the
  value is withheld and the rule is **deferred** — the browser states no verdict, nothing
  is blocked, and **the save goes through**. The post-save audit logs it afterwards and
  the Validation scan lists it on demand; neither can stop the write.
- **One unresolvable reference defers the whole rule**, not just that term. A condition
  spanning two other forms where you can read only one produces no verdict at all — not a
  partial one, and not a warning.
- If the screening form **repeats independently** of the diagnosis form, this rule is
  refused as a configuration problem (there is no defined pairing between their
  instances) — see [the condition language](#the-when-condition-language). Put the two
  fields on the same instrument, or enable extended references and provide an
  explicit instance selector or shared-key binding.

`and` / `or` / `not` combine as many of these as you like — a single rule may span several
instruments:

```text
# on: tx_start_date (treatment) — dx_* on diagnosis, scr_* on screening
@UVASSERT={"assert":"[tx_start_date]>=[dx_result_date] and [tx_daily_dose_mg]<=[scr_max_daily_dose]","message":"Check the start date and dose against baseline","blockSave":"hard"}
```

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

| Attempt                                                    | Result                                                            |
| ---------------------------------------------------------- | ----------------------------------------------------------------- |
| Two`@UVALIDATE` tags, neither with a `when`            | Configuration error — two when-less rules of one kind            |
| Two`@UVASSERT` tags with byte-identical `when`         | Configuration error — they could never be told apart             |
| A`single` and a `pooled` `@UVALIDATE` on one field   | Configuration error — mixed types in one kind                    |
| Two`@UVREQUIRED` tags whose conditions are true at once  | Runtime conflict — a notice, validates nothing, never blocks     |
| `@UVREQUIRED` or `@UVUNIQUE` on a **calc** field | Configuration error — a data enterer cannot fix a calc           |
| `@UVALIDATE` on a dropdown/radio/slider                  | Configuration error — check/format rules are Text and Notes only |

---

## Tags the module refuses

Each line below produces a visible configuration error on the field. They are here so
you can recognise the mistake; the fix is on the right.

```text invalid
@UVALIDATE=none                                                  format-only needs the JSON form with a pattern
@UVALIDATE={"algorithm":"sha256"}                                unknown algorithm
@UVALIDATE={"algoritm":"damm"}                                   unknown key (typo)
@UVALIDATE={"source":"letters_only"}                             source is normalized_id, digits_only or sequence_only
@UVALIDATE={"blockSave":"block"}                                 blockSave is off, confirm or hard
@UVALIDATE={"algorithm":"none","pattern":"(A+)+"}                pattern can backtrack catastrophically
@UVALIDATE={"type":"pooled","idMinLen":5,"idMaxLen":10}          idMaxLen must be less than 2x idMinLen
@UVALIDATE={"type":"pooled","idLengths":[4,5,9]}                 9 = 4 + 5, so one member could be two
@UVALIDATE={"suggestFix":"true"}                                 true/false must not be quoted
@UVASSERT={"message":"no condition"}                             assert is required
@UVASSERT="[a]>=[b"                                              unbalanced bracket
@UVASSERT="weight > 0"                                           field references are written [weight]
@UVUNIQUE=site                                                   scope is project, dag, event or record
@UVUNIQUE={"with":["a","b","c","d","e","f"]}                     at most 5 composite fields
@UVCHOICES={"show":["1"],"hide":["2"]}                           show or hide, not both
@UVCHOICES={"when":"[x]='1'"}                                    one of show/hide is required
@UVASSERT={"assert":"{t}>0","references":{"t":{"field":"dose","aggregate":"median"}}}                                unknown aggregate
@UVASSERT={"assert":"{t}>0","references":{"t":{"field":"dose","event":"a_arm_1","events":["b_arm_1"],"aggregate":"sum"}}}    event and events together
@UVASSERT={"assert":"{t}>0","references":{"t":{"field":"dose","events":"arm","aggregate":"sum"}}}                   events "arm" needs arm
@UVASSERT={"assert":"{t}>0","references":{"t":{"field":"d","type":"date","elapsedFrom":"[d0]"}}}                    elapsedFrom needs unit
@UVASSERT={"assert":"{t}>0","references":{"t":{"field":"d","type":"date","elapsedFrom":"[d0][any-instance]","unit":"days"}}}   elapsedFrom is one scalar reference
@UVASSERT={"assert":"{t}>0","references":{"t":{"field":"d","type":"date","aggregate":"minimum"}}}                   typed dates cannot be aggregated
@UVASSERT={"assert":"{t}>0","references":{"t":{"field":"x","instance":2,"match":{"k":"[k]"}}}}                      match and instance together
@UVASSERT={"assert":"{missing}>0","references":{"t":{"field":"x"}}}                                                  {missing} is not defined
@UVASSERT={"assert":"[a][any-instance]=[b][any-instance]"}                                                           one collection operand per comparison
```

---

## The Configure dialog, setting by setting

Everything a tag can say, the module's **Configure** dialog can say too. Use the
dialog when one rule covers many fields, when you want a rule that is not tied to the
data dictionary, or when a designer should not edit annotations. `@UVCHOICES` is the
one mode that exists only as a tag.

**Step 1 — open it.** Control Center or the project's *External Modules* page →
**Universal Field Validator** → **Configure**.

**Step 2 — project-wide settings** (top of the dialog):

| Setting | What to choose |
| --- | --- |
| Enable event and instance references | Tick to allow `[event][field][instance]`, `references` and `@UVUNIQUE=record`. Off by default; while off, such rules show as unconfigured and nothing else changes. |
| Maximum extended audit contexts per save | How many host entries one save may re-check (default 500). Beyond it the audit logs a notice and a Validation scan finishes the work. |
| How to log invalid values | `hashed` (keyed hash of value and record id), `none` (location only), `raw` (the value itself), `off` (no audit log). |
| Validation scan — use the durable scan | Tick for the resumable, batch scan. Needs the installation-wide switch as well. |
| Scan report: value beside each finding | `locations` (default), `identifier-redacted`, or `raw`. |
| Days to keep a stored value preview / a finished scan | Blank uses the server default; you may choose fewer days, never more. |
| Most findings / most bytes a scan retains | Blank uses the server default. A run that reaches the limit keeps counting and labels itself truncated. |
| Debug: include exception messages | Leave off in production; exception text can quote data. |

**Step 3 — add a rule.** Press **+** beside *Validation rule*, then fill the rule from
top to bottom. The right-hand column is the tag key that means the same thing.

| Dialog setting | Applies to | Tag equivalent |
| --- | --- | --- |
| Rule label | all | `note` |
| What this rule checks: Single value / Pooled / Constraint / Required / Unique | all | `type` `single` or `pooled`; or the tag `@UVASSERT`, `@UVREQUIRED`, `@UVUNIQUE` |
| Field(s) this rule validates (+ adds another) | all | the fields carrying the tag |
| Fast entry: more field names, comma or space separated | all | the same, typed |
| Extended reference bindings (JSON object) | all | `references` |
| Only validate when | all | `when` |
| Compare text case-sensitively | all | `caseSensitive` |
| Constraint condition | Constraint | `assert` |
| Message | Constraint, Required, Unique | `message` |
| Composite-key fields | Unique | `with` |
| Where the value must be unique | Unique | `scope`: `project`, `dag`, `event`, `record` |
| Also check live on survey pages | Unique | `surveys` |
| Check-character method | Single, Pooled | `algorithm` |
| What the check runs over | Single, Pooled | `source` |
| Suggest the correct final check character | Single, Pooled | `suggestFix` |
| Format pattern | Single, Pooled | `pattern` |
| Several ID formats in one field (JSON list) | Single, Pooled | `alternates` |
| Separators to ignore | Single, Pooled | `strip` |
| Extra characters to keep | Pooled | `keepChars` |
| Exact ID length(s) | Pooled | `idLengths` |
| Minimum / maximum ID length | Pooled | `idMinLen`, `idMaxLen` |
| Expected number of IDs | Pooled | `expectedIds` |
| On an invalid value: Informational / Advisory / Compulsory | all | `blockSave` `off`, `confirm`, `hard` |

The internal rule id is assigned by the module. Never edit it: scan findings and the
audit log use it to follow a rule when rules are reordered.

**Step 4 — worked example.** The tag

```text
@UVASSERT={"assert":"[weight]<={mean}","references":{"mean":{"field":"weight","aggregate":"average","excludeCurrent":true}},"when":"[visit_type]='routine'","message":"Weight is above this participant's average","blockSave":"confirm"}
```

is entered in the dialog as:

| Setting | Value |
| --- | --- |
| What this rule checks | Constraint |
| Field(s) | `weight` |
| Extended reference bindings | `{"mean":{"field":"weight","aggregate":"average","excludeCurrent":true}}` |
| Only validate when | `[visit_type]='routine'` |
| Constraint condition | `[weight]<={mean}` |
| Message | `Weight is above this participant's average` |
| On an invalid value | Advisory |

Note that the bindings box takes the inner object only, without the `"references":` key.

**Step 5 — save and check.** Save the dialog, open a record, and look under the
field. A configuration problem is reported there in words. Dialog rules and tags can
target the same field: different kinds compose, and the same kind branches by `when`.

**Installation-wide settings** (Control Center only; they cap what a project may choose):

| Setting | Default | Meaning |
| --- | --- | --- |
| Enable the durable scan on this installation | off | Master switch; the scan creates its own tables when turned on. |
| Maximum days a stored value preview may be kept | 30 | Projects may choose fewer. |
| Maximum days a finished scan is kept | 90 | Projects may choose fewer. |
| Most findings any one run may retain | 100000 | Beyond it a run counts without storing detail and labels itself truncated. |
| Most bytes of finding detail per run | 536870912 (512 MiB) | Either limit truncates the run. |
| Projects scanned at the same time | 2 | Rations the server; a project cannot raise it. |
| Hours before a stalled run is treated as abandoned | 24 | Its lease can then be taken over. |
| Retries for a record that cannot be read | 3 | After that the run records it as unread and reports itself incomplete. |

---

## Parameter reference tables

### `@UVALIDATE`

| Key               | Type           | Default              | Scope  | Notes                                                 |
| ----------------- | -------------- | -------------------- | ------ | ----------------------------------------------------- |
| `type`          | string         | `single`           | all    | `single` or `pooled`                              |
| `algorithm`     | string         | `iso7064_mod37_36` | all    | See the algorithm list; shorthands accepted           |
| `source`        | string         | `normalized_id`    | all    | `normalized_id`, `digits_only`, `sequence_only` |
| `pattern`       | string         | *(none)*           | all    | JS regex, auto-anchored, uppercase, printable ASCII   |
| `strip`         | string         | `-/ _\|\`           | all    | Separator characters ignored before checking          |
| `keepChars`     | string         | *(none)*           | pooled | Extra characters kept when splitting; length-capped   |
| `idLengths`     | list or string | *(none)*           | pooled | `[9]`, `[10,12]`, `"10, 12"`                    |
| `idMinLen`      | integer        | `8`                | pooled | Positive whole number                                 |
| `idMaxLen`      | integer        | `14`               | pooled | Must be**< 2×`idMinLen`**                          |
| `expectedIds`   | integer        | *(none)*           | pooled | Expected number of IDs in the field                   |
| `blockSave`     | string         | `off`              | all    | `off`, `confirm`, `hard`                        |
| `when`          | string         | *(none)*           | all    | Condition; the rule runs only while true              |
| `suggestFix`    | boolean        | `false`            | all    | Opt in to the "should end in X" hint                  |
| `note`          | string         | *(none)*           | all    | Rule label                                            |
| `caseSensitive` | boolean        | `false`            | all    | Exact-case text in`when`                            |

### `@UVASSERT`

| Key               | Type    | Default        | Notes                                                   |
| ----------------- | ------- | -------------- | ------------------------------------------------------- |
| `assert`        | string  | *(required)* | The condition that must hold; a missing one is an error |
| `message`       | string  | generic line   | Your own wording — recommended                         |
| `blockSave`     | string  | `off`        | `off`, `confirm`, `hard`                          |
| `when`          | string  | *(none)*     | Enforce the constraint only while true                  |
| `caseSensitive` | boolean | `false`      | Exact-case text in`assert` and `when`               |

### `@UVREQUIRED`

| Key               | Type    | Default      | Notes                                                   |
| ----------------- | ------- | ------------ | ------------------------------------------------------- |
| `when`          | string  | *(none)*   | Required only while true; the bare short form sets this |
| `message`       | string  | generic line | Your own wording                                        |
| `blockSave`     | string  | `off`      | `off`, `confirm`, `hard`                          |
| `caseSensitive` | boolean | `false`    | Exact-case text in`when`                              |

### `@UVUNIQUE`

| Key               | Type            | Default      | Notes                                                          |
| ----------------- | --------------- | ------------ | -------------------------------------------------------------- |
| `with`          | list of strings | *(none)*   | Composite key fields; max 5, no duplicates, must exist         |
| `scope`         | string          | `project`  | `project`, `dag`, `event`, `record` (record needs event and instance references enabled); the bare short form sets this |
| `surveys`       | boolean         | `false`    | Opt in to the check on surveys; boolean answer only            |
| `when`          | string          | *(none)*   | Check only while true                                          |
| `message`       | string          | generic line | Your own wording                                               |
| `blockSave`     | string          | `off`      | `off`, `confirm`, `hard`                                 |
| `caseSensitive` | boolean         | `false`    | Exact-case text in`when` (not the duplicate check)           |

### `@UVCHOICES`

| Key               | Type            | Default        | Notes                                                                  |
| ----------------- | --------------- | -------------- | ---------------------------------------------------------------------- |
| `show`          | list of strings | *(one of)*   | Offer only these choice codes; up to 200 codes                         |
| `hide`          | list of strings | *(one of)*   | Offer every code except these. Exactly one of `show`/`hide`        |
| `when`          | string          | *(none)*     | The branch applies while true; a tag without `when` is the fallback  |
| `message`       | string          | generic line   | Shown when a saved or selected code is not offered                     |
| `blockSave`     | string          | `off`        | `off`, `confirm`, `hard`                                         |
| `caseSensitive` | boolean         | `false`      | Exact-case text in `when`                                            |

### Keys every tag also accepts

| Key            | Type   | Notes                                                                                  |
| -------------- | ------ | -------------------------------------------------------------------------------------- |
| `references` | object | Named bindings used as `{alias}` in `when`/`assert`; at most 20; feature must be enabled |
| `alternates` | list   | `@UVALIDATE` only: `label`, `pattern`, `algorithm`, `source`, `strip`, `lengths` per entry |

### `references` binding keys

| Key                | Type              | Notes                                                                                         |
| ------------------ | ----------------- | --------------------------------------------------------------------------------------------- |
| `field`          | string            | Required. The field to read                                                                   |
| `event`          | string            | One unique event name. Not with `events`                                                    |
| `events`         | list or `"arm"` | Several unique event names, or `"arm"` together with `arm`                                |
| `arm`            | integer           | Arm number for `events:"arm"`; must contain an event that collects the instrument           |
| `instance`       | integer or string | A number, or `current-`, `previous-`, `next-`, `first-`, `last-instance`, `any-instance`, `all-instances`. Not with `match` |
| `match`          | object            | `{"target_key_field":"[source_field]"}`; several keys mean all must match                   |
| `aggregate`      | string            | `count`, `exists`, `populated-count`, `distinct-count`, `sum`, `minimum`, `maximum`, `average`, `any`, `all` |
| `excludeCurrent` | boolean           | Leave the exact current entry out of the collection                                           |
| `type`           | string            | `date`, `datetime`, `datetime_seconds`. Not with `aggregate`                              |
| `elapsedFrom`    | string            | One scalar reference; needs `type` and `unit`                                             |
| `unit`           | string            | `days`, `hours`, `minutes`, `seconds`; needs `elapsedFrom`                              |

### Reference selectors inside a condition

| Form                              | Meaning                                                        |
| --------------------------------- | -------------------------------------------------------------- |
| `[field]`                       | This entry                                                     |
| `[checkbox(code)]`              | One checkbox option, `'1'` when ticked                       |
| `[event_name][field]`           | A named event (any arm of the same record)                     |
| `[event-name][field]`           | The current event                                              |
| `[previous-event-name][field]`, `[next-event-name][field]` | Neighbouring designated event in the arm |
| `[first-event-name][field]`, `[last-event-name][field]`    | First / last designated event in the arm |
| `[field][3]`                    | Instance number 3                                              |
| `[field][current-instance]`, `[previous-instance]`, `[next-instance]` | Relative to this entry's number  |
| `[field][first-instance]`, `[last-instance]`               | Lowest / highest existing instance       |
| `[field][any-instance]`, `[all-instances]`                 | Collection tests; one per comparison     |
| `[event_name][field][last-instance]`                         | Event and instance together              |
| `{alias}`                       | A binding from `references`                                  |

### Limits

| Limit | Value |
| --- | --- |
| Condition length | 500 characters |
| Field references in one condition | 20 |
| Nesting depth (parentheses and `not`) | 10 |
| Bindings per rule | 20 |
| Members in one collection | 10,000 |
| Digits in exact decimal arithmetic | 4,096 |
| Evaluation budget per record | 100,000 units |
| Codes in one `show`/`hide` list | 200 |
| Composite `with` fields | 5 |
| `keepChars` length | 64 |

### Algorithms

| Algorithm                                | Payload / output                     | Example (payload → check) | Typical use                                     |
| ---------------------------------------- | ------------------------------------ | -------------------------- | ----------------------------------------------- |
| `iso7064_mod37_36` **(default)** | letters+digits, 1 char               | `0ABCD12345` → `K`    | Participant/specimen IDs                        |
| `iso7064_mod11_10`                     | digits, 1 char                       | `079` → `2`           | Strong digit-only check                         |
| `iso7064_mod97_10`                     | digits, 2 chars                      | `1` → `95`            | Longer digit IDs (IBAN scheme)                  |
| `iso7064_mod11_2`                      | digits, 1 char (may be`X`)         | `079` → `X`           | Digit IDs where`X` is acceptable              |
| `iso7064_mod37_2`                      | letters+digits, 1 char (may be`*`) | `1` → `*`             | Alphanumeric, pure Mod 37,2                     |
| `iso7064_letters1`                     | 1 letter                             | `0ABCD12345` → `N`    | Letter-only check                               |
| `iso7064_letters2`                     | 2 letters (A–F)                     | `0ABCD12345` → `DC`   | Letter-only check, two chars                    |
| `damm`                                 | digits, 1 char                       | `572` → `4`           | Catches single-digit and adjacent-swap errors   |
| `verhoeff`                             | digits, 1 char                       | `123456` → `8`        | Strong single-error + transposition coverage    |
| `luhn`                                 | digits, 1 char                       | `7992739871` → `3`    | Compatibility only (weakest)                    |
| `gs1_mod10`                            | digits, 1 char                       | `978030640615` → `7`  | GS1 / GTIN / EAN / UPC barcodes                 |
| `aba_mod10`                            | digits, 1 char                       | `01100001` → `5`      | US bank routing numbers                         |
| `mrz_mod10`                            | digits, 1 char                       | `740812` → `2`        | ICAO 9303 passport MRZ fields                   |
| `weighted_mod11`                       | digits, 1 char (may be`X`)         | `080442957` → `X`     | ISBN-10 style, ≤ 9-digit payloads              |
| `none`                                 | *(format only)*                    | —                         | Codes with a fixed shape and no check character |

### Algorithm shorthands

| Shorthands                                                | Resolves to                                 |
| --------------------------------------------------------- | ------------------------------------------- |
| `3736`, `37,36`, `37_36`, `mod37_36`, `mod3736` | `iso7064_mod37_36`                        |
| `1110`, `11,10`, `11_10`, `mod11_10`, `mod1110` | `iso7064_mod11_10`                        |
| `9710`, `97,10`, `97_10`, `mod97_10`, `mod9710` | `iso7064_mod97_10`                        |
| `112`, `11,2`, `11_2`, `mod11_2`, `mod112`      | `iso7064_mod11_2`                         |
| `372`, `37,2`, `37_2`, `mod37_2`, `mod372`      | `iso7064_mod37_2`                         |
| `letters1`, `letter1` / `letters2`, `letter2`     | `iso7064_letters1` / `iso7064_letters2` |
| `mod10`                                                 | `luhn`                                    |
| `gs1`, `gtin`, `ean`, `upc`                       | `gs1_mod10`                               |
| `aba`, `routing`                                      | `aba_mod10`                               |
| `mrz`, `icao`                                         | `mrz_mod10`                               |
| `isbn`, `mod11w`, `weighted11`                      | `weighted_mod11`                          |
| `regex`, `format`                                     | `none` (pair with a `pattern`)          |

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

# ── Events, repeating instruments, bindings (feature must be enabled) ────────
@UVASSERT={"assert":"[weight]>=[baseline_arm_1][weight]"}
@UVREQUIRED={"when":"[previous-event-name][adverse_event]='1'"}
@UVASSERT={"assert":"[weight]>=[weight][first-instance]"}
@UVASSERT={"assert":"[result][any-instance]='1'"}
@UVASSERT={"assert":"{total}<=[dose_limit]","references":{"total":{"field":"dose","events":"arm","arm":1,"aggregate":"sum"}}}
@UVASSERT={"assert":"[result]>={t}","references":{"t":{"field":"threshold","event":"collection_arm_1","match":{"specimen_id":"[result_specimen_id]"}}}}
@UVASSERT={"assert":"{h}>=0 and {h}<=48","references":{"h":{"field":"result_time","type":"datetime","elapsedFrom":"[collected_at]","unit":"hours"}}}
@UVUNIQUE=record                                      no duplicate inside one record

# ── Letter case ──────────────────────────────────────────────────────────────
@UVASSERT={"assert":"[code]=[code_confirm]","caseSensitive":true}

# ── Composing several kinds on ONE field ─────────────────────────────────────
@UVREQUIRED="[consent]='1'"
@UVALIDATE={"algorithm":"3736","blockSave":"hard"}
@UVUNIQUE={"blockSave":"hard","message":"That participant ID already exists"}

# ── Branching: several tags of the SAME kind ─────────────────────────────────
@UVALIDATE={"algorithm":"verhoeff","when":"[specimen_type]='2'"}
@UVALIDATE={"algorithm":"none","pattern":"FC[0-9]{4}"}   <- the "otherwise" branch
```

---

*Documents Universal Field Validator v2.1.0-rc.1. Every tag on this page is parsed by
`tests/docs_examples_php.php`, so an example that stops matching the module fails the build. For the full training
guide see [`USER_GUIDE.md`](USER_GUIDE.md); for installation see
[`INSTALL.md`](INSTALL.md); for the manual REDCap test checklist see
[`TESTING.md`](TESTING.md); for the product overview see the [README](../README.md).*
