# Action Tag Validation Examples

A worked, parameter-by-parameter guide to the action tags of the **Universal
Field Validator** module (current implementation). Every tag is shown from its
simplest form to its most complete, with the meaning of each option and what the
person entering data will see.

**Where you type these:** the *Action Tags / Field Annotation* box of a field in the
Online Designer, or the `field_annotation` column of a data dictionary CSV. Tagging
50 fields is one spreadsheet column and one upload. Everything here is also available
in the module's Configure dialog, except `@UVCHOICES`, `@UVWINDOW`, `@UVEXISTS` and `@UVRANGE`. The tags and
the dialog are the same rules through different doors, and they mix freely.

---

## Contents

- [The tags at a glance](#the-tags-at-a-glance)
- [Rules that apply to every tag](#rules-that-apply-to-every-tag)
- [`@UVALIDATE` — check characters and format](#uvalidate--check-characters-and-format)
- [`@UVASSERT` — cross-field constraints](#uvassert--cross-field-constraints)
- [`@UVREQUIRED` — conditional required](#uvrequired--conditional-required)
- [`@UVUNIQUE` — no duplicates across records](#uvunique--no-duplicates-across-records)
- [`@UVCHOICES` — dropdowns and autocomplete](#uvchoices--dropdowns-autocomplete-radio-and-checkbox-choices)
- [`@UVWINDOW` — dates within a window](#uvwindow--dates-within-a-window)
- [`@UVEXISTS` — values that must already exist](#uvexists--values-that-must-already-exist)
- [`@UVRANGE` — numbers within plausible limits](#uvrange--numbers-within-plausible-limits)
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
  - [`@UVWINDOW` recipes](#uvwindow-recipes)
  - [`@UVEXISTS` recipes](#uvexists-recipes)
  - [`@UVRANGE` recipes](#uvrange-recipes)
  - [Combination recipes](#combination-recipes)
- [Tags the module refuses](#tags-the-module-refuses)
- [The Configure dialog, setting by setting](#the-configure-dialog-setting-by-setting)
- [Parameter reference tables](#parameter-reference-tables)
- [Copy-paste cheat sheet](#copy-paste-cheat-sheet)

---

## The tags at a glance

| Tag           | What it checks                                                                                                          | Field types it may sit on                                      |
| ------------- | ----------------------------------------------------------------------------------------------------------------------- | -------------------------------------------------------------- |
| `@UVALIDATE`  | The value's check character and/or format (an ID is well-formed)                                                        | Text, Notes                                                    |
| `@UVASSERT`   | A condition across fields holds (end ≥ start, dose ≤ max)                                                               | Text, Notes, dropdown, radio, yes/no, true/false, calc, slider |
| `@UVREQUIRED` | The field is not blank, optionally only while a condition is true                                                       | Same as above, minus calc                                      |
| `@UVUNIQUE`   | The value is not used by another record                                                                                 | Same as above, minus calc                                      |
| `@UVCHOICES`  | Which options are offered — show/hide choices while a condition holds                                                   | radio, dropdown, checkbox (not matrix)                         |
| `@UVWINDOW`   | A date falls within a window around another date, or is not after today                                                 | Text with date, datetime or datetime-with-seconds validation   |
| `@UVEXISTS`   | The value is already saved in the project: a record ID, or a value of another field                                     | Text, dropdown, radio, SQL                                     |
| `@UVRANGE`    | A number lies within usual and plausible limits (two levels of warning), or its z-score against a growth reference does | Text with no, integer or number validation, calc, slider       |

Different tags on one field **compose** — all must pass, and each keeps its own
save-block state. Several tags of the *same* kind on one field **branch** (one wins
by condition). Both are covered below.

---

## Rules that apply to every tag

Learn these once and every tag behaves predictably.

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

**`blockSave` is how strongly you enforce.** Available on every tag except
`@UVRANGE`, which sets each of its two levels with `softBlock` and `hardBlock`:

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

**Text ignores letter case.** `[status]='active'` is true for `Active` and `ACTIVE`,
in every tag's `when`, in branch selectors and in `@UVASSERT`. The values
`@UVEXISTS` looks up and `@UVUNIQUE` compares ignore it too. Add
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
- **`{,n}` and quantifiers with spaces are rejected** (`FC[0-9]{,4}`, `[0-9]{2, 3}`).
  The browser reads them as literal text and PHP 8.4 reads them as counts, so the two
  would disagree. Write `{0,4}` or `{2,3}`, or escape the brace (`\{`) when you mean
  the text.
- **Escaped separators count.** In a pooled rule, a character the pattern spells out
  is kept while the field is cleaned, whether it is written plain or escaped (`\/`, `\:`).

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

`keepChars` lists extra characters to keep while a pooled field is cleaned. Before splitting, a pooled value keeps only `A-Z`, `0-9`, the characters the
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

```text expect
OL00029* NW00042U     =>  valid: OL00029*, NW00042U
OL12345M              =>  valid: OL12345M
OL12345*              =>  refused: check-character
```

`keepChars` takes up to 64 printable ASCII characters. The save check also compares
kept characters in a one-ID `alternates` rule, so such a rule may need `keepChars` too,
even though a one-ID field is never split (see [Recipe 5](#recipe-5-formats-with-different-separators)).

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
With `alternates`, the check runs over the lengths of every entry together; see
[When IDs cannot share one pooled field](#when-ids-cannot-share-one-pooled-field).

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

Use one `alternates` list when the entered ID itself shows which format it is.
Each entry has its own pattern and its own check-character algorithm, and a value is
valid when one complete entry accepts it. When another answer decides the format
(the country, the study arm), write one tag per answer with `when` instead; see
[Recipe 10](#recipe-10-another-answer-picks-the-format-list). Several unconditional
`@UVALIDATE` tags on one field conflict, so never use them as alternatives.

#### How a value is matched to a format

1. **Check-bearing entries come first.** If the value fits the pattern of any entry
   that has a check character, it must pass that check character. A wrong check
   character is reported as a check-character error, and the value is not tried
   against the format-only entries.
2. **Format-only entries are tried only when no check-bearing pattern fits.** When
   several format-only patterns fit, the first one listed is credited.
3. **Order cannot weaken rule 1.** Listing a format-only entry first does not let it
   take a value that a check-bearing pattern fits.
4. **A pooled field applies the same rules to each member**, separately at each
   declared length.

The rule is checked when it is saved. A format-only pattern that can match a value a
check-bearing pattern also matches is refused (in a pooled rule, only at a length both
entries declare). Rule 1 catches a mis-scan that keeps the check-bearing shape. A
mis-scan that breaks the shape, such as an `O` read for a `0` or a dropped character,
would otherwise fall into the overlapping format-only pattern and pass on format
alone. [Rules refused when saved](#rules-refused-when-saved) lists every refusal with
its message.

#### Reading the tested values

Each recipe is followed by a block of tested values. The left side of `=>` is what is
typed or scanned, with `\n` marking a new line. The right side is the verdict:

| Result                       | Meaning                                                                                   |
| ---------------------------- | ----------------------------------------------------------------------------------------- |
| `valid`                      | A one-ID field accepted the value.                                                        |
| `valid: ID, ID, ...`         | A pooled field accepted the value. These are the members it read, in order.               |
| `refused: format`            | One-ID field: no entry's pattern fits.                                                    |
| `refused: check-character`   | The value fits a check-bearing pattern but its check character is wrong (pooled: at least one member). |
| `refused: junk`              | Pooled: characters that belong to no ID were left over.                                   |
| `refused: duplicate`         | Pooled: the same ID appears twice.                                                        |
| `refused: no-id`             | Pooled: no ID could be read at all.                                                       |
| `refused: count`             | Pooled: the number of IDs differs from `expectedIds`.                                     |

CI runs every tested value through the server verdict and through the browser engine,
and fails when either result differs from the one printed here.

#### Recipe 1: one ID per field, several studies

```text
@UVALIDATE={"strip":"-","blockSave":"hard","alternates":[
  {"label":"GHIT","pattern":"FC[1-9]-[0-9]{4}","algorithm":"none"},
  {"label":"START4KIDS","pattern":"SK[1-9]-[0-9]{4}[0-9A-Z]","algorithm":"3736"},
  {"label":"DARE-TB","pattern":"DT[1-9]-[0-9]{5}[0-9A-Z]","algorithm":"3736"},
  {"label":"SCREEN-TB","pattern":"ST[1-9]-[0-9]{5}[0-9A-Z]","algorithm":"3736"}
]}
```

```text expect
FC1-0589      =>  valid
SK2-0019I     =>  valid
DT1-00151N    =>  valid
ST3-00042S    =>  valid
dt1-00151n    =>  valid
SK2-0019A     =>  refused: check-character
DT1-00151     =>  refused: format
FC1-05899     =>  refused: format
XX1-0001      =>  refused: format
```

`GHIT` checks format only. The other three also verify ISO 7064 Mod 37,36, so a value
that fits their pattern with the wrong last character is refused. `strip:"-"` removes
the hyphen before the check character is computed; the pattern still sees it. A
one-ID rule needs no `lengths`.

#### Recipe 2: the same studies, many IDs in one field

```text
@UVALIDATE={"type":"pooled","strip":"-","blockSave":"hard","alternates":[
  {"label":"GHIT","pattern":"FC[1-9]-[0-9]{4}","algorithm":"none","lengths":[8]},
  {"label":"START4KIDS","pattern":"SK[1-9]-[0-9]{4}[0-9A-Z]","algorithm":"3736","lengths":[9]},
  {"label":"DARE-TB","pattern":"DT[1-9]-[0-9]{5}[0-9A-Z]","algorithm":"3736","lengths":[10]},
  {"label":"SCREEN-TB","pattern":"ST[1-9]-[0-9]{5}[0-9A-Z]","algorithm":"3736","lengths":[10]}
]}
```

```text expect
FC1-0589 SK2-0019I DT1-00151N ST3-00042S        =>  valid: FC1-0589, SK2-0019I, DT1-00151N, ST3-00042S
FC1-0589SK2-0019IDT1-00151N                     =>  valid: FC1-0589, SK2-0019I, DT1-00151N
FC1-0589, SK2-0019I; DT1-00151N\nST3-00042S     =>  valid: FC1-0589, SK2-0019I, DT1-00151N, ST3-00042S
FC1-0589 SK2-0019A                              =>  refused: check-character
FC1-0589 SK2-0019I FC1-0589                     =>  refused: duplicate
FC1-0589 SK2-001 DT1-00151N                     =>  refused: junk
FC1-0589-SK2-0019I                              =>  refused: junk
hello                                           =>  refused: no-id, junk
```

Every pooled entry needs `lengths`: the length of its IDs including the characters
its pattern spells out, such as the hyphen. The lengths here are 8, 9, 10 and 10.
IDs may be separated by spaces, commas, semicolons or new lines, or scanned back to
back. A character that any pattern contains (the `-` here) is kept while the field is
cleaned, so typing it between IDs leaves junk.

#### Recipe 3: same length, with and without a check character

A viral-load field takes three kinds of ID: `C`-`Z` codes that carry a check character,
legacy `A`/`B` codes that do not, and retired `VLB-` barcodes. The first two are both
six characters long. They can share that length because no value fits both patterns.

One ID per field:

```text
@UVALIDATE={"strip":"-","blockSave":"hard","alternates":[
  {"label":"Viral load ID (check character)","pattern":"[C-Z][0-9]{4}[0-9A-Z]","algorithm":"3736"},
  {"label":"Legacy viral load ID (A/B)","pattern":"[AB][0-9]{5}","algorithm":"none"},
  {"label":"Retired VLB barcode","pattern":"VLB-[0-9]{5}","algorithm":"none"}
]}
```

```text expect
C12348        =>  valid
Z0007D        =>  valid
A12345        =>  valid
B00001        =>  valid
VLB-00012     =>  valid
VLB–00012     =>  valid
vlb-00012     =>  valid
C1234A        =>  refused: check-character
C12345        =>  refused: check-character
O12345        =>  refused: check-character
VLB00012      =>  refused: format
AB1234        =>  refused: format
```

`O12345` is a legacy ID mis-scanned with the letter `O` for the digit `0`. It fits
the `C`-`Z` pattern, so it must pass that check character, and it does not. The
en dash in `VLB–00012` is read as a hyphen.

Several IDs in one field:

```text
@UVALIDATE={"type":"pooled","strip":"-","blockSave":"hard","alternates":[
  {"label":"Viral load ID (check character)","pattern":"[C-Z][0-9]{4}[0-9A-Z]","algorithm":"3736","lengths":[6]},
  {"label":"Legacy viral load ID (A/B)","pattern":"[AB][0-9]{5}","algorithm":"none","lengths":[6]},
  {"label":"Retired VLB barcode","pattern":"VLB-[0-9]{5}","algorithm":"none","lengths":[9]}
]}
```

```text expect
C12348 A12345 VLB-00012      =>  valid: C12348, A12345, VLB-00012
C12348Z0007D                 =>  valid: C12348, Z0007D
A12345B00001                 =>  valid: A12345, B00001
VLB-00012VLB-00013           =>  valid: VLB-00012, VLB-00013
VLB–00012,C12348             =>  valid: VLB-00012, C12348
C12348;C1234A                =>  refused: check-character
C12348 O12345                =>  refused: check-character
A12345 A12345                =>  refused: duplicate
VLB00012 A12345              =>  refused: junk
C12348-A12345                =>  refused: junk
```

`VLB00012` lost its hyphen, so it fits no pattern and is reported as junk. To accept
VLB codes without the hyphen, write that entry as `"pattern":"VLB-?[0-9]{5}"` with
`"lengths":[8,9]`.

#### Recipe 4: one study, legacy IDs and checked IDs

GHIT Cameroon IDs were first printed without a check character (`FC1-0589`); newer
labels add one (`FC1-0589C`). Keep both readable:

```text
@UVALIDATE={"strip":"-","blockSave":"hard","alternates":[
  {"label":"GHIT (check character)","pattern":"FC[1-9]-[0-9]{4}[0-9A-Z]","algorithm":"3736"},
  {"label":"GHIT (legacy, no check)","pattern":"FC[1-9]-[0-9]{4}","algorithm":"none"}
]}
```

```text expect
FC1-0589C     =>  valid
FC3-0179J     =>  valid
FC9-1200      =>  valid
FC1-0589A     =>  refused: check-character
```

```text
@UVALIDATE={"type":"pooled","strip":"-","blockSave":"hard","alternates":[
  {"label":"GHIT (check character)","pattern":"FC[1-9]-[0-9]{4}[0-9A-Z]","algorithm":"3736","lengths":[9]},
  {"label":"GHIT (legacy, no check)","pattern":"FC[1-9]-[0-9]{4}","algorithm":"none","lengths":[8]}
]}
```

```text expect
FC1-0589C FC3-0179J     =>  valid: FC1-0589C, FC3-0179J
FC9-1200 FC1-0589C      =>  valid: FC9-1200, FC1-0589C
FC1-0589A               =>  refused: junk
FC1-0589                =>  valid: FC1-0589
```

The cost of keeping legacy IDs valid: a checked ID whose last character was dropped
(`FC1-0589`) is itself a valid legacy ID, so it passes. In a pooled field, a checked
ID with a wrong last character reads as the legacy ID plus one junk character, so the
field is still refused, as junk rather than as a check-character error. Remove the
legacy entry once every label in use carries a check character.

#### Recipe 5: formats with different separators

GHIT Nigeria IDs use a slash (`ZRC2/0123V`) where Cameroon IDs use a hyphen:

```text
@UVALIDATE={"type":"pooled","strip":"-/","keepChars":"/","blockSave":"hard","alternates":[
  {"label":"GHIT Cameroon","pattern":"FC[1-9]-[0-9]{4}[0-9A-Z]","algorithm":"3736","lengths":[9]},
  {"label":"GHIT Cameroon (legacy)","pattern":"FC[1-9]-[0-9]{4}","algorithm":"none","lengths":[8]},
  {"label":"GHIT Nigeria","pattern":"ZRC[1-3]/[0-9]{4}[0-9A-Z]","algorithm":"3736","lengths":[10]},
  {"label":"GHIT Nigeria (legacy)","pattern":"ZRC[1-3]/[0-9]{4}","algorithm":"none","lengths":[9]}
]}
```

```text expect
FC1-0589C ZRC2/0123V        =>  valid: FC1-0589C, ZRC2/0123V
ZRC1/0042U ZRC3/0001        =>  valid: ZRC1/0042U, ZRC3/0001
FC9-1200,ZRC2/0123V         =>  valid: FC9-1200, ZRC2/0123V
ZRC2/0123A                  =>  refused: junk
FC1-0589C/ZRC2/0123V        =>  refused: junk
ZRC1-0042U                  =>  refused: no-id, junk
```

Two settings make this work:

- `strip:"-/"` removes both separators before the check character is computed. The
  Nigeria check character was minted over `ZRC20123`, without the slash.
- `keepChars:"/"` keeps the slash while the field is cleaned. Without it the rule is
  refused, because cleaning runs once over the whole field and the Cameroon entries
  would not otherwise keep `/`.

A kept character cannot separate IDs: `/` between two IDs is junk.

The one-ID version needs the same `keepChars`. A one-ID field is never split, but the
save check compares the characters each entry keeps in every `alternates` rule:

```text
@UVALIDATE={"strip":"-/","keepChars":"/","blockSave":"hard","alternates":[
  {"label":"GHIT Cameroon","pattern":"FC[1-9]-[0-9]{4}[0-9A-Z]","algorithm":"3736"},
  {"label":"GHIT Nigeria","pattern":"ZRC[1-3]/[0-9]{4}[0-9A-Z]","algorithm":"3736"}
]}
```

```text expect
FC1-0589C      =>  valid
ZRC2/0123V     =>  valid
ZRC2/0123A     =>  refused: check-character
ZRC2-0123V     =>  refused: format
```

#### Recipe 6: a check alphabet with an extra character

ISO 7064 Mod 37,2 can end an ID in `*`. When it shares a pooled field with an
algorithm that cannot, declare `*` in `keepChars`; see the example under
[Level 7](#level-7--separators-strip-keepchars).

#### Recipe 7: more formats than the 8-entry limit

A rule holds at most 8 entries. Several families with the same algorithm can share one
entry through alternation (`(?:A|B)`). This field holds lab sample IDs from Wave11
testing, RapidTB, Start4All, the three Phase 2 studies and GHIT Cameroon and Nigeria:

```text
@UVALIDATE={"type":"pooled","strip":"-/","keepChars":"/","blockSave":"hard","alternates":[
  {"label":"Wave11 testing","pattern":"[1-8][A-Z]{3}-[0-9]{5}","algorithm":"none","lengths":[10]},
  {"label":"RapidTB","pattern":"PP[1-6]-[0-9]{4}","algorithm":"none","lengths":[8]},
  {"label":"Start4All","pattern":"SC[1-6]-[0-9]{4}","algorithm":"none","lengths":[8]},
  {"label":"DARE-TB","pattern":"DT[1-9]-[0-9]{5}[0-9A-Z]","algorithm":"3736","lengths":[10]},
  {"label":"SCREEN-TB","pattern":"ST[1-9]-[0-9]{5}[0-9A-Z]","algorithm":"3736","lengths":[10]},
  {"label":"START4KIDS","pattern":"SK[1-9]-[0-9]{4}[0-9A-Z]","algorithm":"3736","lengths":[9]},
  {"label":"GHIT (check character)","pattern":"(?:FC[1-9]-[0-9]{4}|ZRC[1-3]/[0-9]{4})[0-9A-Z]","algorithm":"3736","lengths":[9,10]},
  {"label":"GHIT (legacy, no check)","pattern":"FC[1-9]-[0-9]{4}|ZRC[1-3]/[0-9]{4}","algorithm":"none","lengths":[8,9]}
]}
```

```text expect
1KUM-00123 SC1-0001 PP6-1234 DT1-00151N ST3-00042S SK2-0019I FC1-0589C ZRC2/0123V FC3-0179 ZRC1/0042  =>  valid: 1KUM-00123, SC1-0001, PP6-1234, DT1-00151N, ST3-00042S, SK2-0019I, FC1-0589C, ZRC2/0123V, FC3-0179, ZRC1/0042
1KUM-00123DT1-00151NFC1-0589CZRC1/0042PP2-0002SK2-0019I    =>  valid: 1KUM-00123, DT1-00151N, FC1-0589C, ZRC1/0042, PP2-0002, SK2-0019I
1KUM-00123, DT1-00151N; ST3-00042S\nSK2-0019I  SC3-0042    =>  valid: 1KUM-00123, DT1-00151N, ST3-00042S, SK2-0019I, SC3-0042
pp1-0001 1kum-00123 dt1-00151n                             =>  valid: PP1-0001, 1KUM-00123, DT1-00151N
1KUM-00123 DT1-00151A SK2-0019A                            =>  refused: check-character
1KUM-00123X                                                =>  refused: junk
PP7-0001 SC0-0001                                          =>  refused: no-id, junk
1KUM-00123 HSK1-0019                                       =>  refused: junk
1KUM-00123 1KUM-00123                                      =>  refused: duplicate
```

Things to know about this rule:

- An entry's `lengths` lists every length its pattern can produce. The GHIT entries
  declare two each, one per country.
- A merged entry has one label, so the field reports `GHIT (check character)` without
  naming the country.
- Wave11 testing IDs and DARE-TB/SCREEN-TB IDs are all 10 characters long. They share
  that length safely because a Wave11 ID starts with a digit.
- Healthcare-worker IDs (`HSK1-0019`) and pool IDs (`PXDT1-00011`) are refused as
  junk. They need their own field, or the free entries of a smaller rule.
- `1KUM-00123X` is a Wave11 screening ID. This field is for testing IDs, so the
  extra `X` is junk.

#### Recipe 8: per-format `source` and `strip`

An entry's `source` and `strip` replace the rule-level value for that entry only. Here
a lab number's Mod 11,10 check digit covers only its digits, while a TB register
number's Mod 37,36 check covers the whole ID:

```text
@UVALIDATE={"blockSave":"hard","alternates":[
  {"label":"Lab number","pattern":"LB-[0-9]{7}","algorithm":"mod11_10","source":"digits_only"},
  {"label":"TB register","pattern":"TB[A-Z]{3}-[0-9]{5}[0-9A-Z]","algorithm":"3736","strip":"-"}
]}
```

```text expect
LB-1234568      =>  valid
LB-1234567      =>  refused: check-character
TBABC-002397    =>  valid
TBABC-00239K    =>  refused: check-character
LB-123456       =>  refused: format
```

#### Recipe 9: a fixed number of IDs (`expectedIds`)

A pooled sample of exactly three Phase 2 participants:

```text
@UVALIDATE={"type":"pooled","strip":"-","expectedIds":3,"blockSave":"hard","alternates":[
  {"label":"DARE-TB","pattern":"DT[1-9]-[0-9]{5}[0-9A-Z]","algorithm":"3736","lengths":[10]},
  {"label":"SCREEN-TB","pattern":"ST[1-9]-[0-9]{5}[0-9A-Z]","algorithm":"3736","lengths":[10]}
]}
```

```text expect
DT1-00151N DT7-00320B ST3-00042S              =>  valid: DT1-00151N, DT7-00320B, ST3-00042S
DT1-00151N ST3-00042S                         =>  refused: count
DT1-00151N DT7-00320B ST3-00042S ST9-10077Y   =>  refused: count
DT1-00151N DT7-00320B ST3-00042A              =>  refused: check-character
```

#### Recipe 10: another answer picks the format list

When the site's country, not the ID, decides which formats are allowed, write one tag
per answer. Each tag can carry its own `alternates`:

```text
@UVALIDATE={"when":"[country]='1'","strip":"-","blockSave":"hard","alternates":[
  {"label":"GHIT Cameroon","pattern":"FC[1-9]-[0-9]{4}[0-9A-Z]","algorithm":"3736"},
  {"label":"GHIT Cameroon (legacy)","pattern":"FC[1-9]-[0-9]{4}","algorithm":"none"}
]}
@UVALIDATE={"when":"[country]='2'","strip":"-/","blockSave":"hard","alternates":[
  {"label":"GHIT Nigeria","pattern":"ZRC[1-3]/[0-9]{4}[0-9A-Z]","algorithm":"3736"},
  {"label":"GHIT Nigeria (legacy)","pattern":"ZRC[1-3]/[0-9]{4}","algorithm":"none"}
]}
```

A Nigerian ID entered for a Cameroon site is then refused, which one combined rule
could not do. See [Branching](#branching--several-tags-of-the-same-kind).

#### When IDs cannot share one pooled field

A pooled field splits a run of characters by length. If one declared length equals the
sum of two or more others, two short IDs scanned back to back could read as one long
ID, so the rule is refused. GHIT Vietnam IDs are 10 to 21 characters long
(`HN-PED-123Y` is 11, `GHIT-HCM-OPC-BTH-045W` is 21), and their own lengths already
collide: 20 = 10 + 10. They can only be validated one ID per field:

```text
@UVALIDATE={"strip":"-","blockSave":"hard","alternates":[
  {"label":"GHIT Vietnam (check character)","pattern":"(?:GHIT-CT-ACF-BT-[0-9]{3}|GHIT-HCM-OPC-(?:BTH|BD|BC|BT|CLA|CL|CK|THO|TH|TD|TP|AL|GV|HH|LX|MK|NL|PL|XC)-[0-9]{3}|HN-(?:HLH|NLH)-[0-9]{3}-[AB]|HN-PED-[0-9]{3}|HP-HPLH-[0-9]{3})[0-9A-Z]","algorithm":"3736"},
  {"label":"GHIT Vietnam (legacy)","pattern":"GHIT-CT-ACF-BT-[0-9]{3}|GHIT-HCM-OPC-(?:BTH|BD|BC|BT|CLA|CL|CK|THO|TH|TD|TP|AL|GV|HH|LX|MK|NL|PL|XC)-[0-9]{3}|HN-(?:HLH|NLH)-[0-9]{3}-[AB]|HN-PED-[0-9]{3}|HP-HPLH-[0-9]{3}","algorithm":"none"}
]}
```

```text expect
HN-PED-123Y               =>  valid
HN-HLH-007-AJ             =>  valid
HP-HPLH-045G              =>  valid
GHIT-HCM-OPC-BTH-045W     =>  valid
GHIT-CT-ACF-BT-210X       =>  valid
HN-PED-123                =>  valid
HN-PED-123A               =>  refused: check-character
GHIT-HCM-OPC-ZZ-045       =>  refused: format
```

#### Rules refused when saved

Each of these shows a configuration error under the field and validates nothing until
it is fixed. The `# refused:` line quotes the start of the message; CI checks that
each rule is refused for that reason.

```text invalid
# A format-only pattern that can match a check-bearing ID. Narrow OLD to [AB][0-9]{5}.
# refused: its pattern also accepts values meant for NEW
@UVALIDATE={"alternates":[{"label":"NEW","pattern":"[C-Z][0-9]{4}[0-9A-Z]","algorithm":"3736"},{"label":"OLD","pattern":"[A-Z][0-9]{5}","algorithm":"none"}]}

# The same overlap in a pooled rule, at the length both entries declare.
# refused: its pattern also accepts values meant for NEW
@UVALIDATE={"type":"pooled","alternates":[{"label":"NEW","pattern":"[C-Z][0-9]{4}[0-9A-Z]","algorithm":"3736","lengths":[6]},{"label":"OLD","pattern":"[A-Z][0-9]{5}","algorithm":"none","lengths":[6]}]}

# A backreference or lookaround the overlap test cannot settle. Write explicit classes.
# refused: too complex to prove
@UVALIDATE={"alternates":[{"label":"NEW","pattern":"(?=C)[A-Z][0-9]{4}[0-9A-Z]","algorithm":"3736"},{"label":"OLD","pattern":"(?!C)[A-Z][0-9]{5}","algorithm":"none"}]}

# Pooled lengths where one is the sum of others (18 = 8 + 10). Split into separate fields.
# refused: could swallow
@UVALIDATE={"type":"pooled","alternates":[{"label":"RapidTB","pattern":"PP[1-6]-[0-9]{4}","algorithm":"none","lengths":[8]},{"label":"Vietnam PED","pattern":"HN-PED-[0-9]{3}","algorithm":"none","lengths":[10]},{"label":"Vietnam ACF","pattern":"GHIT-CT-ACF-BT-[0-9]{3}","algorithm":"none","lengths":[18]}]}

# Nine entries. Merge families that share an algorithm (Recipe 7).
# refused: at most 8 are supported
@UVALIDATE={"alternates":[{"pattern":"A1[0-9]{4}","algorithm":"none"},{"pattern":"A2[0-9]{4}","algorithm":"none"},{"pattern":"A3[0-9]{4}","algorithm":"none"},{"pattern":"A4[0-9]{4}","algorithm":"none"},{"pattern":"A5[0-9]{4}","algorithm":"none"},{"pattern":"A6[0-9]{4}","algorithm":"none"},{"pattern":"A7[0-9]{4}","algorithm":"none"},{"pattern":"A8[0-9]{4}","algorithm":"none"},{"pattern":"A9[0-9]{4}","algorithm":"none"}]}

# Pooled entries that keep different characters. Add "keepChars":"/" (Recipe 5).
# refused: disagree about which characters survive cleaning
@UVALIDATE={"type":"pooled","strip":"-/","alternates":[{"label":"GHIT Cameroon","pattern":"FC[1-9]-[0-9]{4}[0-9A-Z]","algorithm":"3736","lengths":[9]},{"label":"GHIT Nigeria","pattern":"ZRC[1-3]/[0-9]{4}[0-9A-Z]","algorithm":"3736","lengths":[10]}]}

# One-ID rules are compared the same way. Add "keepChars":"/".
# refused: disagree about which characters survive cleaning
@UVALIDATE={"strip":"-/","alternates":[{"label":"GHIT Cameroon","pattern":"FC[1-9]-[0-9]{4}[0-9A-Z]","algorithm":"3736"},{"label":"GHIT Nigeria","pattern":"ZRC[1-3]/[0-9]{4}[0-9A-Z]","algorithm":"3736"}]}

# An escaped separator is kept like a plain one, so the same rule applies.
# refused: disagree about which characters survive cleaning
@UVALIDATE={"type":"pooled","alternates":[{"label":"SK","pattern":"SK[0-9]{4}[0-9A-Z]","algorithm":"3736","lengths":[7]},{"label":"LG","pattern":"LG\\/[0-9]{5}","algorithm":"none","lengths":[8]}]}

# {,n} and quantifiers with spaces. PHP 8.4 and the browser read them differently. Write {0,4} or {2,3}.
# refused: quantifier with spaces
@UVALIDATE={"algorithm":"none","pattern":"FC[0-9]{,4}"}
# refused: quantifier with spaces
@UVALIDATE={"alternates":[{"pattern":"FC[0-9]{2, 3}","algorithm":"none"}]}

# A pooled entry without "lengths".
# refused: needs "lengths"
@UVALIDATE={"type":"pooled","alternates":[{"pattern":"FC[1-9]-[0-9]{4}","algorithm":"none"}]}

# Rule-level lengths or pattern beside alternates. Put them inside each entry.
# refused: must not also set rule-level "idLengths"
@UVALIDATE={"type":"pooled","idLengths":[8],"alternates":[{"pattern":"FC[1-9]-[0-9]{4}","algorithm":"none","lengths":[8]}]}
# refused: must not also set a rule-level "pattern"
@UVALIDATE={"pattern":"FC[0-9]{4}","alternates":[{"pattern":"FC[1-9]-[0-9]{4}","algorithm":"none"}]}

# An empty list, an entry without a pattern, an unknown entry key, an unknown algorithm.
# refused: non-empty list
@UVALIDATE={"alternates":[]}
# refused: needs a non-empty "pattern"
@UVALIDATE={"alternates":[{"label":"X","algorithm":"3736"}]}
# refused: unknown option(s): prefix
@UVALIDATE={"alternates":[{"pattern":"FC[0-9]{4}","algorithm":"none","prefix":"FC"}]}
# refused: unknown algorithm "mod99"
@UVALIDATE={"alternates":[{"pattern":"FC[0-9]{4}","algorithm":"mod99"}]}
```

#### Rules that save but may not do what you meant

The save check cannot know your intent. These rules are accepted:

- **A pooled `lengths` value the pattern cannot produce.** `FC[1-9]-[0-9]{4}` is 8
  characters long, so `"lengths":[7]` never matches and every ID reads as junk:

  ```text
  @UVALIDATE={"type":"pooled","strip":"-","alternates":[{"label":"GHIT","pattern":"FC[1-9]-[0-9]{4}","algorithm":"none","lengths":[7]}]}
  ```

  ```text expect
  FC1-0589    =>  refused: no-id, junk
  ```

  Count the characters of a real ID, separators included, before you save.
- **Two format-only patterns that overlap.** A value both fit is credited to the first
  one listed. The verdict is the same; only the label differs.
- **Two check-bearing patterns that overlap.** A value both fit passes when either
  check character matches, which gives a mis-scan two chances. Keep check-bearing
  patterns disjoint, by prefix or by length. Here `C`-`M` codes fit both entries, so
  `C12348` (right for Mod 37,36) and `C12349` (right for Mod 37,2) both pass. Mod 37,2
  can produce `*`, which Mod 37,36 cannot, so the rule declares `keepChars:"*"`.

  ```text
  @UVALIDATE={"keepChars":"*","alternates":[{"label":"NEW","pattern":"[C-Z][0-9]{4}[0-9A-Z]","algorithm":"3736"},{"label":"OLD","pattern":"[C-M][0-9]{4}[0-9A-Z*]","algorithm":"372"}]}
  ```

  ```text expect
  C12348    =>  valid
  C12349    =>  valid
  C12347    =>  refused: check-character
  ```

- **Legacy and checked forms of one family** (Recipe 4). A checked ID with its last
  character dropped is a valid legacy ID.
- **`lengths` in a one-ID rule** is accepted and ignored:

  ```text
  @UVALIDATE={"strip":"-","alternates":[{"label":"GHIT","pattern":"FC[1-9]-[0-9]{4}","algorithm":"none","lengths":[7]}]}
  ```

  ```text expect
  FC1-0589    =>  valid
  ```

#### Keys

| Entry key   | Meaning                                                                                                                         |
| ----------- | ------------------------------------------------------------------------------------------------------------------------------- |
| `label`     | Format name shown in validation feedback. Without one, the entry is named by its position in the list.                         |
| `pattern`   | Required. Whole-ID regex, matched before separators are stripped.                                                               |
| `algorithm` | This format's check algorithm. Inherits the rule's `algorithm` when omitted; write `none` for a format-only entry.              |
| `source`    | What the check runs over for this entry; replaces the rule's `source`.                                                          |
| `strip`     | Separators removed before this entry's check; replaces the rule's `strip`.                                                      |
| `lengths`   | Pooled rules: required. Every length this entry's IDs can have, counting the characters its pattern keeps. Ignored in a one-ID rule. |

Rule-level keys that work with `alternates`: `type`, `algorithm` (the default for
entries that omit theirs), `source`, `strip`, `keepChars`, `expectedIds`,
`blockSave`, `when`, `suggestFix`, `caseSensitive`, `note`. Rule-level `pattern`,
`idLengths`, `idMinLen` and `idMaxLen` are refused; put patterns and lengths inside
the entries. A rule holds at most 8 entries. The pooled work limits and ambiguity
checks apply as for any pooled rule.

One rule using every rule-level key. The `Lab` entry has no `algorithm`, so it uses
the rule's Damm check over the digits only; `Legacy` overrides it with `none`:

```text
@UVALIDATE={"type":"pooled","algorithm":"damm","source":"digits_only","strip":"-","keepChars":"#","expectedIds":2,"blockSave":"confirm","when":"[sample_type]='2'","suggestFix":true,"caseSensitive":true,"note":"Pooled sputum pair","alternates":[
  {"label":"Lab","pattern":"LB-[0-9]{7}","lengths":[10]},
  {"label":"Legacy","pattern":"OLD#[0-9]{4}","algorithm":"none","lengths":[8]}
]}
```

```text expect
LB-1234566 OLD#0042     =>  valid: LB-1234566, OLD#0042
LB-1234565 OLD#0042     =>  refused: check-character
LB-1234566              =>  refused: count
```

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
  colliding record id only when that record is inside their own DAG.
- **What counts as the same value.** Values are trimmed and letter case is ignored, so
  `SP-1` and `sp-1` are duplicates; add `"caseSensitive":true` when case tells two
  values apart. On a field that holds numbers (a Text field validated as an integer or
  a number, or a slider) values compare by value: `007` and `7.0` both duplicate `7`.
  On a plain Text field they stay text, so `007` and `7` differ. Dropdown and radio
  values compare by code. `@UVEXISTS` compares the same way, so the two tags agree when
  one field carries both.
- **Save asks again.** A value the page found already used 30 seconds or more
  before Save is clicked is asked once more, so a record changed since then no
  longer blocks the save. That click waits for the answer; the next one is decided
  by it. When the server cannot answer, the earlier answer stands.
- **Missing Data Codes are never duplicates.** Ten records marked `UNK` share no
  value, and `unk` typed in one record is no duplicate of a saved `UNK`.
- **The race is audited, not denied.** Two near-simultaneous saves can both pass the
  live check. The post-save audit re-checks the saved value against every other record
  and logs a collision (`type: unique`, `reason: duplicate-value`) — review the module
  log for races.
- **Transport failures fail open.** A network error never traps a save.

### `@UVUNIQUE` JSON keys

`with`, `scope`, `when`, `message`, `blockSave`, `surveys`, `caseSensitive`. Any other
key is a configuration error. `caseSensitive` governs the `when` and the duplicate check
alike.

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

## `@UVWINDOW` — dates within a window

REDCap's date validation takes a fixed minimum and maximum date, and only warns.
It cannot say "21 to 35 days after this participant's baseline visit", and it has
no idea what today is. `@UVWINDOW` checks a date against a window counted from
another date, and/or that the date is not after today. JSON form only.

### Level 1 — not in the future

```text
# on: visit_date — a visit cannot be recorded before it happens
@UVWINDOW={"notFuture":true}
```

"Today" is the server's date, never the date on the user's computer, so a
laptop whose clock runs a day ahead cannot accept tomorrow's date. The page gets
the server's clock when it opens and moves it forward while it stays open, so a
form left open across midnight moves to the next day. On a datetime field the
limit is the current time, not the end of the day.

### Level 2 — a window after another date

```text
# on: visit_date_wk4 — the week-4 visit falls 21 to 35 days after baseline
@UVWINDOW={"from":"[visit_date_bl]","window":[21,35]}
```

`window` is `[earliest, latest]`, counted from the `from` date in whole units,
**both ends included**. The unit is days unless you say otherwise. With a baseline
of 2026-01-01 the rule accepts 2026-01-22 to 2026-02-05, and the message names
those dates in the field's own format:

> ✗ This date must be between 22-01-2026 and 05-02-2026. (21 to 35 days from [visit_date_bl])

The part in brackets is shown to staff only; a survey respondent sees the dates
without the other field's name. The example assumes a D-M-Y field.

### Level 3 — open ends, negative bounds, other units

```text
# on: diagnosis_date — not before birth, no upper limit
@UVWINDOW={"from":"[dob]","window":[0,null]}

# on: screening_date — at most 28 days BEFORE enrolment, and not after it
@UVWINDOW={"from":"[enrol_date]","window":[-28,0]}

# on: followup_date — weeks 10 to 14 after randomisation
@UVWINDOW={"from":"[rand_date]","window":[10,14],"unit":"weeks"}

# on: sample_taken_at (datetime) — within 6 hours after the dose
@UVWINDOW={"from":"[dose_given_at]","window":[0,6],"unit":"hours"}
```

`null` leaves that end open; one end must be a number. A negative bound counts
back from the `from` date. `unit` is `days` or `weeks` on a date field, and also
`minutes` or `hours` on a datetime field. Bounds are whole numbers up to 36,500
either way.

### Level 4 — both checks, message and enforcement

```text
# on: screening_date — within a week of enrolment, never in the future, hard block
@UVWINDOW={"from":"[enrol_date]","window":[-7,7],"notFuture":true,
           "message":"Screening must be within a week of enrolment, and not in the future.",
           "blockSave":"hard"}
```

When both checks fail, the page shows the "future" message, because a date in the
future is the more likely typing slip.

### Level 5 — gated, or counted from another event

```text
# on: visit_date — the window applies to scheduled visits only
@UVWINDOW={"from":"[visit_date_bl]","window":[21,35],"when":"[visit_type]='1'"}

# on: visit_date in the week-4 event — counted from the baseline event's date
@UVWINDOW={"from":"[baseline_arm_1][visit_date]","window":[21,35]}
```

The second form, a `from` date in another event or instance, needs **event and
instance references** enabled in the project settings (see
[Validation across events and repeating instruments](#validation-across-events-and-repeating-instruments)).
Without the feature, `from` must be a field of the same event.

### Semantics worth knowing

- **Eligible fields:** Text fields with date, datetime or datetime-with-seconds
  validation, in any display format (Y-M-D, M-D-Y or D-M-Y). Any other field shows a
  configuration error.
- **The `from` field must be the same kind of date.** A date window counts from a
  date field, a datetime window from a datetime field. A `from` field that does
  not exist, is not a date field, or is the tagged field itself is a configuration
  error.
- **Blank means nothing to check.** A blank field checks nothing, and so does a
  blank `from` date: the visit that anchors the window may not have happened yet.
  `notFuture` still applies while `from` is blank.
- **A date still being typed shows no verdict.** REDCap's own date check covers
  half-typed values.
- **A `from` date on another form is read when the page opens.** If the user may
  read that form, its saved value is used and the check is **advisory**: it shows
  the verdict but never blocks the save, because that form could change while
  this one is open. On a survey, or without rights to that form, the value is not
  sent to the page at all. The browser then checks only `notFuture`; staff see a
  note that the window is checked when the record is saved, and a survey
  respondent sees nothing about it.
- **`notFuture` keeps its `blockSave`.** "After today" does not depend on the
  `from` date, so a hard rule still blocks a future date when the window part is
  advisory or not sent. A rule whose `from` names another event is advisory as a
  whole on the page, like every rule with an event or instance reference.
- **Multi-page surveys.** The browser reads a `from` date only on the page being
  shown. If the `from` field is on an earlier page of the survey, the window is
  not checked while the respondent types; the post-save audit still checks it.
  Put both dates on one page to check the window as they are entered.
- **A `from` entry that does not exist yet** (no row in that event, no such
  instance) counts as a blank `from` date: nothing is checked until it is saved.
- **Today is the server's date.** The page gets the server clock when it opens
  and moves it forward while it stays open, so a wrong computer clock cannot
  accept tomorrow's date and a form left open past midnight moves to the next
  day. A computer clock moved forward while the form is open moves it too; the
  post-save audit reads the server clock and still logs the date. The date is
  taken in the server's time zone unless the project setting **Time zone for
  @UVWINDOW "notFuture"** names another one, such as `Africa/Douala`.
- **The server checks every save.** The post-save audit logs a violation as
  `type: window` with reason `window-early`, `window-late` or `future`. Saving
  only the form that holds the `from` date re-checks the windows counted from it.
- **The Validation scan** reports the same findings, with "Date outside allowed
  window" or "Date in the future" in the Issue column and the missed bound in the
  detail line. A scan judges "future" against the day it runs, so it flags only
  dates that are still in the future on that day. Each part of a durable scan
  reads the clock when that part runs.
- **Configure dialog:** none. `@UVWINDOW` exists only as an action tag.

### `@UVWINDOW` JSON keys

`from`, `window`, `unit`, `notFuture`, `when`, `message`, `blockSave`,
`caseSensitive`, and `references` (named bindings that `when` reads as `{alias}`;
needs event and instance references). A rule needs `from` with `window`, or
`"notFuture":true`, or both. Any other key is a configuration error.

These are refused when the rule is saved:

```text invalid
# Shorthand. The tag takes JSON only.
# refused: needs its settings as JSON
@UVWINDOW=21

# A window with nothing to count from.
# refused: "window" needs a "from" date
@UVWINDOW={"window":[21,35]}

# A "from" date with nothing to check.
# refused: "from" needs a "window"
@UVWINDOW={"from":"[visit_date_bl]"}

# Both ends open.
# refused: needs at least one bound
@UVWINDOW={"from":"[visit_date_bl]","window":[null,null]}

# Earliest after latest. Swap the numbers.
# refused: is after its latest bound
@UVWINDOW={"from":"[visit_date_bl]","window":[35,21]}

# Fractions. Use a smaller unit.
# refused: must be a whole number
@UVWINDOW={"from":"[visit_date_bl]","window":[0,1.5]}

# More than 100 years either way.
# refused: limited to 36500 units
@UVWINDOW={"from":"[dob]","window":[0,40000]}

# Months and years vary in length. Use days or weeks.
# refused: "unit" must be minutes, hours, days or weeks
@UVWINDOW={"from":"[visit_date_bl]","window":[1,3],"unit":"months"}

# A quoted boolean.
# refused: "notFuture" must be true or false
@UVWINDOW={"notFuture":"true"}

# Two dates in "from". A window counts from one date.
# refused: exactly one date field reference
@UVWINDOW={"from":"[visit_date_bl] [enrol_date]","window":[0,7]}

# A checkbox option is not a date.
# refused: exactly one date field reference
@UVWINDOW={"from":"[symptoms(1)]","window":[0,7]}

# Neither check.
# refused: needs a "from" date with a "window", or "notFuture": true
@UVWINDOW={"message":"Check the date"}
```

## `@UVEXISTS` — values that must already exist

REDCap checks that a value has the right shape. It cannot check that a specimen ID
typed on a lab result was ever registered, or that a record ID named on a transfer
form belongs to a real participant. `@UVEXISTS` asks the server, as the value is
entered, whether the value is already saved in the project.

### Level 1 — a record ID of this project

```text
# on: mother_record_id — the mother must already be enrolled
@UVEXISTS=record
```

The value must be the ID of a saved record. The answer is "found" or "not found";
a record-ID lookup never names another record.

### Level 2 — a value saved in another field

```text
# on: result_specimen_id — the specimen must be registered on the collection form
@UVEXISTS=[specimen_id]
```

The value must be saved in `specimen_id` in some record, in any event or repeat
instance. Staff see the record it was found in, unless they are in a Data Access
Group and that record is not.

### Level 3 — one event, one group, one site

```text
# on: result_specimen_id — registered at enrolment, by the same site
@UVEXISTS={"in":"[specimen_id]","event":"enrolment_arm_1","match":{"site_code":"[site]"}}

# on: referral_id — only among records of the same Data Access Group
@UVEXISTS={"in":"[referral_id]","scope":"dag"}

# on: kit_number — a kit dispensed in the same event as this entry
@UVEXISTS={"in":"[kit_dispensed]","scope":"event"}
```

`event` names one event by its unique name. `scope` is `project` (the default),
`dag` (records of the same Data Access Group as this record; records in no group
form one group) or `event` (the event of the entry being checked). `match` looks
only at saved entries whose target field holds the same value as a field of this
record: `{"site_code":"[site]"}` reads "where `site_code` equals this record's
`[site]`". Up to 5 pairs. The target values must be saved in the same entry as the
searched value: one event row, or one repeat instance together with its event row.

### Level 4 — message, enforcement, condition, surveys

```text
# on: result_specimen_id — hard block, with a message the lab understands
@UVEXISTS={"in":"[specimen_id]","message":"Register this specimen on the collection form first.",
           "blockSave":"hard"}

# on: partner_id — checked only when a partner is reported
@UVEXISTS={"in":"[participant_id]","when":"[has_partner]='1'"}

# on: voucher_code — also checked on the survey
@UVEXISTS={"in":"[voucher_issued]","surveys":true}
```

### Level 5 — a value saved in another project

```text
# on: result_specimen_id — the specimen is registered in the lab project (project 412)
@UVEXISTS={"in":"[specimen_id]","project":412}

# the same, with an alias this project's settings map to the lab project
@UVEXISTS={"in":"[specimen_id]","project":"lab","match":{"site_code":"[site]"}}

# on: lab_record_id — a record ID of the lab project
@UVEXISTS={"in":"record","project":"lab"}
```

`project` names another project on the same REDCap server, by its project id or
by an alias. An alias is set in this project's module settings ("@UVEXISTS
project aliases"), so the data dictionary can move between a test and a
production server unchanged; only the alias row differs. `in`, `event` and the
`match` targets then name fields and events of that project; the `match` values
stay fields of this record. Three switches must all be on:

1. **The server.** An administrator turns on "@UVEXISTS in other projects" in the
   module's Control Center settings. It is off by default.
2. **The searched project.** It must have this module enabled and list this
   project under "Projects that may look up values here", with the fields it may
   search (`record` for its record ID) and who gets an answer:
   - *Only users who have rights to those fields in this project* (the default):
     the user needs a current rights row there and access to the form of each
     searched field. A user in a Data Access Group there is answered from their
     own group only.
   - *Any signed-in user of the asking project*: found / not found only, and no
     searched field may be an Identifier there. Any value typed in the asking
     project can then be tested against the searched one, which is why this is
     the searched project's choice.
3. **Surveys,** only when the searched project answers any signed-in user, also
   ticks "Also answer survey respondents", and the rule says `"surveys":true`.

### Semantics worth knowing

- **Eligible fields:** Text, dropdown, radio and SQL fields. The searched field and
  each `match` target must exist and hold one value (no checkbox, file or
  descriptive field).
- **How values compare.** Values are trimmed (spaces, tabs, line breaks and the
  no-break space) and letter case is ignored, so `sp-1` finds `SP-1`. Add
  `"caseSensitive":true` to compare letter for letter. Only A to Z fold:
  accented letters and other scripts compare exactly, and no Unicode
  normalisation is applied, so an accent typed as a separate mark differs from
  the same letter typed whole.
- **Numbers compare by value** when the searched field holds numbers: a Text
  field validated as an integer or a number, a calc or a slider. `7` then finds
  `007`, `7.0` and `+7`. A field with a decimal comma searched from one with a
  decimal point (or the other way round) is refused when the rule is saved. On a
  plain Text field numbers stay text, so `7` does not find `007` there.
- **Dates** compare as REDCap stores them (Y-M-D), whatever format the field
  shows, so the field and the field searched must be stored the same way: two
  dates, two datetimes to the minute, two datetimes to the second, two times of
  the same precision, or neither a date nor a time. Dropdown and radio values
  are compared by code, so the two fields need the same codes.
- **Record IDs.** `@UVEXISTS=record` never finds the record being edited. A
  record ID typed in another letter case (`xe-7` for `XE-7`) is found after one
  extra read of the project's record IDs, within the survey read budget on a
  survey. With `"caseSensitive":true` only the exact ID is found. When the
  record-ID field is validated as an integer or number, IDs compare by value:
  `007` finds record `7`.
- **Missing Data Codes.** A saved code is no value: `unk` typed here does not
  find `UNK` saved in the searched field.
- **The record shown to staff.** When several entries match, the one in the
  user's own Data Access Group comes first, so it can be named, then the one
  spelled exactly as typed.
- **Asked when the value is entered, not per keystroke.** The page asks when the
  field is changed or left, and once when the form opens. Typing clears the last
  answer, so a half-typed value never reads "not found"; typing in a text field
  a `when` condition reads counts too.
- **Save waits for the answer.** Clicking Save re-checks the field first: a value
  typed and saved at once is asked before the save is decided, and the save is
  held while the answer is on its way. After 10 seconds without one, the value
  counts as *could not check*. A *not found* is never taken from the page's
  memory at Save: the value may have been saved elsewhere since, so it is asked
  again.
- **Three answers.** *Found* (green), *not found* (red; enforced per `blockSave`),
  and *could not check* (amber; never blocks). A failed read, a `match` field that
  is blank on another form, a busy server and missing rights all answer *could not
  check*, and staff see the reason. The post-save audit checks the value again,
  except when a `match` field is still blank: then there is nothing to check
  until that field is saved, and saving its form checks the lookup again.
- **Blank checks nothing.** A blank value is not looked up. A blank `match` field
  on the same page means there is nothing to narrow by yet, so nothing is asked.
- **Branches.** With several `when` branches the page sends the values its
  conditions read, and the server answers from that branch, also on a new record.
- **Where the rule looks stays on the server.** The page config holds the rule's
  message and enforcement but not `in`, `event`, `scope` or `match`; the server
  reads them from the stored rule. On a survey, a configuration error shows as a
  general notice, and the "no access" reason does not name a form or field.
- **Rights.** A signed-in user is answered only when they may open the forms that
  hold the searched field and the `match` fields. A record-ID lookup needs no extra
  form. At most 60 lookups a minute per user session, shared with `@UVUNIQUE`. A
  user in a Data Access Group is answered only on records of their own group.
- **Data Access Groups.** A group user's reads may be confined to their group. A
  *not found* from a rule that looks across groups is kept only when the module
  sees other groups' records in the same request; otherwise it is *could not
  check*, and the audit reports a rule problem rather than a violation.
- **Surveys: opt-in.** `"surveys":true` turns the check on for surveys, with a
  found / not found answer only and the survey rate limit. It is refused when any
  field the lookup touches is an Identifier, including the record-ID field for
  `@UVEXISTS=record`, because a "found" answer would let anyone holding the survey
  link test whether a value is in the study. A *not found* costs a read of the
  whole searched field, so survey lookups may cause at most 60 such reads a
  minute per project; past that they answer *could not check* (shown as nothing).
- **The server checks every save.** The post-save audit logs a violation as
  `type: exists` with reason `not-found`. A lookup that cannot be completed is
  logged as a rule problem, never as a pass.
- **The Validation scan** reads the searched field once per rule per scan request
  and checks every record against it, with "Not found in its source" in the Issue
  column. It reads in chunks of records and stops with a rule problem, not a
  pass, when the index it builds is on course to use more than 60% of PHP's
  memory limit. A scan confined to one Data Access Group skips rules whose `scope` is
  not `dag`, and reports them as not evaluated, because a value saved only in
  another group would read as not found. A durable scan with `@UVEXISTS` rules
  claims coverage through its change fence only when no record changed during
  the run.
- **Configure dialog:** none. `@UVEXISTS` exists only as an action tag.

### Semantics in another project

- **One refusal for every reason.** Until the searched project's agreement
  passes, every problem shows the same setup error: the project does not exist,
  is deleted, does not have the module, does not list this project, or does not
  list a searched field. Neither its dictionary nor its data is read before
  then, so the error tells a designer nothing about that project. An `event`
  that is not one of its events gets the same error, because the agreement
  lists fields, not events. Field errors (a field missing there, a different
  kind of date) come only after the agreement passes. A withdrawn agreement
  shows up the same way on the form, and a survey shows its generic notice.
- **The record found there is never shown.** Staff get found / not found and,
  for *could not check*, a reason.
- **Lookups are logged in the searched project.** Once the module can read that
  project's agreement, its module log gets one `uv-exists-probe` line per
  lookup, answered or refused: the asking project, the channel (staff, survey,
  audit), the user ("survey" for a respondent, "(no user)" for a save nobody
  was signed in for, such as a data import), the field, the result, and the
  value as a keyed hash under that project's key. A rule that ignores letter
  case hashes the value in lower case and adds `case: ignored`, so `SP-1` and
  `sp-1` leave the same hash. The value is left out when
  that project's "How to log invalid values" is "none" or "off", and is never
  logged raw. When a *not found* needed a read of that project's record IDs,
  the line says `extra_read: record ids`. Nothing is written there while the
  Control Center switch is off or the module is not enabled in that project.
  Over a budget, only the first refused lookup of the minute is logged, so a
  flood of refused lookups is not a flood of log lines.
- **Budgets.** Each searched project keeps three counters, so no kind of caller
  can use up another's: staff lookups (1,200 a minute from every project
  together, plus 30 a minute per signed-in session), survey lookups (120 a
  minute from every project together, plus the session window), and post-save
  audits with scans (1,200 a minute). Administrators can change the 1,200 and
  the 30 in the Control Center settings; the survey limit is fixed. Over a
  limit, or when the counter cannot be kept, the lookup answers *could not
  check*.
- **An empty read is not "not found".** When the searched project returns no
  records at all to the lookup (or, for a user confined to a group there, none
  of that group), the answer is *could not check*. A record-ID lookup that
  finds nothing reads that project's record IDs to tell the two apart; a survey
  lookup does so only within the survey read budget.
- **The audit** asks as the user who saved. A save with no signed-in user (a
  survey) is checked only when the searched project answers survey respondents.
  A saver who is in a Data Access Group of the searched project, under "Only
  users who have rights", is not checked after the save: their lookups see only
  their group there, and a value saved in another group would be logged as
  missing. Anything else is logged as a rule problem.
- **The Validation scan** reads the searched project once per searched field per
  scan request and leaves one `uv-exists-index-read` line in its log. A rule is
  reported as not evaluated when the person running the scan would not be
  answered, or is in a Data Access Group of the searched project; in a rule
  with several branches only those branches are reported, and the others are
  checked. A stored scan that searched another project is shown only to people
  that project would answer. Changes saved in the searched project do not
  re-open a scan of this one, and the report says so. A scan confined to one
  group of this project still checks these rules: this project's groups mean
  nothing in the other one.
- **A field named `record`** cannot be searched or matched: `record` stands for
  the record ID, in this tag and in the other project's agreement.

### `@UVEXISTS` JSON keys

`in`, `project`, `event`, `scope`, `match`, `surveys`, `when`, `message`,
`blockSave` and `caseSensitive`. Any other key is a configuration error.

These are refused when the rule is saved:

```text invalid
# Nothing to look in.
# refused: needs to know where to look
@UVEXISTS=

# Two fields. The lookup searches one.
# refused: is not a place to look
@UVEXISTS=[specimen_id][aliquot_id]

# The JSON form needs "in".
# refused: needs "in"
@UVEXISTS={"scope":"dag"}

# Another project: a project id, or an alias that starts with a letter.
# refused: "project" must be a project id
@UVEXISTS={"in":"[specimen_id]","project":"12-lab"}

# Groups and events of this record mean nothing in another project.
# refused: not shared between projects
@UVEXISTS={"in":"[specimen_id]","project":"lab","scope":"dag"}

# "record" is the record ID. A field of that name cannot be searched.
# refused: a field named "record" cannot be used
@UVEXISTS=[record]

# Nor matched.
# refused: a field named "record" cannot be used
@UVEXISTS={"in":"[specimen_id]","match":{"record":"[site]"}}

# Scopes are project, dag and event.
# refused: "scope" must be project, dag or event
@UVEXISTS={"in":"[specimen_id]","scope":"site"}

# "event" takes the unique event name.
# refused: unique event name
@UVEXISTS={"in":"[specimen_id]","event":"Enrolment visit"}

# One event, or the entry's own event. Not both.
# refused: cannot be combined
@UVEXISTS={"in":"[specimen_id]","event":"enrolment_arm_1","scope":"event"}

# A record ID belongs to the whole record.
# refused: does not apply to "in":"record"
@UVEXISTS={"in":"record","event":"enrolment_arm_1"}

# A record ID has nothing to match.
# refused: a record ID has nothing to match
@UVEXISTS={"in":"record","match":{"site_code":"[site]"}}

# "match" maps a target field to a field of this record.
# refused: "match" must be an object
@UVEXISTS={"in":"[specimen_id]","match":["[site]"]}

# The match value must be a field reference.
# refused: must be one field reference
@UVEXISTS={"in":"[specimen_id]","match":{"site_code":"A"}}

# The searched field already holds the value looked up.
# refused: is the field named in "in"
@UVEXISTS={"in":"[specimen_id]","match":{"specimen_id":"[site]"}}

# A quoted boolean.
# refused: "surveys" must be true or false
@UVEXISTS={"in":"[voucher_issued]","surveys":"true"}
```

## `@UVRANGE` — numbers within plausible limits

REDCap's number validation takes one minimum and one maximum, and only warns.
Lab and clinical numbers need two levels. A value outside the usual range is
worth a second look before saving; a value outside what is physically possible
is a typing slip. `@UVRANGE` checks a number against both levels. JSON form only.

### Level 1 — usual and plausible limits

```text
# on: hb — haemoglobin, a number field
@UVRANGE={"soft":[12,17.5],"hard":[3,25],"unit":"g/dL"}
```

```text expect
14.2   => ok
12     => ok
17.50  => ok
11.4   => soft-low
19     => soft-high
2.5    => hard-low
31     => hard-high
1.5E1  => ok
1e1    => soft-low
n/a    => not-a-number
```

`soft` is the usual range. A value outside it is unusual. Its note is amber,
and the save asks "save anyway?" first. `hard` is the plausible range. A value
outside it, or text that is not a number, is implausible. Its note is red,
and the save is blocked until the value is fixed. Both ends of each range are
included. The notes name the limits:

> ⚠ This value is lower than usual (expected 12 to 17.5 g/dL).
>
> ✗ This value is above the plausible range (allowed 3 to 25 g/dL).

The note appears when the user leaves the field, not while a number is being
typed, so "1" on the way to "12" is never flagged.

### Level 2 — how strongly each level enforces

```text
# on: sbp — systolic pressure, no usual minimum, and only a note above 140
@UVRANGE={"soft":[null,140],"softBlock":"off","hard":[40,250],"unit":"mmHg"}

# on: weight_kg — ask before saving an implausible weight instead of refusing it
@UVRANGE={"soft":[30,150],"hard":[2,300],"hardBlock":"confirm","unit":"kg"}
```

| Key           | Values               | Default     | Applies to                               |
| ------------- | -------------------- | ----------- | ---------------------------------------- |
| `softBlock` | `off`, `confirm`  | `confirm` | A value outside `soft`                 |
| `hardBlock` | `confirm`, `hard` | `hard`    | A value outside `hard`, or not a number |

`blockSave` does not apply to this tag and is refused; these two keys take its
place. `null` leaves one end of a range open. A rule may have only `soft`, only
`hard`, or both. With both, the soft limits must lie inside the hard ones.

### Level 3 — limits by sex, age group or anything else

```text
# on: hb — different usual limits for women and men
@UVRANGE={"soft":[12,15.5],"hard":[3,25],"unit":"g/dL","when":"[sex]='2'"}
@UVRANGE={"soft":[13.5,17.5],"hard":[3,25],"unit":"g/dL","when":"[sex]='1'"}
```

Several `@UVRANGE` tags on one field branch, and the one whose `when` is true
applies (see [Branching](#branching--several-tags-of-the-same-kind)). One of
them may leave out `when` to give the limits for everyone else. While `sex` is
blank, neither branch above applies and nothing is checked.

### Level 4 — your own message

```text
# on: temp_c
@UVRANGE={"soft":[36,37.5],"hard":[30,43],"unit":"°C","message":"Check the temperature and the unit (°C, not °F)."}
```

`message` replaces the generated note for both levels and for a value that is
not a number.

### Level 5 — growth references (z-scores)

A child's weight, length or head circumference is usual or not for that
child's sex and age. With `reference`, the limits apply to the value's
z-score against a growth reference, read with the sex and the age (or the
length or height) from other fields:

```text
# on: weight_kg — weight-for-age, WHO 2006 standards, birth to 5 years
@UVRANGE={"reference":"who-wfa","sex":"[sex]","male":"1","female":"2","age":{"dob":"[dob]","at":"[visit_date]"},"soft":[-2,2],"hard":[-5,5]}

# on: weight_kg — weight-for-height, by a height field in cm
@UVRANGE={"reference":"who-wfh","sex":"[sex]","male":"1","female":"2","by":"[height_cm]","soft":[-3,3],"hard":[-5,5]}

# on: bmi — BMI-for-age, WHO 2007 reference, with the age in months held in a field
@UVRANGE={"reference":"who2007-bfa","sex":"[sex]","male":"1","female":"2","age":{"months":"[age_months]"},"hard":[-5,5]}
```

A weight of 6.6 kg for a boy of exactly one year has a weight-for-age
z-score of -3.40, so the first rule shows:

> ⚠ This value is lower than usual (z-score -3.40 on Weight-for-age, WHO 2006 (birth to 5 years); expected z-score -2 to 2).

| Key | Value |
| --- | ----- |
| `reference` | The id of the reference (table below) |
| `sex` | The field holding the sex, e.g. `"[sex]"` |
| `male`, `female` | The codes that field stores for male and for female |
| `age` | `{"dob":"[dob]","at":"[visit_date]"}` (two date fields), `{"days":"[age_days]"}` or `{"months":"[age_months]"}` |
| `by` | For a reference by length or height: the field holding it, in cm |
| `soft`, `hard` | z-score limits, from -20 to 20 |

The references that ship with the module:

| `reference` | Measurement | Read by | Valid for |
| ----------- | ----------- | ------- | --------- |
| `who-wfa` | weight (kg) | age | birth to 5 years |
| `who-lhfa` | length or height (cm) | age | birth to 5 years |
| `who-bfa` | BMI (kg/m²) | age | birth to 5 years |
| `who-hcfa` | head circumference (cm) | age | birth to 5 years |
| `who-acfa` | mid-upper arm circumference (cm) | age | 3 months to 5 years |
| `who-ssfa` | subscapular skinfold (mm) | age | 3 months to 5 years |
| `who-tsfa` | triceps skinfold (mm) | age | 3 months to 5 years |
| `who-wfl` | weight (kg) | length, measured lying | 45 to 110 cm |
| `who-wfh` | weight (kg) | height, measured standing | 65 to 120 cm |
| `who2007-wfa` | weight (kg) | age | 5 to 10 years |
| `who2007-hfa` | height (cm) | age | 5 to 19 years |
| `who2007-bfa` | BMI (kg/m²) | age | 5 to 19 years |

The z-score is the one WHO's own software (the `anthro` and `anthroplus` R
packages) computes: the same tables, the same restricted method beyond 3 SD for
the weight, BMI, arm circumference and skinfold indicators, and the same
rounding to two decimals. The module's tests compare 2,880 points with WHO's
code. An administrator can add other references, such as CDC 2000 or a national
one (see `data/references/README.md`).

- **The age.** From two dates, the age is the whole number of days between
  them (a date with a time counts its date only). A month is 30.4375 days.
  The WHO 2006 tables have a row per day and use the nearest day; the WHO 2007
  tables and the length and height tables interpolate between rows.
- **What checks nothing.** A blank input, a sex code that is neither `male`
  nor `female`, an age or a length outside the reference, and a measurement
  dated before the birth. On a data entry form a grey note tells the user why
  ("Not checked against Weight-for-age, WHO 2006 (birth to 5 years): the age is
  outside the reference (0 to under 1826.25 days)."); a survey shows nothing.
  A missing data code in an input counts as blank.
- **What is implausible whatever the inputs say.** A value that is not a number,
  and a measurement of 0 or below (reason `not-positive`).
- **Not applied.** WHO's anthro software adds 0.7 cm to a length measured
  standing under 2 years, and takes 0.7 cm from a height measured lying from
  2 years. The module does not know how the child was measured: pick
  `who-wfl` or `who-wfh` to match, or record the corrected value. WHO gives no
  weight-based z-score for a child with oedema; skip the rule with `when`, e.g.
  `"when":"[oedema]<>'1'"`.
- **Inputs on another form** are read when the page opens, and the rule's
  note does not block on the page, as for a `when` on another form. A survey,
  and a user without rights to that form, are not sent the values: the page
  checks only that the value is a number above 0, and holds the save when it
  is not. Staff see a note that the z-score is checked when the record is
  saved; a survey shows no note. The post-save audit checks it.
- **Inputs in another event** need event and instance references turned on in
  the project settings, e.g. `"dob":"[enrolment_arm_1][dob]"`. Such a rule never
  blocks on the page; the post-save audit and the scan check every entry.
- **What the dictionary must hold.** The reference must exist, and its axis
  decides between `age` and `by`. `sex` is a radio, dropdown, yes/no,
  true/false, Text, calc or SQL field, and when it has choices, `male` and
  `female` must be among them (yes/no and true/false store 1 and 0). `dob` and
  `at` are date or datetime fields; `days`, `months` and `by` are Text fields
  with integer or number validation, or calcs. No input may be the measured
  field itself.
- **Limits the reference can reach.** Near 0, some rows give no z-score below
  a floor, and some (in a table without `adjust`, with L below 0) none above a
  ceiling. At 5 years, `who-tsfa` never scores below -6.97 for a girl, so a hard
  low limit of -7 would not hold back even 0.1 mm. A `soft` or `hard` limit beyond such a
  bound anywhere in the reference is a configuration error that names the row
  and the value a limit must clear ("a low limit must be above -6.97"). Every shipped reference takes soft
  limits of -3 to 3 and hard limits of -6 to 6.
- **Page size.** A page carries a copy of each table it uses (3 to 95 KB) and at
  most four different references. A rule needing a fifth is checked when the
  record is saved, and its note says so. A rule whose inputs are not sent to
  the page (a survey, or a form the user cannot see) gets no table and does not
  count towards the four.

### Semantics worth knowing

- **Eligible fields.** Text fields with no validation, integer validation or
  number validation (any number of decimal places, with a point or a comma),
  calc fields and sliders. A Text field with any other validation, such as a
  date or an email, shows a configuration error.
- **What counts as a number.** Digits with at most one decimal point and an
  optional sign, such as `12`, `-0.5`, `.5` or `+14`, and the exponent form
  REDCap's number validation also accepts: `1.5E1` is 15 and `2e-3` is 0.002.
  Thousands separators (`1,200`) and a unit typed into the box (`12 g/dL`) are
  not numbers and count as implausible, even on a rule with only `soft`
  limits. REDCap's own validation stops these on a number field; on a Text
  field with no validation, `@UVRANGE` is the only check.
- **Exact comparison.** Limits and values are compared as decimals, digit by
  digit, so `17.50` equals `17.5` and very long numbers keep every digit. A
  limit is kept exactly as written, quoted or not: `0.12345678901234567890`
  keeps all twenty digits. A limit may be up to 64 characters long once any
  exponent is written out.
- **Missing data codes.** A field marked with one of the project's missing
  data codes (REDCap's "M" button, or an import) holds the code itself, such as
  `UNK` or `-99`. REDCap does not validate it and neither does `@UVRANGE`: the
  field is treated as blank, on the page, in the post-save audit and in the
  scan. Every other tag does the same, except `@UVREQUIRED`, which counts a
  missing data code as an answer. A `when` condition reads the code as itself,
  as REDCap's branching logic does.
- **Comma decimals.** On a field with comma-decimal validation
  (`number_1dp_comma_decimal` and the like), `17,5` is read as 17.5 and the
  notes write the limits with a comma. Limits in the tag are always written
  with a point.
- **Blank checks nothing.**
- **A calc field never blocks a save**, because nobody typed its value. It still
  shows the note, and the post-save audit and the scan still report it. A
  read-only field never holds a save either.
- **A `when` that reads a field on another form** works as on the other tags.
  The page reads that field's saved value when it opens, and the rule does not
  block on the page. On a data entry form the note names the fields that chose
  the limits; a survey names none. The post-save audit checks it with the saved
  values.
- **Event and instance references.** With them turned on in the project
  settings, `when` and the growth-reference inputs may read another event or
  instance, such as `[baseline_arm_1][sex]`. Such a rule never blocks on the
  page; the post-save audit and the scan check it. The `references` key is
  refused.
- **The post-save audit checks each form and survey save.** It logs a value
  outside either range as `type: range`, with reason `soft-low`, `soft-high`,
  `hard-low`, `hard-high`, `not-a-number` or, for a growth reference,
  `not-positive`, whatever `softBlock` says. A rule that only shows notes
  still leaves a record of each unusual value.
- **The Validation scan** counts these findings. Its report labels them
  "Unusual value", "Implausible value", "Not a number" or "Not a positive
  measurement", with the limits in the detail line ("Expected 12 to 17.5
  g/dL." or "Allowed 3 to 25 g/dL.").
  The report page and its download are not available while the scan is being
  rebuilt (see the README); the scan page shows the count. The scan's own
  result names the branch that judged each value, so the report can show that
  branch's limits; the stored run does not keep the branch yet.
- **Configure dialog:** none. `@UVRANGE` exists only as an action tag.

### `@UVRANGE` JSON keys

`soft`, `hard`, `softBlock`, `hardBlock`, `unit`, `when`, `message` and
`caseSensitive`, and for a growth reference `reference`, `sex`, `male`,
`female`, `age` and `by`. A rule needs `soft` or `hard` limits. Any other key
is a configuration error.

These are refused when the rule is saved:

```text invalid
# Shorthand. The tag takes JSON only.
# refused: needs its settings as JSON
@UVRANGE=12-17.5

# No limits at all.
# refused: needs "soft" or "hard" limits
@UVRANGE={"unit":"g/dL"}

# A range is a list of two limits.
# refused: must be a list of two limits
@UVRANGE={"hard":[3,25,40]}

# Both ends open checks nothing.
# refused: needs at least one limit
@UVRANGE={"soft":[null,null],"hard":[3,25]}

# Low above high. Swap the numbers.
# refused: is above its high limit
@UVRANGE={"hard":[25,3]}

# The usual range must lie inside the plausible one.
# refused: is outside the "hard" range
@UVRANGE={"soft":[2,17.5],"hard":[3,25]}

# A limit must be a number.
# refused: must be a number such as 12
@UVRANGE={"hard":["three",25]}

# A limit is written with a point, even for a comma-decimal field.
# refused: must be a number such as 12
@UVRANGE={"hard":["0","1,5"]}

# A limit of more than 64 characters once written out.
# refused: is longer than 64 characters
@UVRANGE={"hard":[0,1e100]}

# blockSave is replaced by softBlock and hardBlock.
# refused: "blockSave" does not apply
@UVRANGE={"hard":[3,25],"blockSave":"hard"}

# softBlock has no hard block, because an unusual value may be right.
# refused: "softBlock" must be off or confirm
@UVRANGE={"soft":[12,17.5],"softBlock":"hard"}

# hardBlock cannot be off. For notes only, use "soft" with "softBlock":"off";
# text that is not a number still follows hardBlock.
# refused: "hardBlock" must be confirm or hard
@UVRANGE={"hard":[3,25],"hardBlock":"off"}

# A unit is a short label.
# refused: "unit" must be a short label
@UVRANGE={"hard":[3,25],"unit":"grams per decilitre of blood"}

# The growth keys need a reference.
# refused: only apply with a "reference"
@UVRANGE={"hard":[3,25],"sex":"[sex]","male":"1","female":"2"}

# A reference sets the unit itself.
# refused: "unit" does not apply with a "reference"
@UVRANGE={"reference":"who-wfa","sex":"[sex]","male":"1","female":"2","age":{"days":"[age_days]"},"hard":[-5,5],"unit":"kg"}

# z-score limits lie between -20 and 20.
# refused: must lie between -20 and 20
@UVRANGE={"reference":"who-wfa","sex":"[sex]","male":"1","female":"2","age":{"days":"[age_days]"},"hard":[-30,30]}

# The two codes must differ.
# refused: are the same code
@UVRANGE={"reference":"who-wfa","sex":"[sex]","male":"1","female":"1","age":{"days":"[age_days]"},"hard":[-5,5]}

# A reference is read by age or by length or height, never both.
# refused: give "age" or "by", not both
@UVRANGE={"reference":"who-wfh","sex":"[sex]","male":"1","female":"2","age":{"days":"[age_days]"},"by":"[height_cm]","hard":[-5,5]}

# An age from dates needs both of them.
# refused: "age" must be
@UVRANGE={"reference":"who-wfa","sex":"[sex]","male":"1","female":"2","age":{"dob":"[dob]"},"hard":[-5,5]}

# An input is one field, not a condition.
# refused: "sex" must be exactly one field reference
@UVRANGE={"reference":"who-wfa","sex":"[sex]='1'","male":"1","female":"2","age":{"days":"[age_days]"},"hard":[-5,5]}
```

These are refused once the data dictionary is read, with a configuration error
under the field: a `reference` this server does not have (the error lists the
ones it has), `age` on a reference read by length or height (and `by` on one
read by age), an input field that does not exist or is the wrong kind, a
`male` or `female` code that is not among the sex field's choices, and a limit
the reference cannot reach (see "Limits the reference can reach" above).

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

The same dialect powers `when` on every tag and `assert` on `@UVASSERT`. It is a
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

Text in a condition is compared without regard to the case of A-Z. So are the values
`@UVEXISTS` looks up and the values `@UVUNIQUE` compares. All of these match `Yes`,
`yes` and `YES`:

```text
@UVREQUIRED="[consent]='yes'"
@UVALIDATE={"algorithm":"verhoeff","when":"[specimen_type]='sputum'"}
@UVASSERT={"assert":"[answer]='yes' or [answer]='no'","message":"Answer yes or no"}
```

Set `"caseSensitive":true` when case is part of the value:

```text
@UVASSERT={"assert":"[lot_code]=[lot_code_confirm]","caseSensitive":true,"message":"Lot codes must match exactly"}
@UVREQUIRED={"when":"[grade]='A'","caseSensitive":true}
@UVUNIQUE={"caseSensitive":true,"message":"This code is taken (codes are case-sensitive)"}
@UVEXISTS={"in":"[lot_code]","caseSensitive":true}
```

- Every tag with a `when` accepts it: `@UVALIDATE`, `@UVASSERT`, `@UVREQUIRED`,
  `@UVUNIQUE`, `@UVCHOICES`, `@UVWINDOW`, `@UVEXISTS`, `@UVRANGE`. In the Configure
  dialog it is the "compare text case-sensitively" checkbox on the rule.
- It covers **every comparison of that one rule**: its `when`, its `assert`, the
  value `@UVEXISTS` looks up, the duplicate check of `@UVUNIQUE`, and, when several
  tags on a field branch, that branch's selector. Each branch uses its own tag's flag.
- The value must be an unquoted `true` or `false`; `"true"` or `1` is a configuration
  error. `false` is the default and changes nothing.
- Only A-Z are folded. Accented and other non-ASCII letters keep their case, because
  the browser and the server lowercase those differently and must reach the same verdict.
- Numbers are unaffected (`'2.50'` still equals `'2.5'`), and so is ordering between
  numbers and text.
- Branches that differ only in case, such as `[site]='a'` and `[site]='A'`, are both
  true at once under the default and are reported as a branch conflict.
- Up to 2.1.0-rc.2, `@UVUNIQUE` compared exactly. A project that already holds `SP-1`
  and `sp-1` in a unique field now shows them as duplicates; add `"caseSensitive":true`
  to keep the old behaviour.

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
@UVALIDATE={"algorithm":"none","pattern":"SK[1-9]-[0-9]{4}[0-9A-Z]","when":"[baseline_arm_1][consent]='1'"}

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

A binding takes `event` or `events`, never both. Each event appears in `events` once.
`events:"arm"` needs `arm`, and the arm must contain an event that collects the instrument. Up to 20 bindings per rule;
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

`caseSensitive` here governs the `when` text and the duplicate comparison alike: with
it, `SP-1` and `sp-1` are different specimens. Leading zeros count unless the field holds
numbers (an integer or number validation), where `007` and `7` are the same value.

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

### `@UVWINDOW` recipes

#### Dates that cannot be in the future

```text
# on: consent_date
@UVWINDOW={"notFuture":true,"blockSave":"hard"}

# on: specimen_collected_at (datetime) — a collection time cannot be later than now
@UVWINDOW={"notFuture":true,"message":"The collection time is in the future - check the clock"}
```

#### Protocol visit windows

```text
# on: visit_date_wk4 — target day 28, window -7/+7 days
@UVWINDOW={"from":"[visit_date_bl]","window":[21,35],"blockSave":"confirm"}

# on: visit_date_m6 — month 6 as weeks 24 to 28 after enrolment
@UVWINDOW={"from":"[enrol_date]","window":[24,28],"unit":"weeks"}

# on: visit_date in each follow-up event — counted from the baseline event (references enabled)
@UVWINDOW={"from":"[baseline_arm_1][visit_date]","window":[21,35]}
```

#### Order of events in one participant's history

```text
# on: diagnosis_date — on or after birth
@UVWINDOW={"from":"[dob]","window":[0,null],"notFuture":true}

# on: treatment_start — no earlier than diagnosis, at most 90 days after it
@UVWINDOW={"from":"[diagnosis_date]","window":[0,90]}

# on: screening_date — up to 28 days before enrolment
@UVWINDOW={"from":"[enrol_date]","window":[-28,0]}
```

#### Timed samples (datetime fields)

```text
# on: pk_sample_2h — between 90 minutes and 150 minutes after the dose
@UVWINDOW={"from":"[dose_given_at]","window":[90,150],"unit":"minutes"}

# on: result_reported_at — within 48 hours of collection
@UVWINDOW={"from":"[specimen_collected_at]","window":[0,48],"unit":"hours"}
```

#### Only for some records

```text
# on: visit_date — scheduled visits only; unscheduled visits may fall anywhere
@UVWINDOW={"from":"[visit_date_bl]","window":[21,35],"when":"[visit_type]='1'"}
```

### `@UVEXISTS` recipes

#### Results that point at registered specimens

```text
# on: result_specimen_id — the specimen must be registered, hard block
@UVEXISTS={"in":"[specimen_id]","blockSave":"hard","message":"Register this specimen on the collection form first."}

# on: aliquot_parent — the parent tube must be registered at the same site
@UVEXISTS={"in":"[specimen_id]","match":{"site_code":"[site]"},"blockSave":"confirm"}
```

#### Links between participants

```text
# on: mother_record_id — the mother is enrolled in this project
@UVEXISTS=record

# on: index_case_id — the index case is a participant of the same group
@UVEXISTS={"in":"[participant_id]","scope":"dag"}
```

#### Visits and kits

```text
# on: kit_returned — the returned kit was dispensed in this event
@UVEXISTS={"in":"[kit_dispensed]","scope":"event"}

# on: screening_number — issued at the screening event
@UVEXISTS={"in":"[screening_number]","event":"screening_arm_1"}
```

#### Surveys

```text
# on: voucher_code — the respondent enters a voucher the study issued (not an Identifier)
@UVEXISTS={"in":"[voucher_issued]","surveys":true,"blockSave":"hard"}
```

#### Another project

```text
# on: result_specimen_id — registered in the lab project, at the same site
@UVEXISTS={"in":"[specimen_id]","project":"lab","match":{"site_code":"[site]"},"blockSave":"hard"}

# on: screening_id — screened in the screening project's screening event
@UVEXISTS={"in":"[screening_id]","project":"screening","event":"screening_arm_1"}
```

### `@UVRANGE` recipes

#### Laboratory results

```text
# on: hb (g/dL) — usual range by sex, plausible range for everyone
@UVRANGE={"soft":[12,15.5],"hard":[3,25],"unit":"g/dL","when":"[sex]='2'"}
@UVRANGE={"soft":[13.5,17.5],"hard":[3,25],"unit":"g/dL","when":"[sex]='1'"}

# on: creatinine (µmol/L) — a note only for unusual values, a block for impossible ones
@UVRANGE={"soft":[45,110],"softBlock":"off","hard":[10,2000],"unit":"µmol/L"}

# on: viral_load_log — a value in log10 copies/mL, never below zero
@UVRANGE={"soft":[null,7],"hard":[0,9],"unit":"log10 copies/mL"}
```

#### Vital signs

```text
# on: temp_c — catches a temperature typed in °F
@UVRANGE={"soft":[36,37.5],"hard":[30,43],"unit":"°C","message":"Check the temperature and the unit (°C, not °F)."}

# on: sbp — no usual minimum
@UVRANGE={"soft":[null,140],"softBlock":"off","hard":[40,250],"unit":"mmHg"}

# on: resp_rate — by age group
@UVRANGE={"soft":[20,40],"hard":[5,100],"unit":"/min","when":"[age_years]<5"}
@UVRANGE={"soft":[12,24],"hard":[5,70],"unit":"/min"}
```

#### Anthropometry

```text
# on: weight_kg — ask before saving an implausible weight instead of refusing it
@UVRANGE={"soft":[30,150],"hard":[2,300],"hardBlock":"confirm","unit":"kg"}

# on: bmi (calc) — a calc never blocks; the note and the scan still flag it
@UVRANGE={"soft":[16,35],"hard":[10,60],"unit":"kg/m²"}
```

#### Child growth

```text
# on: weight_kg — weight-for-age for under-fives (weight-for-height needs its own field: one field takes one rule without "when")
@UVRANGE={"reference":"who-wfa","sex":"[sex]","male":"1","female":"2","age":{"dob":"[dob]","at":"[visit_date]"},"soft":[-3,3],"hard":[-6,5]}

# on: height_cm — the date of birth is saved once, at enrolment, in another event
@UVRANGE={"reference":"who-lhfa","sex":"[enrolment_arm_1][sex]","male":"1","female":"2","age":{"dob":"[enrolment_arm_1][dob]","at":"[visit_date]"},"soft":[-3,3],"hard":[-6,6]}

# on: muac_cm — no check for a child with oedema
@UVRANGE={"reference":"who-acfa","sex":"[sex]","male":"1","female":"2","age":{"dob":"[dob]","at":"[visit_date]"},"hard":[-5,5],"when":"[oedema]<>'1'"}
```

The limits -6 and 5 for weight-for-age, and ±6 for length/height-for-age, are
the ones WHO's software uses to flag a z-score as implausible.

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
@UVASSERT={"assert":"{t}>0","references":{"t":{"field":"dose","events":["b_arm_1","b_arm_1"],"aggregate":"sum"}}}   an event listed twice
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
data dictionary, or when a designer should not edit annotations. `@UVCHOICES`,
`@UVWINDOW` and `@UVEXISTS` exist only as tags.

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
| `caseSensitive` | boolean         | `false`    | Exact-case text in`when` and the duplicate check             |

### `@UVCHOICES`

| Key               | Type            | Default        | Notes                                                                  |
| ----------------- | --------------- | -------------- | ---------------------------------------------------------------------- |
| `show`          | list of strings | *(one of)*   | Offer only these choice codes; up to 200 codes                         |
| `hide`          | list of strings | *(one of)*   | Offer every code except these. Exactly one of `show`/`hide`        |
| `when`          | string          | *(none)*     | The branch applies while true; a tag without `when` is the fallback  |
| `message`       | string          | generic line   | Shown when a saved or selected code is not offered                     |
| `blockSave`     | string          | `off`        | `off`, `confirm`, `hard`                                         |
| `caseSensitive` | boolean         | `false`      | Exact-case text in `when`                                            |

### `@UVWINDOW`

| Key               | Type            | Default        | Notes                                                                  |
| ----------------- | --------------- | -------------- | ---------------------------------------------------------------------- |
| `from`          | string          | *(none)*     | One date field reference, e.g. `[visit_date_bl]`; another event needs event and instance references enabled |
| `window`        | list of two     | *(none)*     | `[earliest, latest]` whole units from `from`, both included; `null` leaves an end open |
| `unit`          | string          | `days`       | `days`, `weeks`; on datetime fields also `minutes`, `hours`. Needs `from` |
| `notFuture`     | boolean         | `false`      | The date may not be after today (a datetime: after now), by the server's clock |
| `when`          | string          | *(none)*     | Check only while true                                                  |
| `message`       | string          | generic line   | Replaces the line that names the allowed dates                         |
| `blockSave`     | string          | `off`        | `off`, `confirm`, `hard`; a `from` date on another form never blocks |
| `caseSensitive` | boolean         | `false`      | Exact-case text in `when`                                            |

### `@UVEXISTS`

| Key               | Type            | Default        | Notes                                                                  |
| ----------------- | --------------- | -------------- | ---------------------------------------------------------------------- |
| `in`            | string          | *(none)*     | `record`, or one field reference such as `[specimen_id]`; the shorthand `@UVEXISTS=[field]` sets it |
| `project`       | number or string | *(this project)* | Another project's id, or an alias from this project's settings. Not with `"scope":"dag"` or `"scope":"event"` |
| `event`         | string          | *(any)*      | One unique event name to look in. Not with `"scope":"event"` or `"in":"record"` |
| `scope`         | string          | `project`    | `project`, `dag` (this record's group), `event` (this entry's event)   |
| `match`         | object          | *(none)*     | `{"target_field":"[field of this record]"}`, up to 5 pairs, same entry as the searched value |
| `surveys`       | boolean         | `false`      | Also check on surveys; refused when a touched field is an Identifier   |
| `when`          | string          | *(none)*     | Check only while true                                                  |
| `message`       | string          | generic line   | Replaces the "not found" line                                          |
| `blockSave`     | string          | `off`        | `off`, `confirm`, `hard`; "could not check" never blocks          |
| `caseSensitive` | boolean         | `false`      | Exact-case text in `when` and in the lookup                          |

### `@UVRANGE`

| Key               | Type            | Default        | Notes                                                                  |
| ----------------- | --------------- | -------------- | ---------------------------------------------------------------------- |
| `soft`          | list of two     | *(none)*     | `[low, high]` usual range, both included; `null` leaves an end open; inside `hard` |
| `hard`          | list of two     | *(none)*     | `[low, high]` plausible range, both included; `null` leaves an end open |
| `softBlock`     | string          | `confirm`    | `off`, `confirm`: for a value outside `soft`                       |
| `hardBlock`     | string          | `hard`       | `confirm`, `hard`: for a value outside `hard` or not a number; a calc never blocks |
| `unit`          | string          | *(none)*     | Label shown after the limits in the notes, up to 20 characters         |
| `when`          | string          | *(none)*     | Check only while true; fields of this entry only                       |
| `message`       | string          | generic line   | Replaces the generated note for both levels                            |
| `caseSensitive` | boolean         | `false`      | Exact-case text in `when`                                            |

A limit is a JSON number, or a decimal in quotes for more digits than a JSON
number keeps. `blockSave` is refused.

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
| `events`         | list or `"arm"` | Several unique event names, each listed once, or `"arm"` together with `arm`              |
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
| Entries in one `alternates` list | 8 |
| `@UVWINDOW` bound, either side of `from` | 36,500 units |
| `@UVEXISTS` `match` pairs | 5 |
| `@UVEXISTS` lookups per user session | 60 a minute, shared with `@UVUNIQUE` |
| `@UVEXISTS` lookups into one other project, per browser session | 30 a minute (Control Center setting) |
| `@UVEXISTS` lookups one project answers for signed-in data entry in other projects | 1,200 a minute (Control Center setting) |
| `@UVEXISTS` lookups one project answers for post-save checks and scans of other projects | 1,200 a minute (same setting, its own count) |
| `@UVEXISTS` lookups one project answers for survey respondents of other projects | 120 a minute |
| `@UVRANGE` `unit` label | 20 characters |

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

# ── @UVWINDOW — dates within a window ────────────────────────────────────────
@UVWINDOW={"notFuture":true}                           not after today
@UVWINDOW={"from":"[visit_date_bl]","window":[21,35]}  21 to 35 days after baseline
@UVWINDOW={"from":"[dob]","window":[0,null]}           not before birth
@UVWINDOW={"from":"[enrol_date]","window":[-28,0]}     up to 28 days before enrolment
@UVWINDOW={"from":"[rand_date]","window":[10,14],"unit":"weeks","blockSave":"hard"}

# ── @UVEXISTS — values that must already exist ──────────────────────────────
@UVEXISTS=record                                       a record ID of this project
@UVEXISTS=[specimen_id]                                a value saved in specimen_id
@UVEXISTS={"in":"[specimen_id]","event":"enrolment_arm_1"}
@UVEXISTS={"in":"[specimen_id]","match":{"site_code":"[site]"},"blockSave":"hard"}
@UVEXISTS={"in":"[referral_id]","scope":"dag"}
@UVEXISTS={"in":"[specimen_id]","project":"lab"}       saved in another project (both must agree)

# ── @UVRANGE — numbers within plausible limits ──────────────────────────────
@UVRANGE={"soft":[12,17.5],"hard":[3,25],"unit":"g/dL"}            usual and plausible limits
@UVRANGE={"soft":[null,140],"softBlock":"off","hard":[40,250]}     note only for unusual values
@UVRANGE={"hard":[2,300],"hardBlock":"confirm"}                    ask instead of blocking
@UVRANGE={"soft":[12,15.5],"hard":[3,25],"when":"[sex]='2'"}       limits for one group

# ── Events, repeating instruments, bindings (feature must be enabled) ────────
@UVASSERT={"assert":"[weight]>=[baseline_arm_1][weight]"}
@UVREQUIRED={"when":"[previous-event-name][adverse_event]='1'"}
@UVASSERT={"assert":"[weight]>=[weight][first-instance]"}
@UVASSERT={"assert":"[result][any-instance]='1'"}
@UVASSERT={"assert":"{total}<=[dose_limit]","references":{"total":{"field":"dose","events":"arm","arm":1,"aggregate":"sum"}}}
@UVASSERT={"assert":"[result]>={t}","references":{"t":{"field":"threshold","event":"collection_arm_1","match":{"specimen_id":"[result_specimen_id]"}}}}
@UVASSERT={"assert":"{h}>=0 and {h}<=48","references":{"h":{"field":"result_time","type":"datetime","elapsedFrom":"[collected_at]","unit":"hours"}}}
@UVUNIQUE=record                                      no duplicate inside one record
@UVWINDOW={"from":"[baseline_arm_1][visit_date]","window":[21,35]}

# ── Letter case ──────────────────────────────────────────────────────────────
@UVASSERT={"assert":"[code]=[code_confirm]","caseSensitive":true}
@UVUNIQUE={"caseSensitive":true}                       "AB12" and "ab12" are different codes

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
