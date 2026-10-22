# Action Tag Validation Examples

A worked, parameter-by-parameter guide to the action tags of the **Universal
Field Validator** module (current implementation). Every tag is shown from its
simplest form to its most complete, with the meaning of each option and what the
person entering data will see.

**Where you type these:** the *Action Tags / Field Annotation* box of a field in the
Online Designer, or the `field_annotation` column of a data dictionary CSV. Tagging
50 fields is one spreadsheet column and one upload. Everything here is also available
in the module's Configure dialog, except `@UVCHOICES`, `@UVWINDOW`, `@UVEXISTS`, `@UVRANGE`, `@UVDELTA` and `@UVWRITEONCE`. The tags and
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
- [`@UVDELTA` — how far a number may change](#uvdelta--how-far-a-number-may-change)
- [`@UVWRITEONCE` — values entered once](#uvwriteonce--values-entered-once)
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
  - [`@UVDELTA` recipes](#uvdelta-recipes)
  - [Combination recipes](#combination-recipes)
- [Messages in several languages](#messages-in-several-languages)
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
| `@UVDELTA`    | A number has not changed more than a limit since an earlier value (the previous visit, baseline, another field)         | Text with no, integer or number validation, calc, slider       |
| `@UVWRITEONCE` | A value, once saved, is not changed or cleared except by named user roles                                              | Text, Notes, dropdown, radio, yes/no, true/false, slider, SQL  |

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

**`message` may come in several languages.** In a project that uses Multi-Language
Management, every tag that takes a `message` also takes a map of language id to text,
and the form or survey shows the text of the language on screen. A plain string works
as before. See [Messages in several languages](#messages-in-several-languages).

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
generic message. ISO dates and numbers compare correctly. A field validated as a number
(an integer, a number with any decimals, comma decimals included), a calc and a slider
are read as the number they hold: a saved `12,5` is 12.5 and `2.5E1` is 25. Two numbers
compare by value, so `=` treats `02` and `2` as equal; for an ID or a phone number, use
`"exact":true` (Level 2).

A bare `@UVASSERT` with no condition is a configuration error — there is nothing to
assert.

The quotes are optional. An unquoted condition is read whole, up to the end of the line
or the next action tag, as for [`@UVREQUIRED`](#level-4--compound-conditions):
`@UVASSERT=[end_date]>=[start_date] or [end_date]=''` checks both halves.

### Level 2 — confirm-a-value

```text
@UVASSERT={"assert":"[participant_id]=[participant_id_confirm]","exact":true}
```

"Type it twice" is an assertion like any other, with one option. Without `"exact":true`,
`=` compares numbers by value, so `00123` confirmed as `123`, `1002` as `1002.0` and
`+237677123456` as `237677123456` would all pass. With it, `=` and `<>` compare the two
entries character for character (spaces around them aside), letter case included.

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
@UVREQUIRED="[consent]='1' and ([site]='3' or [site]='4')"
```

`and`, `or`, `not` and parentheses mix freely; see
[the `when` condition language](#the-when-condition-language) for precedence.

A bare condition may be written without quotes. The module reads it whole, up to the end
of the line or the next action tag, so the two lines below require the field on the same
records:

```text
@UVREQUIRED="[consent]='1' and [site]<>'9'"
@UVREQUIRED=[consent]='1' and [site]<>'9'
```

Earlier releases stopped the unquoted form at the first space and checked only
`[consent]='1'`. Text after a complete condition is still ignored as a note. Text that
goes on with `and`, `or`, `not (` or `not [`, a comparison, or one of `&&`, `||`, `&`,
`|` and `!`, but does not finish the condition, is refused, since the module cannot tell
where the condition ends. For `&&`, `||` and `!` the error also names the word to write:

```text invalid
# refused: put the whole condition in quotes
@UVREQUIRED=[consent]='1' and see protocol section 4

# refused: uses "&&" — write "and" instead
@UVREQUIRED=[consent]='1' && [site]<>'9'
```

An unquoted condition ends with its line. A next line that starts with `and`, `or` or
another of those words is refused the same way, so quote a condition that runs over two
lines. A next line that starts with anything else, such as another action tag or a
note, is not read.

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
other record when it is entered or changed, over a CSRF-protected module AJAX call, with
no page reload. Typing clears the old answer; the page asks when the field loses focus,
and again at Save.

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

Any other bare value is a configuration error naming the three valid scopes. Under `dag`,
records in no group form one group of their own, and a new record made by a user in no
group is compared with them.

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
lowercased automatically. A `with` value is read from the same entry as the tagged field,
so each `with` field must be collected in an event that also collects the tagged field,
and must not sit on a repeating instrument the tagged field is not on. The rule is refused
otherwise.

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

`when` decides whether this record's value is checked. The other records are
searched whatever their own `when` says: a barcode saved on a record with
`specimen_collected` = 0 still counts as used. The form, the post-save audit and the
Validation scan all read it this way; the scan reports the record that was checked,
not the one whose `when` is false.

### Level 7 — look in other fields too (`also`)

```text
# on: study_id_typed — typed only when the scan fails
@UVUNIQUE={"also":["study_id_scan"],"message":"This ID is already registered","blockSave":"hard"}
# on: study_id_scan — the other direction
@UVUNIQUE={"also":["study_id_typed"]}
```

`also` lists other fields the value is looked for in. On `study_id_typed`, an ID
counts as used when another record holds it in `study_id_typed` or in
`study_id_scan`. Each tag checks one direction, so tag both fields when neither may
repeat the other. Without a `message`, staff see which field and record hold the
value: "This value is already recorded in field **study_id_scan** (record **12**)."

- Up to **5** fields, each listed once, none also in `with`. Each must exist, hold one
  value (not a checkbox, file or descriptive field), and store values the way the
  tagged field does: both text, both dates of the same kind, or both numbers with the
  same decimal mark. Validate both fields the same way.
- The scope and `with` apply to a match in an `also` field: under `"scope":"event"`
  only the same event is searched, and the `with` values must match in the same entry
  as the field the value was found in.
- The record being checked is never compared with itself. To allow typing only when
  nothing was scanned in the same record, add an assertion:

```text
# on: study_id_typed
@UVUNIQUE={"also":["study_id_scan"]} @UVASSERT={"assert":"[study_id_scan]=''","message":"An ID was scanned; leave this blank"}
```

- Every `@UVUNIQUE` tag on one field must list the same `also` fields, and `also`
  cannot be used with `"scope":"record"`.
- A placement that could never match is refused: an `also` field and a `with` field
  on two different repeating instruments (no record holds them side by side), an
  `also` field on an instrument no event collects, and under `"scope":"event"` an
  `also` field in no event with the tagged field. An `also` field on a form that does
  not repeat is found beside `with` values on a repeating one.
- Staff get an answer only when they may open the forms that hold the `also` fields,
  and the field is named only beside a record they may be told about: a record in
  another Data Access Group is named by neither. With `"surveys":true`, no `also`
  field may be an Identifier, and every `also` field must be on the tagged field's
  instrument, so a respondent is only told about fields of the survey they are
  filling in. The list of `also` fields is never sent to the page.
- The post-save audit and the Validation scan look in the `also` fields too. The scan
  reports the tagged field whose value was found elsewhere, never the `also` field.

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
- **Transport failures fail open.** A network error on a first check never traps a
  save. A failed answer to Save's second question leaves the earlier "already used"
  in force (see *Save asks again* above).

### `@UVUNIQUE` JSON keys

`with`, `also`, `scope`, `when`, `message`, `blockSave`, `surveys`, `caseSensitive`. Any
other key is a configuration error. `caseSensitive` governs the `when` and the duplicate
check alike, in the tagged field and in the `also` fields.

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
On a survey shown one section per page, the controlling field may sit on an earlier
page: the page is drawn with the condition settled from the saved answer. Clearing or correcting
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
another date, from today or from a fixed date, or against a calendar period such
as last month. It can also check that the date is not after today, or not before
it. JSON form only.

### Level 1 — not in the future, or not in the past

```text
# on: visit_date — a visit cannot be recorded before it happens
@UVWINDOW={"notFuture":true}

# on: next_appointment — an appointment cannot be booked in the past
@UVWINDOW={"notPast":true}
```

`notPast` refuses a date before today, and on a datetime field a time before
now. With both, only today passes. A saved value is judged against the day it
was saved (see [Which day counts as today](#which-day-counts-as-today)).

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

# on: visit_m6 — 6 to 7 calendar months after enrolment
@UVWINDOW={"from":"[enrol_date]","window":[6,7],"unit":"months"}
```

`null` leaves that end open; one end must be a number. A negative bound counts
back from the `from` date. `unit` is `days`, `weeks`, `months` or `years` on a
date field, and also `minutes` or `hours` on a datetime field. Bounds are whole
numbers up to 36,500 either way.

`months` and `years` count calendar months. When the day does not exist in the
target month, the last day of that month is used: one month after 2026-01-31 is
2026-02-28, and one year after 2024-02-29 is 2025-02-28. On a datetime field the
time of day is kept.

### Level 4 — counted from today, now or a fixed date

```text
# on: last_period_date — within the last 280 days
@UVWINDOW={"from":"today","window":[-280,0]}

# on: dose_time (datetime) — within the last 2 hours
@UVWINDOW={"from":"now","window":[-2,0],"unit":"hours"}

# on: consent_date — on or after the day the protocol opened
@UVWINDOW={"from":"2026-01-01","window":[0,null]}
```

`today` is the server's date and goes on a date field; `now` is the server's
time and goes on a datetime field. A fixed date is written Y-M-D, with a time
(`2026-01-01 08:00`) when the field holds dates with a time. A window counted
from `now` gets the same small margin as `notFuture` at both ends. A rule that
blocks a save (`blockSave` confirm or hard) is judged again when the form is
saved. A window that ends at `now` (`[-2,0]`) accepts a time filled in with
REDCap's Now button. `notPast`, or a window that starts at `now` (`[0,24]`),
refuses such a time once the form is saved more than about 2 minutes after the
click, on the form when the rule blocks, and in the post-save audit when it is
saved more than 12 minutes after. A period counted from `now` is the period holding the
server's date, with no margin, as a period is whole calendar days.

### Level 5 — calendar periods

```text
# on: stock_count_date — in the current month
@UVWINDOW={"period":"month"}

# on: report_date — last week, Monday to Sunday
@UVWINDOW={"period":"week","offset":-1}

# on: review_date — in one of the three months before this one
@UVWINDOW={"period":"month","offset":[-3,-1]}

# on: lab_date — in the same week as the visit, weeks starting on Sunday
@UVWINDOW={"period":"week","from":"[visit_date]","weekStart":"sunday"}
```

`period` is `week`, `month`, `quarter` or `year`. Without `from` it is the period
holding today; with `from` it is the period holding that date. `offset` moves it
by whole periods: `-1` is the one before, `[-3,-1]` the three before it, up to
1,200 either way. Weeks start on Monday (ISO 8601) unless the project setting
"First day of the week for @UVWINDOW" or the rule's `weekStart` says Sunday. A
period cannot be combined with `window`. The message names the period and its
dates in the field's format:

> ✗ This date must be in last month: 01-09-2026 to 30-09-2026.

### Level 6 — both checks, message and enforcement

```text
# on: screening_date — within a week of enrolment, never in the future, hard block
@UVWINDOW={"from":"[enrol_date]","window":[-7,7],"notFuture":true,
           "message":"Screening must be within a week of enrolment, and not in the future.",
           "blockSave":"hard"}
```

When both checks fail, the page shows the "future" message, because a date in the
future is the more likely typing slip.

### Level 7 — gated, or counted from another event

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

### Which day counts as today

A window counted from `today` or `now`, a period without `from`, and `notPast`
are judged against the day the value was saved, not the day someone looks at it.

- On the form, only a value that is new or changed is judged against today. A
  saved value shows nothing when the record is opened again, so a visit date
  entered last month does not turn red because a month has passed. `notFuture`
  still checks a saved value on the form, against today. A value filled in by
  `@TODAY` or `@NOW` counts as new.
- After a save, the module reads REDCap's own log to find when each value was
  saved, and judges the value against that day. REDCap writes its log entry for
  a save before the module's check runs, so a value just saved is found there.
  Saving another field of the record later does not change the verdict.
- The Validation scan does the same, and its detail line names the day:
  "Judged against 2026-10-02, when this value was saved."
- When the log does not show when a value was saved (it came in some way REDCap
  did not log, its field was renamed, or it was saved more than 5,000 saves of
  the record ago), that part of the rule is reported as not checked for the
  value, with the reason. It is never judged against today instead.
- A verdict that no save day can change needs no log read: with `notFuture`, a
  date after today; with `notPast` as the only part judged against a day (no
  `notFuture`, no window counted from today), a date from today on, or a date
  and time from now on. Every other value of these rules costs one read of the record's log,
  after each save and in the scan.
- `notFuture` uses the same day where the log shows it, so a scan still finds a
  date that was in the future when it was saved. Where the day is not known it
  uses today, which can miss such a date but never reports one that was fine.

Use these rules to check timeliness, that data is entered close to when it
happened. For a clinical window, count from a date in the record, such as the
interview date: data entered late from paper, or synced late from the REDCap
Mobile App, is saved days after the visit, and a window counted from today
would refuse it. `"blockSave":"confirm"` lets staff save such data after a
warning.

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
  advisory or not sent. A `from` in another event works the same way: the window
  part is advisory, and `notFuture` blocks as set. A `from` that resolves to a
  field on this page (`[visit_date_bl][current-instance]`, where both dates are
  on one form) blocks as set too.
- **A condition read when the page opened makes the whole rule advisory.** When
  a `when`, or the condition that picks a branch, reads a value from another
  form or event, the page judges with the value it read when it opened. Whether
  the rule applies may have changed since, so nothing in the rule blocks there,
  `notFuture` included. A count or set of entries, in this event or another,
  counts as such a value, even when it holds no entries or only this one.
- **Multi-page surveys.** The browser reads a `from` date only on the page being
  shown. If the `from` field is on an earlier page of the survey, the window is
  not checked while the respondent types; the post-save audit still checks it.
  Put both dates on one page to check the window as they are entered.
- **A `from` entry that does not exist yet** (no row in that event, no such
  instance) counts as a blank `from` date: nothing is checked until it is saved.
- **`notFuture` and `notPast` do not need the `from` date.** A `from` date that
  cannot be read, or a reference that cannot be resolved (an instance of a
  repeating form that the rule does not name), stops only the window part.
  `notFuture` and `notPast` are still checked on the page, after saving and in
  the scan; staff see why the window is not checked, and the post-save audit
  logs it as not checked.
- **A plain `from` field must share an event with the tagged field.** `[field]`
  reads the same event. If no event collects both forms, the rule is refused:
  name the event, as `[baseline_arm_1][visit_date]`.
- **Today is the server's date.** The page gets the server clock when it opens
  and moves it forward while it stays open, so a wrong computer clock cannot
  accept tomorrow's date and a form left open past midnight moves to the next
  day. A computer clock moved forward while the form is open moves it too; the
  post-save audit reads the server clock and still logs the date. The date is
  taken in the server's time zone unless the project setting **Time zone for
  @UVWINDOW "notFuture"** names another one, such as `Africa/Douala`. The same
  setting decides `notPast` and a `from` of `today` or `now`. A page left open
  across a daylight-saving change follows it.
- **A date and time gets a small margin.** On the page it may run up to 120
  seconds past the server's "now", plus how far the computer's clock runs ahead
  of or behind the server's, up to 10 minutes, so REDCap's Now button and `@NOW`,
  which use the computer's clock, are not refused on a computer whose clock is a
  little off. After saving, the module cannot see that computer's clock, so the
  post-save audit and the scan allow the most the page could have: 12 minutes
  either side of the moment the value was saved. The computer's time zone adds
  no margin, and a date has none.
- **One time zone per project.** `@NOW` and `@TODAY` fill in the computer's
  time, `@NOW-UTC` and `@TODAY-UTC` UTC, `@NOW-SERVER` and `@TODAY-SERVER` the
  server's. `notFuture` on a field filled by one of the last four is refused when
  that zone runs ahead of the rule's time zone at any time of the year: in New
  York, a UTC "now" is four hours in the future in summer and five in winter.
  `notPast` is refused when that zone runs behind, and a `from` of `today` or
  `now` when it differs either way. A rule that also has a window or period
  counted from a field or a fixed date keeps it; only the parts judged against
  the clock are dropped, and staff see why on the form.
- **Hours and minutes count wall-clock time.** `"window":[0,6],"unit":"hours"`
  compares the times as written, so across a daylight-saving change the window
  spans 5 or 7 hours of elapsed time.
- **The server checks every save.** The post-save audit logs a violation as
  `type: window` with reason `window-early`, `window-late`, `future` or `past`.
  `as_of` names the day the verdict was judged against, on a verdict that rests
  on that day. Saving only the form that holds the `from` date re-checks the
  windows counted from it.
- **The Validation scan** reports the same findings, with "Date outside allowed
  window", "Date in the future" or "Date in the past" in the Issue column and
  the missed bound in the detail line, such as "The window opens 21 days from
  [visit_date_bl]." or "The date must be in last month.". Where the log does not
  show when a value was saved, `notFuture` is judged against the day the scan
  runs. Each part of a durable scan reads the clock when that part runs, and
  stores with each finding the day it was judged against (`as_of`); the durable
  scan's report, which will show it, has not shipped yet.
- **Configure dialog:** none. `@UVWINDOW` exists only as an action tag.

### `@UVWINDOW` JSON keys

`from` (a field, `today`, `now` or a Y-M-D date), `window`, `unit`, `period`,
`offset`, `weekStart`, `notFuture`, `notPast`, `when`, `message`, `blockSave`,
`caseSensitive`, and `references` (named bindings that `when` reads as `{alias}`;
needs event and instance references). A rule needs `from` with `window`, a
`period`, `"notFuture":true` or `"notPast":true`. Any other key is a
configuration error.

These are refused when the dictionary is checked, since they depend on the
field: `"from":"now"` on a date field, `"from":"today"` with a `window` on a
datetime field (a `period` may count from today there), and a fixed date of the
other kind (`"2026-01-01 08:00"` on a date field) unless the rule names a period.

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

# Not a unit.
# refused: "unit" must be minutes, hours, days, weeks, months or years
@UVWINDOW={"from":"[visit_date_bl]","window":[1,3],"unit":"fortnights"}

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
# refused: needs a "from" date with a "window", a "period"
@UVWINDOW={"message":"Check the date"}

# A period and a window together. Use one or the other.
# refused: "period" and "window" cannot be combined
@UVWINDOW={"period":"month","window":[0,7]}

# Not a period.
# refused: "period" must be week, month, quarter or year
@UVWINDOW={"period":"fortnight"}

# An offset without a period.
# refused: "offset" only applies to a "period"
@UVWINDOW={"from":"[visit_date_bl]","window":[0,7],"offset":-1}

# First and last swapped.
# refused: the "offset" first period (-1) is after its last (-3)
@UVWINDOW={"period":"month","offset":[-1,-3]}

# More than 100 years of months.
# refused: "offset" is limited to 1200 periods
@UVWINDOW={"period":"month","offset":-1201}

# A week start on a month.
# refused: "weekStart" only applies to "period": "week"
@UVWINDOW={"period":"month","weekStart":"sunday"}

# Today with nothing to check.
# refused: "from" needs a "window" to check against
@UVWINDOW={"from":"today"}

# An impossible date.
# refused: is not a date
@UVWINDOW={"from":"2026-02-30","window":[0,null]}

# A quoted boolean.
# refused: "notPast" must be true or false
@UVWINDOW={"notPast":"true"}
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
production server unchanged; only the alias row differs. `in`, `event`, the
`match` targets and the fields of a `where` (Level 7) then name fields and
events of that project; the `match` values stay fields of this record. Three
switches must all be on:

1. **The server.** An administrator turns on "@UVEXISTS in other projects" in the
   module's Control Center settings. It is off by default.
2. **The searched project.** It must have this module enabled and list this
   project under "Projects that may look up values here", with the fields it may
   search or filter on (`record` for its record ID; the fields of a `where`, Level 7,
   count as searched) and who gets an answer:
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

### Level 6 — copy fields of the entry found (`return`)

```text
# on: result_specimen_id — fill the site and collection date of the registered specimen
@UVEXISTS={"in":"[specimen_id]","return":{"lab_site":"[result_site]","coll_date":"[result_coll_date]"}}

# the same, and the record the specimen was registered in
@UVEXISTS={"in":"[specimen_id]","return":{"record":"[source_record]","coll_date":"[result_coll_date]"}}

# on: mother_record_id — the mother's enrolment date, read from the enrolment event
@UVEXISTS={"in":"record","event":"enrolment_arm_1","return":{"enrol_date":"[mother_enrol_date]"}}

# from the lab project, when its agreement lets this project copy site_code
@UVEXISTS={"in":"[specimen_id]","project":"lab","return":{"site_code":"[result_site]"}}
```

`return` maps a field of the entry found (the key) to a field of this form
(the value), the same way round as `match`. `record` is the record ID of the
entry found. Up to 10 pairs. For `SP-1`, registered in record 12 with
`lab_site` 2 and `coll_date` 2026-03-01, the first rule writes `2` into
`result_site` and, for a `result_coll_date` shown as D-M-Y, `01-03-2026`.

- **The rule owns its fields.** A found value fills them. *Not found*, a blank
  value, a Missing Data Code, a `when` that is false and a value no branch
  applies to empty them. *Could not check* leaves them as they are. A branched
  rule owns the fields of every branch: the branch that applies fills its own
  and empties the others', and when it leaves its own as they are (*could not
  check*, entries that disagree) it leaves the others' too. Mark the fields
  `@READONLY`, since a person who types there is overwritten at the next
  judgement.
- **Several entries.** When more than one saved entry holds the value, the
  values are copied only when every entry holds the same ones. Otherwise
  nothing is copied, the page adds an amber line under the green *Found* ("3
  saved entries hold this value with different values to copy", counting the
  entries the user can see, agreeing or not), and the post-save audit logs a
  rule problem. Narrow the lookup with `match`. Copying `record` from two
  records is a disagreement too.
- **Data Access Groups.** A user in a group gets values only from entries of
  their own group. When REDCap lets the module see the other groups' records
  for that user, a value found only in another group is still *found*, nothing
  is copied, and the amber line says so. When REDCap confines the user's reads
  to their group, such a value reads as *could not check*, as for a rule
  without `return`.
- **Where the values come from.** The entry where `in` and every `match`
  matched: the same record, event and repeat instance. A copied field must be
  on the form of `in` or on a form that does not repeat; a field of another
  repeating form is refused, since the entry found has many instances of it.
  In a longitudinal project the form of each copied field must also be in
  every event the entry can be found in: the `event` named, or else every
  event that holds the form of `in`, and with `"scope":"event"` only those
  events that also hold this field's form. Otherwise the rule is refused,
  since the entry found there would have no value for it. The record ID,
  copied as `record` or by its field name, is in every event. With `"in":"record"`
  every copied field must be on a form that does not repeat, and a
  longitudinal project names the event the values are read from with
  `event`, which must not be a repeating event.
- **What a field here can take.** The value is copied as REDCap stores it, so
  the field here must be able to hold every value the field there can:

  | Field there | Field here |
  | ----------- | ---------- |
  | any value | a Text field without validation, or a Notes field |
  | dropdown, radio, yes/no, true/false | a choice field that has each of its codes, or a Text field without validation (it gets the code) |
  | a date, datetime or time | a Text field with the same kind of date or time; the order it shows the date in may differ |
  | a number | a Text field with number validation and the same decimal mark; when the field here fixes its decimal places (integer, `number_1dp`, `number_2dp` …), the field there must fix the same number of them, because REDCap's `number_2dp` takes exactly two places and `7.5` would fail it |
  | any other validation | a Text field with the same validation |

  The field here must be on this form, not this field, not a field the lookup
  reads, not the record ID, and not a calc, slider, SQL, checkbox, file or
  descriptive field, nor a field filled by `@CALCTEXT`, `@CALCDATE` or
  `@SETVALUE`, nor one that shows a widget keeping its own text (`@RICHTEXT`,
  a BioPortal ontology search). A field flagged Identifier there may go only
  into a field flagged Identifier here, so a de-identified export does not
  carry it.
- **One writer per field.** Two rules that write one field are both refused,
  whether both are `@UVEXISTS` rules or one is an `@UVRANGE` rule with
  `return`.
- **Rights.** A copied value is data, not only *found*. The page fills the
  fields only for a user who may open every form the lookup reads: the form of
  the searched field, of each `match` field and of each copied field. Anyone
  else gets *could not check*. The save checks the person who saved the same
  way: without those rights the fields keep their values and the audit logs a
  rule problem. A rule with `return` cannot use `"surveys":true`.
- **Surveys and saves without a user.** A survey respondent's save neither
  fills nor empties the fields, even when the form is also a survey. A save
  REDCap runs without a signed-in user (a scheduled job, for example) copies
  no value and asks for none. A blank value, a Missing Data Code, a `when`
  that is false, a value no branch applies to, or a branch without `return`
  still empties the fields; any other value leaves every field the rule owns
  as it is.
- **When the values are written.** The page fills the fields as it opens and
  after each answer. The fill made as the page opens does not mark the form as
  changed, though calculations and branching logic that read the fields still
  run; later fills also raise an input and a change event, as typing does.
  Save waits for a lookup on its way, even with `"blockSave":"off"` and on a
  `@READONLY` field. An answer that lands after the value changed (a date
  picker or an autocomplete pick can change it without an event) is dropped
  and the value asked again. A *found* answer is asked again when the field is
  judged 30 seconds or more after it; Save goes by the answer it has. After
  the save, the post-save audit asks again as the person who saved and writes
  the values that differ, together with any `@UVRANGE` returned values, in one
  save per event and instance of this record. A number field holding the same
  number spelled another way (`7.80` for `7.8`) is not rewritten; any other
  field is written exactly, so `007` replaces `7`.
- **A choice the page does not offer.** When `@HIDECHOICE` or `@UVCHOICES`
  took the option off the page, the page empties the dropdown or radio field
  instead of keeping an older code, and an amber line names the field. The
  save writes the code all the same: the field's choice list holds it.
- **A copy, not a link.** The values are copied when this record is judged. A
  later change to the entry found shows on the page when this form is opened,
  and reaches the saved record when this record is saved again.
- **The cost.** A lookup that copies values reads every saved value of the
  searched field and of the copied fields, the read a *not found* makes for
  any other rule, because a narrowed read can miss an entry spelled another
  way. The read is kept for the rest of the request and the signed-in user:
  an import of many records by one user reads the searched project once, and
  each record saved during it is read back in. A lookup that copies nothing
  (a survey save, a save with no signed-in user or by someone without the
  rights, the save switch below) looks the value up as a rule without
  `return` does.
- **No chains.** A rule that copies values cannot read a field that a rule
  copies or returns to: not as its own field, nor in `in`, `match` or `when`.
  The save works out every value before it writes any, so a chain would lag
  one save behind. The rule that reads is refused and names the rule that
  writes.
- **Turning the write on save off.** The project setting "@UVEXISTS returned
  values" (`exists-return-no-save`) keeps the page fill and writes nothing when
  a record is saved; the lookups made at the save then ask for no values. The
  Validation scan never writes.
- **Another project.** The searched project lists the fields others may copy,
  separately from the fields they may search ("Fields of this project whose
  values it may copy", `record` for its record ID), and only under "Only users
  who have rights". A rule that copies a field not on that list gets the same
  setup error as any other refusal there. A refusal that would describe how
  that project is built (a copied field on a repeating form or missing from an
  event, an event that repeats) gives one message instead: "project N cannot
  give the values "return" asks for". A choice field here that lacks a code of
  the field there is refused without naming the code. The page and the save
  check the user's rights in both projects: here the forms of this field and
  of each `match` field here, there the forms of the fields searched and
  copied.

### Level 7 — only entries that meet conditions (`where`)

```text
# on: result_specimen_id — registered in the lab project, consented, and not withdrawn
@UVEXISTS={"in":"[specimen_id]","project":"lab","where":"[consent]='1' and [status]<>'3'",
           "whereMessage":"This specimen is registered, but not consented or withdrawn."}

# on: index_case_id — an index case confirmed by culture or by Xpert
@UVEXISTS={"in":"[participant_id]","where":"[culture_result]='1' or [xpert_result]='2'"}

# on: screening_id — a record of the screening project found eligible at screening
@UVEXISTS={"in":"record","project":"screening","event":"screening_arm_1","where":"[eligible]='1'"}
```

`where` is a condition on the entry the lookup finds. The value must be saved,
and the entry that holds it must meet the condition. The condition takes the
language of `when`: `and`, `or`, `not` and parentheses, in any letter case,
with `not` binding tightest and `and` before `or`. One comparison, an AND, an
OR or a mix of them all work:

```text
# on: result_specimen_id — consented, and either still in the study or withdrawn after collection
@UVEXISTS={"in":"[specimen_id]","where":"[consent]='1' and ([status]<>'3' or [withdraw_date]>[coll_date])"}
```

Each line below gives the entry that holds `SP-1` and the answer:

```text expect
SP-1 with consent=1, status=1                                                    => found
SP-1 with consent=1                                                              => found
SP-1 with consent=0, status=1                                                    => filtered
SP-1 with consent=1, status=3                                                    => filtered
SP-1 with consent=1, status=3, withdraw_date=2026-05-02, coll_date=2026-03-01    => found
SP-1 with consent=1, status=3, withdraw_date=2026-02-20, coll_date=2026-03-01    => filtered
SP-1 with consent=1, status=3, coll_date=2026-03-01                              => filtered
```

The second line passes because a blank `status` is not `'3'`. The last fails
because a blank side of `>` gives no answer.

A comparison with a blank side of `<`, `>`, `<=` or `>=` never lets an entry
pass, and `not(...)` around it does not change that:

```text
# on: result_specimen_id — not a low-volume specimen
@UVEXISTS={"in":"[specimen_id]","where":"not([volume]<2)"}
```

```text expect
SP-1 with volume=5    => found
SP-1 with volume=1    => filtered
SP-1                  => filtered
```

To let an entry with no volume pass, say so: `"not([volume]<2) or [volume]=''"`.

A checkbox is tested one box at a time, as `[field(code)]`:

```text
# on: kit_id — a kit whose checklist has box 2 (sealed) ticked and box 4 (damaged) not
@UVEXISTS={"in":"[kit_number]","where":"[kit_check(2)]='1' and not([kit_check(4)]='1')"}
```

```text expect
KIT-9 with kit_check(2)=1                    => found
KIT-9 with kit_check(2)=1, kit_check(4)=1    => filtered
KIT-9                                        => filtered
```

Text compares with the letter case of the lookup. Letter case is ignored unless
the rule says `"caseSensitive":true`.

```text
# on: result_specimen_id — collected at one of two sites
@UVEXISTS={"in":"[specimen_id]","where":"[site_name]='North' OR [site_name]='South'"}
```

```text expect
SP-1 with site_name=north    => found
SP-1 with site_name=East     => filtered
```

```text
# the same, letter for letter
@UVEXISTS={"in":"[specimen_id]","where":"[site_name]='North' OR [site_name]='South'","caseSensitive":true}
```

```text expect
SP-1 with site_name=north    => filtered
SP-1 with site_name=North    => found
```

`where`, `match` and `when` answer different questions:

| Key | Asks | Its fields are |
| --- | ---- | -------------- |
| `when` | Does the rule apply to this record at all? | fields of this record |
| `match` | Does the entry found hold the same value as a field of this record? | a field there, paired with a field here |
| `where` | Does the entry found meet fixed conditions? | fields of the entry found |

To compare the entry found with this record, use `match`. A `where` never reads
this record.

- **Three answers become four.** The value is saved and an entry that holds it
  meets `where`: *found*. The value is saved, but no entry that holds it meets
  `where`: staff see the rule's `whereMessage`, or "This value is saved, but not
  in an entry that meets this rule's conditions." It shows red and is enforced
  as *not found* is, per `blockSave`. The Validation scan report explains it
  with the same `whereMessage`, or else with "The value is saved, but not in an
  entry that meets this rule's conditions.", never with `message`. A survey respondent sees the plain *not
  found* wording, so a survey tells nothing about entries it cannot see. A value
  saved nowhere is *not found*, with the rule's `message`, as before. *Could not
  check* is unchanged.
- **One entry.** `where` reads the entry where `in` and every `match` matched:
  one event row, or one repeat instance together with its event row. When
  several entries hold the value, one entry that passes is enough. A checkbox
  on a form that does not repeat, read from a repeat instance, reads the boxes
  of the event row.
- **How values compare.** As in `when`: `=`, `<>` (or `!=`), `<`, `>`, `<=`,
  `>=`. Two numbers compare by value, so `'007'` equals `'7'`. A comparison
  with a blank side of `<`, `>`, `<=` or `>=` is never true, and `not(...)`
  around it does not make it true. A blank value is not `'3'`, so
  `[status]<>'3'` passes an entry with no status; write
  `[status]<>'' and [status]<>'3'` to require one. Missing Data Codes are plain
  values: a saved `NA` is not blank.
- **Dates, times and decimal commas.** Dates compare as REDCap stores them, so
  a date is written `'2026-03-01'` and a datetime `'2026-03-01 08:30'`, whatever
  format the field shows. A time is written `'08:30'`. A number field with a
  decimal comma is read by value (a saved `3,5` is 3.5); write the number in
  `where` with a point. A field compared with a field must hold the same kind
  of value: two dates, two numbers with the same decimal mark, and so on.
- **What it can test.** Up to 10 fields of the entry found, each as `[field]`
  or, for a checkbox, `[field(code)]` with one of its codes. No event or
  instance references, no `[record]`, no `{alias}`. A field on a repeating form
  other than the form of `in` is refused, and in a longitudinal project the
  form of each field must be in every event the entry can be found in. The
  rule is refused rather than read blank, because a blank field passes `<>`.
- **With `"in":"record"`.** The entry is the record's row in the event that
  `event` names. A longitudinal project needs `event`, an event that does not
  repeat, and every `where` field must be on a form that does not repeat. A
  record ID typed in another letter case is judged too: the record counts when
  any record it finds passes.
- **Rights and Identifiers.** A `where` field is a field the lookup reads. A
  signed-in user needs the form of each one, as for the searched field.
  `"surveys":true` is refused when one of them is an Identifier.
- **With `return`.** Values are copied only from an entry that meets `where`.
  The fourth answer empties the fields the rule fills, as *not found* does.
- **The condition stays on the server.** A form page holds `whereMessage` but
  not `where`; a survey page holds neither, since a respondent never sees the
  fourth answer. `whereMessage` takes a map of language id to text, as `message` does
  (see [Messages in several languages](#messages-in-several-languages)).
- **Another project.** The agreement there lists the `where` fields among the
  fields this project may search or filter on. A refusal that would describe
  that project's fields, codes, forms or events gives one message instead:
  "project N cannot apply this "where" condition to the entry the lookup
  finds", followed by what each field must be. The `uv-exists-probe` line names
  the `where` fields with the searched field, and adds `filtered: true` for the
  fourth answer.
- **The audit and the scan.** The post-save audit logs the fourth answer with
  reason `filtered`. The Validation scan shows it as "Saved, but not where its
  conditions hold" in the Issue column.
- **The cost.** The `where` fields are read with the searched field. The
  narrowed read a signed-in user's lookup tries first may leave out rows
  `where` needs, so with `where` it only names candidate records: a second read
  of those records, with no filter logic, decides whether one passes. A value
  with no entry that passes gets the read of the whole field that any *not
  found* gets. A survey lookup with `where` skips the narrowed read and reads
  the whole field once, under the survey's read budget, so a found, a filtered
  and an absent value take the same reads. A record-ID lookup reads the `where`
  fields with the record ID. When that read finds no record that passes and
  the value could name a record another way (another letter case, or `007` for
  record 7), one more read takes every record's ID and `where` fields. The scan reads fewer records per chunk
  for each `where` field, so a chunk holds about as many values as one without
  `where`.

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
  counts as *could not check*. A *not found* the page has held for 30 seconds or
  more is asked again at Save when the rule can block it, since the value may
  have been saved elsewhere since. If the server cannot answer that second
  question, the earlier *not found* stands and is enforced.
- **Three answers.** *Found* (green), *not found* (red; enforced per `blockSave`),
  and *could not check* (amber; never blocks). A rule with `where` adds a fourth
  for staff, saved but not in an entry that meets it (Level 7), enforced as *not
  found* is. A failed read, a `match` field that
  is blank on another form, a busy server and missing rights all answer *could not
  check*, and staff see the reason. The post-save audit checks the value again,
  except when a `match` field is still blank: then there is nothing to check
  until that field is saved, and saving its form checks the lookup again.
- **Blank checks nothing.** A blank value is not looked up. A blank `match` field
  on the same page means there is nothing to narrow by yet, so nothing is asked.
- **Branches.** With several `when` branches the page sends the values its
  conditions read, and the server answers from that branch, also on a new record.
- **Where the rule looks stays on the server.** The page config holds the rule's
  message, `whereMessage` and enforcement but not `in`, `event`, `scope`, `match`
  or `where`; the server reads them from the stored rule. On a survey, a configuration error shows as a
  general notice, and the "no access" reason does not name a form or field.
- **Rights.** A signed-in user is answered only when they may open the forms that
  hold the searched field, the `match` fields and the `where` fields, and, for a
  rule with `return`, the copied fields. A record-ID lookup without `where` needs
  no extra form.
  At most 60 lookups a minute per user session, shared with `@UVUNIQUE`. A
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
  `type: exists` with reason `not-found`, or `filtered` for a value saved only in
  entries that fail `where`. A lookup that cannot be completed is logged as a rule
  problem, never as a pass.
- **The Validation scan** reads the searched field once per rule per scan request
  and checks every record against it, with "Not found in its source" in the Issue
  column, or "Saved, but not where its conditions hold" when the value is saved
  only in entries that fail `where`. It reads in chunks of records and stops with a rule problem, not a
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
  list a field the lookup searches or filters on. Neither its dictionary nor its data is read before
  then, so the error tells a designer nothing about that project. An `event`
  that is not one of its events gets the same error, because the agreement
  lists fields, not events. Field errors (a field missing there, a different
  kind of date) come only after the agreement passes. A withdrawn agreement
  shows up the same way on the form, and a survey shows its generic notice.
- **The record found there is not shown.** Staff get found / not found and,
  for *could not check*, a reason. A rule that copies `record` writes it into
  this record only when the agreement lists `record` among the fields it may
  copy.
- **Lookups are logged in the searched project.** Once the module can read that
  project's agreement, its module log gets one `uv-exists-probe` line per
  lookup, answered or refused: the asking project, the channel (staff, survey,
  audit), the user ("survey" for a respondent, "(no user)" for a save nobody
  was signed in for, such as a data import), the fields read (the searched
  field, the `match` targets and the `where` fields; `record` for a record ID),
  the result, `filtered: true` when
  the value was saved only in entries that fail `where`, and the
  value as a keyed hash under that project's key. A rule that ignores letter
  case hashes the value in lower case and adds `case: ignored`, so `SP-1` and
  `sp-1` leave the same hash. The value is left out when
  that project's "How to log invalid values" is "none" or "off", and is never
  logged raw. When a *not found* needed a read of that project's record IDs,
  the line says `extra_read: record ids`. Nothing is written there while the
  Control Center switch is off or the module is not enabled in that project.
  Over a budget, only the first refused lookup of the minute is logged, so a
  flood of refused lookups is not a flood of log lines. A lookup that asks for
  values (from a data-entry form, for a signed-in person, or at a save that
  writes them) adds `returned` (the fields the rule copies), and, when values
  were handed over, `copied_from` (the record they came from); with "How to
  log invalid values" set to "none" that record is kept as a keyed hash
  (`copied_from_hmac`), and with "off" it is left out. A lookup that asks for
  none (from a survey page, or at a save that writes nothing) adds neither.
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

`in`, `project`, `event`, `scope`, `match`, `return`, `where`, `surveys`,
`when`, `message`, `whereMessage`, `blockSave` and `caseSensitive`. Any other
key is a configuration error.

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

# A record ID belongs to the whole record. "event" only says where copied values are read
# ("return") or where "where" is tested.
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

# "return" maps a field there to a field of this form.
# refused: "return" must be an object
@UVEXISTS={"in":"[specimen_id]","return":["[result_site]"]}

# The value is a field reference.
# refused: must be one field reference of this form
@UVEXISTS={"in":"[specimen_id]","return":{"lab_site":"result_site"}}

# Two values into one field.
# refused: to the same field
@UVEXISTS={"in":"[specimen_id]","return":{"lab_site":"[result_site]","site_code":"[result_site]"}}

# A field the lookup reads cannot be written.
# refused: which this lookup reads or searches
@UVEXISTS={"in":"[specimen_id]","match":{"site_code":"[site]"},"return":{"lab_site":"[site]"}}

# Copied values need rights a survey respondent does not have.
# refused: cannot be used with "surveys"
@UVEXISTS={"in":"[specimen_id]","surveys":true,"return":{"lab_site":"[result_site]"}}

# "where" is a condition, written as text.
# refused: "where" must be a condition on fields of the entry found
@UVEXISTS={"in":"[specimen_id]","where":{"consent":"1"}}

# It must test a field of the entry found.
# refused: "where" must test at least one field
@UVEXISTS={"in":"[specimen_id]","where":"'1'='1'"}

# "&&" is not part of the condition language.
# refused: write "and" instead
@UVEXISTS={"in":"[specimen_id]","where":"[consent]='1' && [status]<>'3'"}

# The entry found has one event and instance: no event references.
# refused: takes no event or instance references
@UVEXISTS={"in":"[specimen_id]","where":"[enrolment_arm_1][consent]='1'"}

# The record ID is what "in":"record" looks up.
# refused: "where" cannot test [record]
@UVEXISTS={"in":"[specimen_id]","where":"[record]<>''"}

# At most 10 fields.
# refused: at most 10 fields
@UVEXISTS={"in":"[specimen_id]","where":"[a1]='1' or [a2]='1' or [a3]='1' or [a4]='1' or [a5]='1' or [a6]='1' or [a7]='1' or [a8]='1' or [a9]='1' or [a10]='1' or [a11]='1'"}

# "whereMessage" is shown when "where" fails, so it needs one.
# refused: "whereMessage" needs "where"
@UVEXISTS={"in":"[specimen_id]","whereMessage":"Not eligible."}
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

Several `@UVRANGE` tags with fixed limits on one field branch, and the one
whose `when` is true applies (see
[Branching](#branching--several-tags-of-the-same-kind)). One of them may leave
out `when` to give the limits for everyone else. Tags that name different
growth references are checked side by side instead (Level 5). While `sex` is
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
@UVRANGE={"reference":"who-wfa","sex":"[sex]","male":"1","female":"2","age":{"dob":"[dob]","at":"[visit_date]"},"soft":[-3,3],"hard":[-6,5]}
```

A weight of 6.6 kg for a boy of exactly one year has a weight-for-age
z-score of -3.40, so the rule shows:

> ⚠ This value is lower than usual (z-score -3.40 on Weight-for-age, WHO 2006 (birth to 5 years), in kg; expected z-score -3 to 3).

The same rule, with the inputs written after the value:

```text
# on: weight_kg
@UVRANGE={"reference":"who-wfa","sex":"[sex]","male":"1","female":"2","age":{"dob":"[dob]","at":"[visit_date]"},"soft":[-3,3],"hard":[-6,5]}
```

```text expect
6.6 with sex=1, dob=2025-01-10, visit_date=2026-01-10    => soft-low, z -3.40
9.6 with sex=1, dob=2025-01-10, visit_date=2026-01-10    => ok, z -0.04
6.6 with sex=2, dob=2025-01-10, visit_date=2026-01-10    => ok, z -2.56
2.5 with sex=1, dob=2025-01-10, visit_date=2026-01-10    => hard-low, z -8.43
6.6 with sex=1, visit_date=2026-01-10                    => not evaluated
abc with sex=1, dob=2025-01-10, visit_date=2026-01-10    => not-a-number
0 with sex=1, dob=2025-01-10, visit_date=2026-01-10      => not-positive
```

| Key | Value |
| --- | ----- |
| `reference` | The id of the reference (table below) |
| `sex` | The field holding the sex, e.g. `"[sex]"` |
| `male`, `female` | The codes that field stores for male and for female. Letter case is ignored unless `caseSensitive` is true |
| `age` | `{"dob":"[dob]","at":"[visit_date]"}` (two date fields), `{"days":"[age_days]"}` or `{"months":"[age_months]"}` |
| `by` | For a reference by length or height: the field holding it, in cm |
| `soft`, `hard` | z-score limits, from -20 to 20 |
| `plausible` | `[low, high]` in the reference's unit, judged when no z-score can be worked out (see below) |
| `measureScale` | A number the typed value is multiplied by before the z-score, from 0.000001 to 1000000 (0.001 for grams typed into a reference in kg) |

The references that ship with the module:

| `reference` | Measurement | Read by | Valid for |
| ----------- | ----------- | ------- | --------- |
| `who-wfa` | weight (kg) | age | birth to 5 years |
| `who-lhfa` | length or height (cm) | age | birth to 5 years |
| `who-bfa` | BMI (kg/m²) | age | birth to 5 years |
| `who-hcfa` | head circumference (cm) | age | birth to 5 years |
| `who-acfa` | mid-upper arm circumference (cm) | age | 91 days to 5 years |
| `who-ssfa` | subscapular skinfold (mm) | age | 91 days to 5 years |
| `who-tsfa` | triceps skinfold (mm) | age | 91 days to 5 years |
| `who-wfl` | weight (kg) | length, measured lying | 45 to 110 cm |
| `who-wfh` | weight (kg) | height, measured standing | 65 to 120 cm |
| `who2007-wfa` | weight (kg) | age | 5 to 10 years |
| `who2007-hfa` | height (cm) | age | 5 to 19 years |
| `who2007-bfa` | BMI (kg/m²) | age | 5 to 19 years |

The z-score is the one WHO's own software (the `anthro` and `anthroplus` R
packages) computes: the same tables, the same restricted method beyond 3 SD for
the weight, BMI, arm circumference and skinfold indicators, and the same
rounding to two decimals (half away from zero). The module's tests compare 2,880 points with WHO's
code. An administrator can add other references, such as CDC 2000 or a national
one ([Level 7](#level-7--a-growth-reference-of-your-own) shows how, step by step).

#### WHO's limits for each indicator

WHO's software flags a z-score beyond these limits as implausible. They make
good `hard` limits:

| Indicator | `reference` | `hard` |
| --------- | ----------- | ------ |
| Weight-for-age, birth to 5 years | `who-wfa` | `[-6,5]` |
| Length/height-for-age | `who-lhfa` | `[-6,6]` |
| Weight-for-length, weight-for-height | `who-wfl`, `who-wfh` | `[-5,5]` |
| BMI-for-age, birth to 5 years | `who-bfa` | `[-5,5]` |
| Head circumference-for-age | `who-hcfa` | `[-5,5]` |
| Arm circumference, skinfolds | `who-acfa`, `who-ssfa`, `who-tsfa` | `[-5,5]` |
| Weight-for-age, 5 to 10 years | `who2007-wfa` | `[-6,5]` |
| Height-for-age, 5 to 19 years | `who2007-hfa` | `[-6,6]` |
| BMI-for-age, 5 to 19 years | `who2007-bfa` | `[-5,5]` |

One rule per indicator, each on the field holding that measurement:

```text
# on: hc_cm — head circumference-for-age
@UVRANGE={"reference":"who-hcfa","sex":"[sex]","male":"1","female":"2","age":{"dob":"[dob]","at":"[visit_date]"},"soft":[-3,3],"hard":[-5,5]}

# on: tsf_mm — triceps skinfold-for-age, from 91 days
@UVRANGE={"reference":"who-tsfa","sex":"[sex]","male":"1","female":"2","age":{"dob":"[dob]","at":"[visit_date]"},"soft":[-3,3],"hard":[-5,5]}

# on: ssf_mm — subscapular skinfold-for-age, from 91 days
@UVRANGE={"reference":"who-ssfa","sex":"[sex]","male":"1","female":"2","age":{"dob":"[dob]","at":"[visit_date]"},"soft":[-3,3],"hard":[-5,5]}

# on: weight_kg — weight-for-age, 5 to 10 years
@UVRANGE={"reference":"who2007-wfa","sex":"[sex]","male":"1","female":"2","age":{"dob":"[dob]","at":"[visit_date]"},"soft":[-3,3],"hard":[-6,5]}

# on: height_cm — height-for-age, 5 to 19 years
@UVRANGE={"reference":"who2007-hfa","sex":"[sex]","male":"1","female":"2","age":{"dob":"[dob]","at":"[visit_date]"},"soft":[-3,3],"hard":[-6,6]}
```

Length/height-for-age, with a few values for a child of one year:

```text
# on: length_cm — length/height-for-age
@UVRANGE={"reference":"who-lhfa","sex":"[sex]","male":"1","female":"2","age":{"dob":"[dob]","at":"[visit_date]"},"soft":[-3,3],"hard":[-6,6]}
```

```text expect
75 with sex=1, dob=2025-01-10, visit_date=2026-01-10     => ok, z -0.31
68 with sex=1, dob=2025-01-10, visit_date=2026-01-10     => soft-low, z -3.26
60 with sex=2, dob=2025-01-10, visit_date=2026-01-10     => soft-low, z -5.44
```

Arm circumference and the skinfolds start at 91 days (3 months). A younger
child's value is not checked:

```text
# on: muac_cm — mid-upper arm circumference-for-age, from 91 days
@UVRANGE={"reference":"who-acfa","sex":"[sex]","male":"1","female":"2","age":{"dob":"[dob]","at":"[visit_date]"},"soft":[-3,3],"hard":[-5,5]}
```

```text expect
14.5 with sex=1, dob=2025-01-10, visit_date=2026-01-10   => ok, z -0.13
11.5 with sex=1, dob=2025-01-10, visit_date=2026-01-10   => soft-low, z -3.09
12 with sex=1, dob=2025-01-10, visit_date=2025-03-11     => not evaluated
```

The 5 to 19 year references read the age the same way:

```text
# on: bmi — BMI-for-age, 5 to 19 years
@UVRANGE={"reference":"who2007-bfa","sex":"[sex]","male":"1","female":"2","age":{"months":"[age_months]"},"soft":[-3,3],"hard":[-5,5]}
```

```text expect
16.5 with sex=2, age_months=120   => ok, z -0.06
13 with sex=2, age_months=120     => ok, z -2.40
13 with sex=2, age_months=48      => not evaluated
```

#### Which soft limits

WHO's cut-offs of -2 and -3 classify children; they do not find typing
slips. In a clinic for malnourished children many values sit below -2, and a
`soft` range of `[-2,2]` asks "save anyway?" for each of them. For data
quality, use `"soft":[-3,3]`, or keep `[-2,2]` with `"softBlock":"off"` so
the amber note shows without the question. Where an extreme value may be a
real child in danger, `"hardBlock":"confirm"` lets staff save it after a
second look instead of refusing it:

```text
# on: weight_kg — a note from -2, a question beyond WHO's limits
@UVRANGE={"reference":"who-wfa","sex":"[sex]","male":"1","female":"2","age":{"dob":"[dob]","at":"[visit_date]"},"soft":[-2,2],"softBlock":"off","hard":[-6,5],"hardBlock":"confirm"}
```

#### BMI from a calc

```text
# on: bmi — a calc field: round([weight_kg] / (([length_cm] / 100) ^ 2), 2)
@UVRANGE={"reference":"who-bfa","sex":"[sex]","male":"1","female":"2","age":{"dob":"[dob]","at":"[visit_date]"},"soft":[-3,3],"hard":[-5,5]}
```

```text expect
17 with sex=1, dob=2025-01-10, visit_date=2026-01-10     => ok, z 0.15
14 with sex=1, dob=2025-01-10, visit_date=2026-01-10     => ok, z -2.36
25 with sex=1, dob=2025-01-10, visit_date=2026-01-10     => soft-high, z 4.86
```

A calc field never blocks a save, since nobody typed its value. Its note still shows, and
the post-save audit and the scan report it. Put the limits that should stop a
typing slip on the typed fields (weight-for-age, length-for-age), and keep the
BMI rule as a second look. At one year, half a unit of BMI is about 0.37 of a
z-score, so round the BMI to two decimals or not at all.

#### The age

The age can come from two dates, or from one field holding days or months:

```text
# on: weight_kg — the age from two dates (whole days between them)
@UVRANGE={"reference":"who-wfa","sex":"[sex]","male":"1","female":"2","age":{"dob":"[dob]","at":"[visit_date]"},"soft":[-3,3],"hard":[-6,5]}

# on: weight_kg — the age in months, from a calc that keeps the part month: datediff([dob], [visit_date], "M")
@UVRANGE={"reference":"who-wfa","sex":"[sex]","male":"1","female":"2","age":{"months":"[age_months]"},"soft":[-3,3],"hard":[-6,5]}

# on: weight_kg — the age in days, from a calc: datediff([dob], [visit_date], "d")
@UVRANGE={"reference":"who-wfa","sex":"[sex]","male":"1","female":"2","age":{"days":"[age_days]"},"soft":[-3,3],"hard":[-6,5]}
```

```text expect
4.0 with sex=1, age_days=31     => ok, z -0.86
4.0 with sex=1, age_days=59     => ok, z -2.49
4.0 with sex=1, age_days=1900   => not evaluated
4.0 with sex=1, age_days=-3     => not evaluated
```

Two dates are best, since the module counts the days itself. A month is 30.4375
days, as WHO counts it; REDCap's `datediff` with `"M"` counts 30.44, which
moves the age by less than a quarter of a day at 5 years. Never round the
months. The WHO 2006 tables have a row per day, and an age in whole months
puts every child of 1 month and 0 to 30 days on the same row. For a boy
weighing 4.0 kg:

| Age | z-score at the age in days | z-score at "1 month" |
| --- | -------------------------- | -------------------- |
| 31 days | -0.86 | -0.79 |
| 45 days | -1.75 | -0.79 |
| 59 days | -2.49 | -0.79 |

So a Text field with integer validation is refused as `months` on a
reference read by day; give `dob` and `at`, days, or a number field or calc
that keeps the part month.

#### Weight-for-length, weight-for-height and weight-for-age on one field

WHO uses length, measured lying, under 2 years (731 days), and height,
measured standing, from 2 years. WHO's software adds 0.7 cm to a length
measured standing under 2 years and takes 0.7 cm from a height measured
lying from 2 years. The module does not know how the child was measured, so
two calcs make the correction:

| Field | Kind | Value |
| ----- | ---- | ----- |
| `measured` | radio | `1, Lying` and `2, Standing` |
| `length_cm` | Text, number | the length or height as measured |
| `age_days` | calc | `datediff([dob], [visit_date], "d")` |
| `len_wfl` | calc | `if([measured] = '2', [length_cm] + 0.7, [length_cm])` |
| `ht_wfh` | calc | `if([measured] = '1', [length_cm] - 0.7, [length_cm])` |

```text
# on: weight_kg — weight-for-length under 2 years, weight-for-height from 2 years, weight-for-age at every age
@UVRANGE={"reference":"who-wfl","sex":"[sex]","male":"1","female":"2","by":"[len_wfl]","soft":[-3,3],"hard":[-5,5],"when":"[age_days] < 731"}
@UVRANGE={"reference":"who-wfh","sex":"[sex]","male":"1","female":"2","by":"[ht_wfh]","soft":[-3,3],"hard":[-5,5],"when":"[age_days] >= 731"}
@UVRANGE={"reference":"who-wfa","sex":"[sex]","male":"1","female":"2","age":{"days":"[age_days]"},"soft":[-3,3],"hard":[-6,5]}
```

Tags that name different references all apply, each when its own `when` is
true (or always, without one). The worst result decides the note and the
save, and the note has one line per reference. Tags that name the same
reference branch as in Level 3: the one whose `when` is true applies, and two
true at once are a branch conflict, which leaves the whole field unchecked,
every reference included. Each finding in the module log and the
Validation scan names its reference.

Apart from a conflict, each reference chooses its branch on its own. A `when`
that reads a value saved on another form makes only its own reference's notes
advisory. A `when` that names a value the server cannot read (in another event,
or on another repeating form) leaves only its own reference unchecked. The page
says so on a line that names the reference, the audit and the scan log it as a
configuration problem, and the other references are still judged. In the same
way, a reference whose inputs or `when` name another event or instance never
blocks on the page, as such a rule alone does, while the fixed limits or other
references beside it block as they say.

A boy of 300 days measured standing at 75.0 cm and weighing 7.5 kg has a
corrected length of 75.7 cm and a weight-for-length z-score of -3.16, lower
than usual. Without the correction it would be -2.98.

```text
# on: weight_kg — weight-for-length by the corrected length
@UVRANGE={"reference":"who-wfl","sex":"[sex]","male":"1","female":"2","by":"[len_wfl]","soft":[-3,3],"hard":[-5,5]}
```

```text expect
7.5 with sex=1, len_wfl=75.7    => soft-low, z -3.16
7.5 with sex=1, len_wfl=75      => ok, z -2.98
9.5 with sex=1, len_wfl=75      => ok, z 0.00
7.5 with sex=1, len_wfl=40      => not evaluated
```

WHO gives no weight-based z-score for a child with oedema; skip those rules
with `when`, e.g. `"when":"[oedema]<>'1'"`.

#### Under 5 and over 5 on one field

`who-wfa` ends just before 60 months (1826.25 days) and `who2007-wfa` starts
there. With the age in days, a `when` on each picks the one that covers the
child:

```text
# on: weight_kg — weight-for-age from birth to 10 years
@UVRANGE={"reference":"who-wfa","sex":"[sex]","male":"1","female":"2","age":{"days":"[age_days]"},"soft":[-3,3],"hard":[-6,5],"when":"[age_days] <= 1826"}
@UVRANGE={"reference":"who2007-wfa","sex":"[sex]","male":"1","female":"2","age":{"days":"[age_days]"},"soft":[-3,3],"hard":[-6,5],"when":"[age_days] >= 1827"}
```

Without the `when`s both apply, and each shows a grey "not checked" note for
an age outside its own range. The two references meet closely but not
exactly. A girl of 14 kg has a z-score of -1.86 at 1826 days on WHO 2006,
and -1.91 at 60 months on WHO 2007.

#### How many references a page carries

Each reference a form uses travels to the page as a table of 3 to 95 KB. A
page carries at most four, a number the administrator can change
(`pageTables`, see `data/references/README.md`). A table counts as one when
the sex is a field on the page. When the sex is a value saved on another
form, such as an enrolment form, only that sex's rows travel and the table
counts as one half. When the age or length is a saved value, only the few
rows around it travel and the table counts as nothing. A rule whose `when`
the saved values already make false carries no table.

A rule past the limit is checked when the record is saved, with a note
saying so. The page still checks that the value is a number above 0. The
weight rules above use four references (`who-wfa`, `who-wfl`, `who-wfh` and
`who2007-wfa`), as many as a page carries by default; with the sex on an
enrolment form they count as two.

#### Surveys

A growth rule works on a survey. Inputs on the survey page are read as the
respondent types. Inputs on another form, or in another event, are not sent
to a survey. The page then checks only that the value is a number above 0
and shows no z-score note, and the post-save audit checks the z-score once
the survey is submitted. With an input in another event, the survey does not
hold the save even for a value that is not a number.

On a multi-page survey the page reads an input only on the page being shown.
With the date of birth on an earlier page, the z-score is not worked out while
the respondent types (a `plausible` range still applies), and the post-save
audit checks it after submission. Put the inputs on the page of the
measurement.

#### Date of birth in an enrolment event

```text
# on: weight_kg — on a visit; the sex and the date of birth were saved at enrolment
@UVRANGE={"reference":"who-wfa","sex":"[enrolment_arm_1][sex]","male":"1","female":"2","age":{"dob":"[enrolment_arm_1][dob]","at":"[visit_date]"},"soft":[-3,3],"hard":[-6,5]}
```

This needs event and instance references turned on in the project settings.
The page reads the saved sex and date of birth when it opens and shows the
z-score, but such a rule never blocks there. The post-save audit and the scan
check every entry.

#### A reference of your own

Any reference published as L, M and S values per sex, or as a mean and a
standard deviation, can be added without changing the module's code: CDC
2000, a national reference, a reference by gestational age. The
administrator adds its table to a folder on the server once, and every
project then names it like a WHO reference:

```text
# on: weight_kg — CDC 2000 weight-for-age, 2 to 20 years, added by the administrator as "cdc-wfa"
@UVRANGE={"reference":"cdc-wfa","sex":"[sex]","male":"1","female":"2","age":{"dob":"[dob]","at":"[visit_date]"},"soft":[-3,3],"hard":[-5,5]}
```

[Level 7](#level-7--a-growth-reference-of-your-own) walks through it step by
step with three worked tables: one by month, one by length, and one built
from a mean and an SD per week of gestation.

#### A blank date of birth (`plausible`)

A z-score needs every input. With `plausible`, a range in the reference's
unit judges the value when no z-score can be worked out, so a 95 kg infant
with a blank date of birth is still caught:

```text
# on: weight_kg — and 0.5 to 30 kg whenever there is no z-score
@UVRANGE={"reference":"who-wfa","sex":"[sex]","male":"1","female":"2","age":{"dob":"[dob]","at":"[visit_date]"},"soft":[-3,3],"hard":[-6,5],"plausible":[0.5,30]}
```

```text expect
95 with sex=1, visit_date=2026-01-10                   => plausible-high
0.2 with visit_date=2026-01-10                         => plausible-low
6.6 with sex=1, visit_date=2026-01-10                  => not evaluated
6.6 with sex=1, dob=2025-01-10, visit_date=2026-01-10  => soft-low, z -3.40
```

> ✗ This value is above the plausible range (allowed 0.5 to 30 kg; no z-score on Weight-for-age, WHO 2006 (birth to 5 years): the date of birth or the date of the measurement is blank).

It applies when the sex is blank or neither code, a date is blank or a saved
date cannot be read, or the age or length is blank, not a number or outside
the reference. A date still being typed on the page gives no verdict at all.
A value outside the range is implausible and follows `hardBlock`, except that
a rule whose inputs come from another form or event never blocks on the page.
Once the z-score can be worked out, `plausible` plays no part. It is not
judged on a page that was not sent an input (a survey, a form the user cannot
see), nor for a rule past the page's table limit; the post-save audit judges
those.

#### Grams (`measureScale`)

```text
# on: weight_g — the weight in grams, against a reference in kg
@UVRANGE={"reference":"who-wfa","sex":"[sex]","male":"1","female":"2","age":{"dob":"[dob]","at":"[visit_date]"},"soft":[-3,3],"hard":[-6,5],"measureScale":0.001}
```

```text expect
6600 with sex=1, dob=2025-01-10, visit_date=2026-01-10  => soft-low, z -3.40
9600 with sex=1, dob=2025-01-10, visit_date=2026-01-10  => ok, z -0.04
```

The typed value is multiplied by `measureScale` before the z-score, and the
note says so ("z-score -3.40 on Weight-for-age, WHO 2006 (birth to 5 years),
in kg after × 0.001"). A `plausible` range on such a rule is in the
reference's unit, after the scale: `[0.5,30]` means 500 to 30,000 g.

#### Corrected age for preterm infants

The WHO standards describe children born at term. For a child born preterm,
many programmes use the age corrected for prematurity in the first two years.
A calc gives it, with `ga_days` the gestational age at birth in days (weeks ×
7 + days):

```text
# on: weight_kg — the age corrected for prematurity, from a calc:
#   datediff([dob], [visit_date], "d") - if([ga_days] < 259, 280 - [ga_days], 0)
@UVRANGE={"reference":"who-wfa","sex":"[sex]","male":"1","female":"2","age":{"days":"[age_corr_days]"},"soft":[-3,3],"hard":[-6,5]}
```

```text expect
4.5 with sex=1, age_corr_days=64     => ok, z -1.83
4.5 with sex=1, age_corr_days=120    => soft-low, z -3.67
4.5 with sex=1, age_corr_days=-10    => not evaluated
```

A boy born at 32 weeks (224 days) who is 120 days old and weighs 4.5 kg has a
corrected age of 64 days (120 days less the 56 between his birth and his due
date) and a z-score of -1.83. By his
age since birth it would be -3.67. Before the due date the corrected age is
below 0, and nothing is checked.

#### A sex typed as M or F

```text
# on: weight_kg — the sex in a Text field, M or F
@UVRANGE={"reference":"who-wfa","sex":"[sex_text]","male":"M","female":"F","age":{"dob":"[dob]","at":"[visit_date]"},"soft":[-3,3],"hard":[-6,5]}
```

```text expect
6.6 with sex_text=m, dob=2025-01-10, visit_date=2026-01-10    => soft-low, z -3.40
6.6 with sex_text=F, dob=2025-01-10, visit_date=2026-01-10    => ok, z -2.56
6.6 with sex_text=boy, dob=2025-01-10, visit_date=2026-01-10  => not evaluated
```

Sex codes match without regard to letter case, so `m` is male. Add
`"caseSensitive":true` to match them exactly. On a radio or dropdown, `male`
and `female` must be among its codes, and codes that differ only in letter
case need `caseSensitive`.

#### What the page, the audit and the scan do

- **The age.** From two dates, the age is the whole number of days between
  them (a date with a time counts its date only). A month is 30.4375 days.
  The WHO 2006 tables have a row per day and use the nearest day; the WHO 2007
  tables and the weight-for-length and weight-for-height tables interpolate
  between rows. On the WHO 2006 tables an age between two days is read at the
  nearest day, so arm circumference and the skinfolds start at 90.5 days.
- **What checks nothing.** A blank input, a sex code that is neither `male`
  nor `female`, an age or a length outside the reference, a measurement
  dated before the birth, and a saved date the rule cannot read ("the date of
  birth is not a date this rule can read"). A `plausible` range still judges
  such a value. On a data entry form a grey note tells the user why ("Not
  checked against Weight-for-age, WHO 2006 (birth to 5 years): the age is
  outside the reference (0 to under 1826.25 days)."); a survey shows nothing.
  A missing data code in an input counts as blank. The post-save audit logs
  each such value as `uvalidate-not-evaluated`, with the rule, the field, the
  reason and the reference, also when a `plausible` range judged it (a value
  outside that range is logged as a finding too). The Validation scan does not
  count them yet.
- **What is implausible whatever the inputs say.** A value that is not a number,
  and a measurement of 0 or below (reason `not-positive`).
- **Inputs on another form** are read when the page opens. The z-score note
  does not block on the page, as for a `when` on another form, but a value
  that is not a number or is 0 or below still follows `hardBlock`. A survey,
  and a user without rights to that form, are not sent the values: the page
  checks only that the value is a number above 0, and holds the save when it
  is not. Staff see a note that the z-score is checked when the record is
  saved; a survey shows no note. The post-save audit checks it.
- **Inputs in another event** need event and instance references turned on in
  the project settings, e.g. `"dob":"[enrolment_arm_1][dob]"`. Such a rule never
  blocks on the page; the post-save audit and the scan check every entry.
- **What the dictionary must hold.** The reference must exist, and its axis
  decides between `age` and `by`. `sex` is a radio, dropdown, yes/no,
  true/false, Text, calc or SQL field. On a radio or dropdown, `male` and
  `female` must be among its codes, each matching only one of them once letter
  case is ignored; yes/no and true/false store 1 and 0; the codes of a Text,
  calc or SQL field are not checked. `dob` and
  `at` are date or datetime fields; `days`, `months` and `by` are Text fields
  with integer or number validation, or calcs. A whole-number `months` field
  on a reference read by day is refused. No input may be the measured field
  itself.
- **Limits the reference can reach.** Near 0, some rows give no z-score below
  a floor, and some (in a table without `adjust`, with L below 0) none above a
  ceiling. At 5 years, `who-tsfa` never scores below -6.97 for a girl, so a hard
  low limit of -7 would not hold back even 0.1 mm. A `soft` or `hard` limit beyond such a
  bound anywhere in the reference is a configuration error that names the row
  and the value a limit must clear ("a low limit must be above -6.97"). Every shipped reference takes soft
  limits of -3 to 3 and hard limits of -6 to 6.
- **The z-score travels with each finding where values are kept raw.** The
  post-save audit's log entry and the Validation scan always carry the
  reference. The z-score, with the sex and the age, gives the measurement back,
  and with the measurement and the sex it gives back the age, so a date of
  birth. It goes only where values are kept raw. The log entry has it when the
  audit log setting keeps raw values. The scan has it when it stores raw values
  (not under the identifier-redacted mode), and the detail line then reads "Its
  z-score is -3.40 on the growth reference who-wfa."

### Level 6 — write the z-score, the percentile, the bounds or a reference value into fields

A growth rule can also write what it worked out into Text fields of the same
form, so the z-score or the usual range is on the record and in exports:

```text
# on: weight_kg — the z-score and the usual range of the weight, written beside it
@UVRANGE={"reference":"who-wfa","sex":"[sex]","male":"1","female":"2","age":{"dob":"[dob]","at":"[visit_date]"},"soft":[-2,2],"hard":[-5,5],"return":{"z":"[wfa_z]","lower":"[wfa_low]","upper":"[wfa_high]","range":"[wfa_range]"}}
```

For a boy of exactly one year weighing 9.6 kg:

| Field | Written | What it is |
| ----- | ------- | ---------- |
| `wfa_z` | `-0.04` | the z-score of the measurement |
| `wfa_low` | `7.8` | the lightest weight, to one decimal, whose z-score passes -2, the low `soft` limit |
| `wfa_high` | `11.9` | the heaviest weight, to one decimal, whose z-score passes 2, the high `soft` limit |
| `wfa_range` | `7.8 to 11.9 kg` | the two as text, with the reference's unit |

For a girl of the same age the range is `7.1 to 11.5 kg`, and with the weight
still blank the bounds are written and `wfa_z` stays empty.

A weight typed in grams is read against the table in kg with `measureScale`,
and the bounds are then in grams. `unit` names the unit `range` is written in:

```text
# on: weight_g, the weight in grams against the WHO table in kg
@UVRANGE={"reference":"who-wfa","sex":"[sex]","male":"1","female":"2","age":{"dob":"[dob]","at":"[visit_date]"},"soft":[-2,2],"hard":[-5,5],"measureScale":0.001,"unit":"g","return":{"range":"[wfa_range_g]"}}
```

For the same boy weighing 9600 g, `wfa_range_g` reads `7737 to 11989 g`.
Without `unit` it reads `7737 to 11989`, with no unit, since the table's kg
would be wrong.

The percentile, and the weight at a given z-score or percentile, go to fields
the same way:

```text
# on: weight_kg — the percentile, and the weights at -2 SD, the median and the 97th percentile
@UVRANGE={"reference":"who-wfa","sex":"[sex]","male":"1","female":"2","age":{"dob":"[dob]","at":"[visit_date]"},"return":{"percentile":"[wfa_pct]","valueAtZ:-2":"[wfa_sd_m2]","valueAtPercentile:50":"[wfa_p50]","valueAtPercentile:97":"[wfa_p97]"}}
```

For the same boy of one year weighing 9.6 kg:

| Field | Written | What it is |
| ----- | ------- | ---------- |
| `wfa_pct` | `48.3` | the percentile of the measurement, to one decimal |
| `wfa_sd_m2` | `7.7` | the weight whose z-score is -2 (7.74 kg), to one decimal |
| `wfa_p50` | `9.6` | the median weight for his age and sex |
| `wfa_p97` | `11.8` | the weight at the 97th percentile |

For a girl of the same age they read `71.6`, `7.0`, `8.9` and `11.3`. With
the weight blank, `wfa_pct` stays empty and the three weights are still
written, so the form shows them before anything is measured. This rule has
no `soft` or `hard`: none of the four is made from a limit.

- **The percentile** is 100 times the normal distribution function of the
  z-score, the unrounded one, so it does not move in steps of 0.01 SD. Beside
  a z-score written `-0.04` this boy's percentile reads `48.3`, the
  percentile of -0.0437, not `48.4`. It is written with `percentileDecimals`
  decimals and rounded half away from zero.
- **The ends of the scale.** The percentile stays one unit of its last
  decimal inside 0 and 100: 1 to 99 with no decimals, 0.1 to 99.9 with one,
  0.001 to 99.999 with three. So at one decimal `0.1` stands for every
  percentile below 0.15, the ones under 0.1 included, and `99.9` for every
  one from 99.85 up. A boy of 6.6 kg (z-score -3.40, a percentile of 0.03)
  gets `0.1`.
- **A value at a z-score** names the z-score after a colon:
  `valueAtZ:-2`, `valueAtZ:2.5`. The z-score lies from -6 to 6, with at most
  two decimals. The value is the measurement whose z-score the rule works
  out as that number. A z-score no measurement above 0 has leaves the field
  empty.
- **Beyond ±3 SD.** On the references WHO reads with its restricted method
  beyond ±3 SD (weight, BMI, arm circumference and the skinfolds), the
  percentile and the values at a z-score or percentile use that method, as
  the z-score does. On length or height and head circumference, and on a
  reference of your own with `"adjust":"none"`, they use the LMS formula
  throughout.
- **A value at a percentile** names the percentile after a colon:
  `valueAtPercentile:97`, `valueAtPercentile:2.5`. It lies from 0.1 to 99.9,
  with at most two decimals. The percentile is turned into its z-score
  without rounding, so `valueAtPercentile:50` is the median and equals
  `valueAtZ:0`.
- **Rounded, not the last that passes.** The values at a z-score or
  percentile are written with `decimals` and rounded to the nearest unit of
  their last decimal, so 7.74 kg is written `7.7`. `lower` and `upper` are
  the last values the rule accepts, so beside a low `soft` limit of -2 the
  two can differ: for this boy `valueAtZ:-2` is `7.7`, and `lower` is `7.8`,
  since 7.7 kg scores -2.05 and is flagged.
- **A tie rounds up.** The rounding first reads the value to 15
  significant digits, the most a computer's number holds for any decimal,
  so a median of 9.165 kg is written `9.17` at two decimals, although the
  computer holds 9.165 as a number just below it. The page and the save
  round a tie the same way.
- **What is not written.** A value that rounds to 0, and one that would
  take more than 10^15 units of its last decimal, leave the field empty, as
  a bound does.
- **Up to 16 values, each key once.** One rule returns at most 16 values,
  each to its own field. `valueAtZ:2` and `valueAtZ:2.0` are one key
  written two ways, as are `valueAtZ:0` and `valueAtZ:-0`, and a rule with
  both is refused.
- **Keys that shape nothing are refused.** `returnLimits` applies to
  `lower`, `upper` and `range`, `decimals` to those and to the values at a
  z-score or percentile, and `percentileDecimals` to `percentile`. A rule
  that writes one of these keys, and neither returns nor shows in a page
  table (Level 8) any of the values it applies to, is refused, so
  `"return":{"z":"[wfa_z]"},"decimals":3` is a configuration error.

| Key | Value | Default |
| --- | ----- | ------- |
| `return` | an object from a value to a field, `{"z":"[wfa_z]","valueAtPercentile:97":"[wfa_p97]"}`. The values are `z`, `lower`, `upper`, `range`, `percentile`, `valueAtZ:<z-score>` and `valueAtPercentile:<percentile>`, at most 16, each to its own field | none |
| `returnLimits` | `soft` or `hard`: the limits `lower`, `upper` and `range` are made from | `soft` |
| `decimals` | 0 to 6: the decimals of `lower`, `upper`, the bounds in `range`, and the values at a z-score or percentile | the measured field's: `number_Ndp` N (`number_1dp` 1, `number_2dp` 2, and their comma forms), `integer` 0, otherwise 1 |
| `percentileDecimals` | 0 to 3: the decimals of `percentile` | 1 |

- **The last value that passes.** `lower` is the smallest and `upper` the
  largest measurement, written with `decimals`, whose two-decimal z-score the
  rule accepts. A measurement shown inside the range passes, and one unit of
  the last decimal beyond a bound is flagged, for a limit with three decimals
  too. When the band is narrower than one unit of the last decimal, the two
  bounds cross and `range` stays empty.
  With only a low limit `range` reads `at least 7.8 kg`, with only a high one
  `at most 11.9 kg`. A limit the table cannot reach (a z-score no measurement
  has) leaves its bound and the range empty.
- **Limits only for the values made from them.** A rule that returns only
  `z`, `percentile` and values at a z-score or percentile needs no `soft` or
  `hard`. `lower`, `upper` and `range` need the limit they are made from, and
  the rule is refused without it.
- **The fields.** Text fields on the same form as the measured field, so in
  the same event and instance. Not the measured field, an input of the rule,
  or the record ID, and no two values to one field. A `range` field takes no
  validation. The other values take none, number validation without a place
  count, or a place count equal to the decimals they are written with (two
  for `z`, `percentileDecimals` for `percentile`, `decimals` for the rest):
  REDCap's `number_2dp` takes exactly two places, so it refuses `7.5` as it
  refuses `7.512`. A value written with no decimals (`decimals` 0, the
  default on an integer measured field, or `"percentileDecimals":0` for the
  percentile) may go into an integer field; `z` never does. A field with comma-decimal
  validation gets a comma, and a field without validation takes the mark of
  the measured field. Nor a field filled by `@CALCTEXT`, `@CALCDATE` or
  `@SETVALUE`, or one that shows a widget keeping its own text (`@RICHTEXT`,
  a BioPortal ontology search). Two rules
  cannot return to one field, and both are refused, naming each other. Two
  references on one field are judged at the same time, so each fills the
  fields of its own branches and they cannot share one.
- **No chains.** A rule that returns values cannot read a returned field, its
  own or another rule's, in its `when`, an input or its measurement. The save
  works out every returned value from the record as it was saved, so a chain
  would always lag one save behind. The rule that reads is refused and names
  the rule that writes.
- **When the values are written.** On the page: as the page opens, and again
  after the measurement or an input changes, whatever the verdict (a weight
  outside the limits still gets its z-score). The first fill does not mark the
  form as changed. After a change REDCap hears an input and a change event, so
  its calculations and branching logic that read the field run. On save: the
  post-save audit works out the same values from the saved record and writes
  those that differ, for a save of a form or a survey, with one save per entry
  (event and instance), the entry being saved first. An import or an API write
  leaves the fields as they were, because REDCap does not run the save hook for
  a Data Import Tool import; the Validation scan reports such a field as
  "Returned value out of date". An entry
  REDCap refuses to write (a locked form) is logged, and the others are still
  written. The write appears in the record's log as an update by the user who
  saved. It is not audited again. The rules that return values are checked
  first, those with event or instance references too, and their values are
  written before any other rule is checked, so a rule that reads a returned
  field (an `@UVREQUIRED` on the z-score field) checks the new value. A rule
  with an input in another event is checked in every visit it applies to, so
  saving the enrolment form after correcting the date of birth refills the
  fields of every visit.
- **The rule owns its fields.** When there is nothing to report the fields are
  emptied: a blank sex, an age or length outside the reference, a saved date
  that is not a date, a `when` that is false, a branch that does not apply. A branched rule owns the fields of
  every branch, so the active branch fills its own and empties the others'. A
  field that is blank is left alone, and a field holding the same number
  spelled another way (`7.80` for `7.8`) is not rewritten. Mark the fields
  `@READONLY`: a person who types there is overwritten at the next judgement.
- **What is left as it is.** When an input is on a form the page may not show,
  on a survey page that does not carry the field, or past the page's table
  cap, the page does not write and the save does. A reference the page or
  the save cannot check keeps its fields, while another reference on the same
  field still fills its own. A form that has no data yet in that event or
  instance is filled once it has.
- **Turning the write on save off.** The project setting "@UVRANGE returned
  values" (`range-return-no-save`) keeps the page fill and writes nothing when
  a record is saved. The Validation scan never writes.

### Level 7 — a growth reference of your own

The WHO tables that ship cover most child growth checks. A programme may also
need a national reference, CDC 2000, a reference by gestational age, or a newer
edition of a WHO table. Any reference published as L, M and S values for each
sex, or as a mean and a standard deviation, can be added without changing the
module's code. The REDCap administrator adds it once per server. After that,
every project on the server names it in `"reference"` as it names `who-wfa`.

This level builds a folder of three references from three tables, each shaped
the way such references are published. The tables are in the module's
`data/references/examples/` folder. Their numbers are made up: use them to
practise, never for real children.

| Reference | Source table | What it shows |
| --------- | ------------ | ------------- |
| `example-hfa` | `hfa-national.csv` | height-for-age with L, M and S for each whole month, read between months |
| `example-wfl` | `wfl-national.csv` | weight-for-length with a row every 0.5 cm |
| `example-bwga` | `bwga-weekly.csv` | birth weight for gestational age, as a mean and an SD for each week |

#### How the module reads a reference

A reference is a list of rows for males and a list for females. Each row
holds three numbers, L, M and S, for one position on the reference's axis: an
age (in days or months), a length (in cm, measured lying) or a height (in cm,
measured standing). For a measurement y read at a row, the z-score is

```text
z = ((y / M)^L - 1) / (L × S)     when L is not 0
z = ln(y / M) / S                 when L is 0
```

With L = 1 this is (y − M) / (M × S): M is the mean and M × S is the standard
deviation. That is how a reference given as a mean and an SD is written
(Example 3).

The rows sit on an even grid: one row every 1/`scale` of the axis unit, with
no row missing between the first and the last, and both sexes starting at the
same position. The WHO 2006 weight-for-age table has a row per day (scale 1,
days); WHO weight-for-length has a row per millimetre (scale 10, cm).

Three things make a reference usable:

1. **The table**, a JSON file in the module's `uv-lms-1` format.
   `convert.php`, in the module's `data/references` folder, writes it from a
   CSV or tab-separated file.
2. **An entry in an `index.json`** beside the table. It names the table and
   its SHA-256, the unit, the axis, and how an age or a length finds its row.
3. **The folder** holding both, named in the module's system settings.

#### Example 1 — height-for-age by month

**Step 1. Save the table as CSV**, with a header row and one row per sex and
month. `hfa-national.csv` begins:

```text
sex,agemos,l,m,s
1,60,1,110,0.042
1,61,1,110.55,0.042
1,62,1,111.1,0.042
```

`convert.php` finds the columns `sex`, `l`, `m` and `s` by name, in any order
and letter case, and `--x` names the column of the axis. A table with other
names takes `--sex`, `--l`, `--m` and `--s`; one that codes the sexes other
than 1 and 2 takes `--male` and `--female` (for example `--male M --female F`).

**Step 2. Convert it.** From the module's `data/references` folder:

```bash
php convert.php --in examples/hfa-national.csv --out examples/example-hfa.json --x agemos
```

It prints the table's SHA-256 and an index entry to start from:

```text
wrote examples/example-hfa.json: 13 male and 13 female rows, x 60 to 72
sha256 b58f4fda71110943cdc86c88774cbdcf62d8d0fc12cd6f917d0210befda86644
index.json entry to start from (edit title, measure, unit, axis, axisUnit, lookup, valid, adjust):
{
    "title": "",
    "source": "",
    "file": "example-hfa.json",
    "sha256": "b58f4fda71110943cdc86c88774cbdcf62d8d0fc12cd6f917d0210befda86644",
    "measure": "",
    "unit": "",
    "axis": "age",
    "axisUnit": "days",
    "lookup": "round",
    "valid": {
        "min": "60",
        "max": "72"
    },
    "adjust": "none"
}
```

It refuses a table it cannot convert and says why. A row off the grid, a
second row for one position, a sex code that is neither `--male` nor
`--female`, an M or S at or below 0, an S below 0.0001, or an L that is not 0
but smaller than 0.000001 is named by its line; a gap in a sex's rows, or
sexes that start at different positions, is named by the sex.

**Step 3. Write the index entry.** Edit the printed entry and give it the id
rules will name:

```json
"example-hfa": {
  "title": "Height-for-age, example national reference (5 to 6 years)",
  "file": "example-hfa.json",
  "sha256": "b58f4fda71110943cdc86c88774cbdcf62d8d0fc12cd6f917d0210befda86644",
  "measure": "height",
  "unit": "cm",
  "axis": "age",
  "axisUnit": "months",
  "lookup": "linear",
  "valid": {"min": "60", "max": "72"},
  "adjust": "none"
}
```

- **The id** (`example-hfa`) is 1 to 64 lower-case letters, digits, `.`, `_`
  or `-`. Rules name it in `"reference"`.
- **`title`** is shown to staff in the notes, and printed by `--check`.
- **`measure` and `unit`** describe the measurement, here height in cm.
- **`axisUnit`** is `months`, since the rows are months. `convert.php` always
  starts from `days`.
- **`lookup`** is `linear`: an age of 66.5 months is read halfway between the
  rows for 66 and 67, as the WHO 2007 tables are. `round` would read the
  nearest month, and `floor` the month completed.
- **`valid`** is the range checked, 60 to 72 months. Outside it the page shows
  a grey "not checked" note.
- **`adjust`** is `none`. Use `who-restricted` only for a WHO table of a
  weight-based indicator.
- **`source`**, which `convert.php` prints empty, is a note for people: fill it
  in or delete it, the module does not read it.

**Step 4. Put the folder on the REDCap server.** Copy the table and the
`index.json` to a folder outside the module folder, so that an upgrade of the
module does not remove them: `/var/redcap/growth` on Linux, for example, or
`D:\redcap\growth` on Windows. The account the web server runs as must be able
to read it. The full `index.json` of this level is shown under Example 3.

**Step 5. Point the module at it.** In Control Center, External Modules,
Universal Field Validator, Configure, set "@UVRANGE growth references —
folder of extra growth references" to the folder's full path. Only an
administrator can, and it applies to every project on the server. Its
references are added to the WHO ones.

**Step 6. Check one value with the module's own code** before any rule uses
the reference. `--folder` names the folder, `--x` is the position in the axis
unit and `--y` the measurement:

```bash
php convert.php --check example-hfa --folder examples --sex male --x 66 --y 115
```

```text
Height-for-age, example national reference (5 to 6 years), male, age 66 months: L 1, M 113.3, S 0.042
z-score 0.36 for 115 cm
```

By hand: (115 − 113.3) / (113.3 × 0.042) = 0.357, which rounds to 0.36. With
a real reference, compare the result with a z-score from the publisher's own
software or tables; a difference means a column, a scale or a unit is wrong.

**Step 7. Use it in a rule.** Any form on the server can now name it:

```text
# on: height_cm — height-for-age against the example reference, 5 to 6 years
@UVRANGE={"reference":"example-hfa","sex":"[sex]","male":"1","female":"2","age":{"months":"[age_months]"},"soft":[-3,3],"hard":[-6,6]}
```

```text expect
115 with sex=1, age_months=66     => ok, z 0.36
115 with sex=1, age_months=66.5   => ok, z 0.30
108 with sex=2, age_months=72     => ok, z -1.65
99 with sex=1, age_months=66      => soft-low, z -3.01
115 with sex=1, age_months=73     => not evaluated
```

`"age":{"dob":"[dob]","at":"[visit_date]"}` works too: the module counts the
days between the dates and divides by 30.4375 for a reference by month.

**Step 8. Enter a test record** with the sex, age and height you checked in
step 6. The note under the field names the reference by its `title`, and the
z-score is the one `--check` printed.

#### Example 2 — weight-for-length every half centimetre

`wfl-national.csv` has a row every 0.5 cm, so there are 2 rows per cm:

```text
Sex,Length,L,M,S
1,45,-0.3521,2.441,0.0918
1,45.5,-0.3521,2.5315,0.0914
1,46,-0.3521,2.622,0.091
```

`--scale` gives the rows per unit of the axis:

```bash
php convert.php --in examples/wfl-national.csv --out examples/example-wfl.json --x Length --scale 2
```

In the entry, `"axis": "length"` and `"axisUnit": "cm"`, and `"lookup":
"linear"`, as WHO reads its own weight-for-length table. A length of 47.3 cm
lies three fifths of the way from the row for 47.0 to the row for 47.5, and L,
M and S are read three fifths of the way between the two rows:

```bash
php convert.php --check example-wfl --folder examples --sex female --x 47.3 --y 2.5
```

```text
Weight-for-length, example national reference (45 to 50 cm), female, length 47.3 cm: L -0.3833, M 2.8704, S 0.08846
z-score -1.60 for 2.5 kg
```

A reference by length or height takes `by` in place of `age`:

```text
# on: weight_kg — weight-for-length against the example reference
@UVRANGE={"reference":"example-wfl","sex":"[sex]","male":"1","female":"2","by":"[length_cm]","soft":[-3,3],"hard":[-5,5]}
```

```text expect
2.5 with sex=2, length_cm=47.3   => ok, z -1.60
2.9 with sex=1, length_cm=47.5   => ok, z 0.02
2.0 with sex=1, length_cm=47.5   => soft-low, z -4.39
2.5 with sex=1, length_cm=44     => not evaluated
```

A reference by height, measured standing, is the same with `"axis":
"height"`. WHO's 0.7 cm correction between length and height is shown under
Level 5.

#### Example 3 — a mean and an SD per week of gestation

Birth weight references are often published as a mean and a standard
deviation for each completed week of gestation. `bwga-weekly.csv`:

```text
sex,ga_weeks,mean,sd
1,37,2.95,0.42
1,38,3.15,0.42
1,39,3.31,0.43
1,40,3.44,0.44
1,41,3.54,0.45
1,42,3.58,0.46
2,37,2.85,0.41
```

Two changes turn it into a table the module reads:

1. **Write each row as L, M and S.** L is 1, M is the mean and S is the SD
   divided by the mean. With L = 1 the z-score is (y − mean) / SD.
2. **Give the axis a unit the module knows.** An age is in days or months, and
   the rows sit on an even grid, so a row per week becomes seven rows, one per
   day. Days 259 to 265 (37 weeks and 0 to 6 days) all take the values of week
   37.

In a spreadsheet, with the weekly table in columns A to D (sex, weeks, mean,
SD) and one row per day below it, the day is `weeks × 7 + 0` to `weeks × 7 + 6`,
L is `1`, M is the mean and S is `=ROUND(D2/C2, 6)`. Save the result as CSV.
`bwga-daily.csv` begins:

```text
sex,ga_days,l,m,s
1,259,1,2.95,0.142373
1,260,1,2.95,0.142373
```

```bash
php convert.php --in examples/bwga-daily.csv --out examples/example-bwga.json --x ga_days
```

In the entry, `"axisUnit": "days"`, `"lookup": "floor"` (each row stands for a
whole day, so 286.4 days reads the row for day 286), and `"valid": {"min":
"259", "below": "301"}`, which is 37 weeks 0 days up to the end of 42 weeks 6
days.

```bash
php convert.php --check example-bwga --folder examples --sex male --x 286 --y 2.2
```

```text
Birth weight for gestational age, example (37 to 42 weeks), male, age 286 days: L 1, M 3.44, S 0.127907
z-score -2.82 for 2.2 kg
```

By hand: 286 days is 40 weeks and 6 days, and week 40 for boys has a mean of
3.44 kg and an SD of 0.44 kg, so (2.2 − 3.44) / 0.44 = −2.82.

The rule gives the gestational age in days with `age`'s `days`, from a calc
field. `dob` and `at` would give the age since birth, which is not what this
reference is read by:

```text
# on: birth_weight_kg — birth weight for gestational age; ga_days is a calc: [ga_weeks]*7 + [ga_extra_days]
@UVRANGE={"reference":"example-bwga","sex":"[sex]","male":"1","female":"2","age":{"days":"[ga_days]"},"soft":[-2,2],"softBlock":"off","hard":[-5,5]}
```

```text expect
3.44 with sex=1, ga_days=280   => ok, z 0.00
2.2 with sex=1, ga_days=286    => soft-low, z -2.82
4.5 with sex=2, ga_days=266    => soft-high, z 3.56
0.9 with sex=1, ga_days=280    => hard-low, z -5.77
3.0 with sex=1, ga_days=258    => not evaluated
```

A table written from a mean and an SD has a floor: a weight of 0 scores
−mean / SD, and nothing typed can score lower. For girls at 37 weeks that is
−6.95, so a `hard` low limit of −7 could never be passed, and the module
refuses it on the form and in the scan with:

> "reference" "example-bwga": its "hard" low limit -7 is out of reach: with "example-bwga" no measurement scores below -6.95 at 259 days (female), so a low limit must be above -6.95.

The whole `index.json` of this level, beside the three tables:

```json
{
  "format": "uv-references-1",
  "references": {
    "example-hfa": {
      "title": "Height-for-age, example national reference (5 to 6 years)",
      "file": "example-hfa.json",
      "sha256": "b58f4fda71110943cdc86c88774cbdcf62d8d0fc12cd6f917d0210befda86644",
      "measure": "height", "unit": "cm",
      "axis": "age", "axisUnit": "months", "lookup": "linear",
      "valid": {"min": "60", "max": "72"},
      "adjust": "none"
    },
    "example-wfl": {
      "title": "Weight-for-length, example national reference (45 to 50 cm)",
      "file": "example-wfl.json",
      "sha256": "0d717f0d88de4d226a2148f3e5c768cf4e5ee9cc9b89b1ec8e895fe834422da4",
      "measure": "weight", "unit": "kg",
      "axis": "length", "axisUnit": "cm", "lookup": "linear",
      "valid": {"min": "45", "max": "50"},
      "adjust": "none"
    },
    "example-bwga": {
      "title": "Birth weight for gestational age, example (37 to 42 weeks)",
      "file": "example-bwga.json",
      "sha256": "0593e3a7a87334761f5cf6b28c396f45f5afd8c465365bfa5a6fec9e8233d90e",
      "measure": "weight", "unit": "kg",
      "axis": "age", "axisUnit": "days", "chartUnit": "weeks", "lookup": "floor",
      "valid": {"min": "259", "below": "301"},
      "adjust": "none"
    }
  }
}
```

#### Choosing `lookup`, `valid` and `adjust`

| The table has | `lookup` | Example |
| ------------- | -------- | ------- |
| a row per day, read at the nearest day | `round` | WHO 2006, birth to 5 years |
| a row per month or per length step, read between two rows | `linear` | WHO 2007, WHO weight-for-length, Examples 1 and 2 |
| rows that each stand for an interval: a completed day, week or half month | `floor` | CDC 2000 half months, Example 3 |

- **`valid`** takes `min` and either `max` (included) or `below` (excluded),
  in the axis unit, as text. Keep it inside the rows: a range that needs a row
  the table lacks is refused, with a message such as "the table
  example-hfa.json has "male" rows 60 to 72, but its "valid" range needs rows
  60 to 80 (a row is 1/1 months)". `convert.php` prints a range the rows cover;
  narrow it if the source says the reference holds for less.
- **`adjust`** is `none` for every reference except WHO's own weight, BMI, arm
  circumference and skinfold tables, which WHO reads beyond ±3 SD with its
  restricted method (`who-restricted`).
- **Several references in one folder** are entries of one `index.json`. Two
  entries may share one table file with a different `valid` or `lookup`.
- **`chartUnit`** (`days`, `weeks`, `months`, `years` or `auto`) sets the unit
  a growth chart counts an age axis in (Level 8). Without it, or with `auto`,
  the chart counts months up to two years and years beyond; `example-bwga`
  counts weeks of gestation. Only an age reference takes it, and it does not
  change the check.
- `pageTables`, at the top of the folder's `index.json`, sets how many tables
  one page carries (0 to 50, default 4), and `pageCharts` how many sets of
  chart curves and tables of reference values (Level 8, same range and
  default). A value outside the range is reported to staff, and the default
  applies; the folder's references stay in use.

#### Tables of other shapes

- **CDC 2000.** Its rows sit on half months: the row at 24.5 covers ages from
  24 up to 25 months. Convert with `--x-offset 0.5`, skip the extra
  whole-month rows with `--min-x` and `--max-x`, and use `"lookup": "floor"`
  with `"axisUnit": "months"`. `data/references/README.md` gives the commands
  for the infant and the 2 to 20 year tables.
- **A table by year.** The rows must sit on a grid of days or months, so
  write each year as twelve monthly rows: the same values twelve times with
  `floor`, or values worked out between the years in the spreadsheet with
  `linear`.
- **One set of rows for both sexes.** Write every row twice, once with each
  sex code. A rule still names a sex field; on a project without one, a calc
  field whose equation is `1` serves.
- **A log-normal reference** (a median and the SD of the log of the
  measurement): L is 0, M the median and S that SD.
- **A newer WHO table.** An entry with the id of a shipped reference, such as
  `who-wfa`, replaces it for every project on the server.
- **Curves that are not L, M and S.** Box-Cox t or power exponential curves
  with four parameters, as some INTERGROWTH-21st standards use, cannot be
  written as L, M and S, and the module does not read them.

#### Changing a table later

Convert the new table and copy its SHA-256 into the entry. A table that no
longer matches the SHA-256 in `index.json` is not used: every rule naming it
shows a configuration error until the two agree. To avoid that gap, write the
new table under a new file name and change `file` and `sha256` in one edit of
`index.json`. Values already saved are not judged again until the record is
saved or a Validation scan runs.

#### When a reference cannot be used

A rule that names a reference the module cannot use shows a configuration
error under the field and in the Validation scan, and the post-save audit
skips it. The folder fails closed: an `index.json` the module cannot read
stops every growth reference, the WHO ones included, so a mistake is seen
instead of rules quietly judging against a table that was meant to be
replaced.

| The error says | What to do |
| -------------- | ---------- |
| `is not a growth reference this server has — it has …` | The id is wrong, or the folder is not set: compare the id with the `index.json` keys |
| `the folder has no readable index.json.` | The path in the setting is wrong, or the web server cannot read the folder |
| `index.json is not valid JSON.` | A comma or a quote is missing; check the file with a JSON validator |
| `index.json must say "format": "uv-references-1".` | Add that line at the top |
| `reference "…" needs "unit".` (or another key) | Fill in the key; that id stays out of use until then |
| `the table … does not match the "sha256" in index.json` | The table changed after `convert.php` printed the SHA-256; copy the new value in |
| `has "male" rows …, but its "valid" range needs rows …` | `valid` reaches past the rows; narrow it |
| `its "hard" low limit … is out of reach` | The limit lies beyond what any measurement can score; move it inside the value the message gives |

`php convert.php --check <id> --folder <folder> …` prints the same problems
for the folder before a rule meets them.

### Level 8 — a growth chart and a table on the form (`show`)

`show` draws the reference's curves with this visit's point under the field,
and a table under them. Both are on the page only. Nothing is saved, and the
check is the same with or without them.

```text
# on: weight_kg — the WHO percentile curves, the usual range shaded, this visit as a point
@UVRANGE={"reference":"who-wfa","sex":"[sex]","male":"1","female":"2","age":{"dob":"[dob]","at":"[visit_date]"},"soft":[-2,2],"hard":[-5,5],"show":{"chart":{"lines":{"percentiles":[3,15,50,85,97]},"bands":true}}}
```

```text expect
6.4 with sex=1, dob=2025-01-10, visit_date=2025-04-20    => ok, z -0.23
4.1 with sex=1, dob=2025-01-10, visit_date=2025-04-20    => soft-low, z -3.83
```

The chart sits under the field's note, in a box that opens and closes ("Growth
chart"). It shows:

- **The heading.** The reference's title, its source ("WHO Child Growth
  Standards (2006)") and the sex the chart is for, and a legend for this
  visit's point and, when it is drawn, the band.
- **The curves.** One per percentile in `lines`, or per z-score with
  `"lines":{"z":[-3,-2,0,2,3]}`, at most 9, each named at the right edge. The
  median is green, ±2 SD red, ±3 SD and beyond black, the others grey. Without
  `lines` the chart draws 0 and each of the rule's limits, here -5, -2, 0, 2
  and 5, whatever their value.
- **The band.** With `"bands":true`, the `soft` range is shaded between its two
  curves (from one curve to the edge when the rule has only one `soft` limit).
  It needs a `soft` limit.
- **This visit.** A point at the child's age and the typed measurement. Its
  tooltip and the chart's description give the value, the age, the z-score and
  the percentile, for a boy of 100 days weighing 6.4 kg `This visit: 6.4 kg
  at 3.29 months, z-score -0.23, percentile 41.1`. A point outside the chart
  is an arrow at the edge it passes, and the description says so. When the
  point cannot be placed, the description says why: no sex, an age that
  cannot be worked out, a value that is not a number above 0.
- **The axes.** The age in months while the chart spans less than two years,
  in years beyond, or the length or height in cm; a reference whose
  `index.json` entry has `"chartUnit"` counts its age in that unit always
  (weeks for a reference by gestational age). The measurement in the field's
  unit: in grams with `"measureScale":0.001`, since the server divides the
  scale back out. The y axis spans at least the reference at z-scores -2 to
  2 over the ages shown, so a chart that draws only the median still has
  room for every child in that range. With `"y":"z"` the y axis is the
  z-score, from at least -3 to 3, and every curve is a flat line.

**Where the point sits.** WHO's tables have a row a day, and the check reads
the row nearest the child's age (the table's `lookup`); a table read with
`floor` reads the row at or below it. On those tables the point is drawn at
the age of the row the check reads, where the drawn curves are exact, so a
child on the line between two tiers is drawn on the side the check puts them.
A table read with `linear` reads between rows, and the point sits at the age
itself.

**How wide it is.** The chart fits the field's cell, from 320 to 640 pixels
wide, and its text keeps its size. In a narrower cell it is drawn 320 wide and
shrinks with the cell. The cell is measured when the chart is drawn: after the
window is resized, the next value entered draws the chart to the cell again.

**Which ages it shows.** Without `span` the curves cover the whole reference,
and the chart shows from its start to a quarter past the child's age, never
less than an eighth of the reference: 0 to 7.5 months for a baby of 100 days on
`who-wfa`. It moves only when the age leaves that window, or the age is
corrected to one that fits a window half as wide, so typing a new weight never
moves the axes. `"span":[0,730]` shows birth to two years whatever the age.

**What moves.** The curves are drawn once for the sex and the window. A new
weight, read when the field is left or changed, moves only the point. A
different sex draws that sex's curves.

**This visit in a table.** `"table":true` shows a row for this visit under the
chart: the age, the weight, the z-score and the percentile. `columns` picks
others:

```text
# on: weight_kg — this visit with its usual range and the weight at the 97th percentile
@UVRANGE={"reference":"who-wfa","sex":"[sex]","male":"1","female":"2","age":{"dob":"[dob]","at":"[visit_date]"},"soft":[-2,2],"hard":[-5,5],"show":{"table":{"columns":["age","value","z","percentile","range","valueAtPercentile:97"]}}}
```

A column is any value `return` can write, or `age` (in the reference's axis
unit) or `value` (the measurement as typed), at most 16. `returnLimits`,
`decimals` and `percentileDecimals` shape the columns as they shape returned
values, and a column made from a limit needs that limit. Numbers use the
measured field's decimal mark.

**A table of reference values.** `"rows":"curve"` lists the reference itself,
one row every `step` in the reference's axis unit, for the child's sex:

```text
# on: weight_kg — the 3rd, 50th and 97th percentile at each birthday
@UVRANGE={"reference":"who-wfa","sex":"[sex]","male":"1","female":"2","age":{"dob":"[dob]","at":"[visit_date]"},"soft":[-2,2],"hard":[-5,5],"show":{"chart":{"lines":{"percentiles":[3,50,97]}},"table":{"rows":"curve","step":365.25}}}
```

Without `columns` it has the age and a column per curve of the chart (or per
default line that a `valueAtZ:` column can name). Its columns cannot need a
measurement (`z`, `percentile`, `value`), and it has at most 100 rows over its
`span`, which is the chart's when it names none. Fields with the same table
share one copy on the page.

**A chart without limits.** A rule that only shows needs no `soft` or `hard`:
its chart draws the median, and its check is the one every growth rule makes
(a value that is not a number above 0 is implausible).

```text
# on: weight_kg — the curves and this visit, with no limits to judge
@UVRANGE={"reference":"who-wfa","sex":"[sex]","male":"1","female":"2","age":{"dob":"[dob]","at":"[visit_date]"},"show":{"chart":{"lines":{"percentiles":[3,50,97]}}}}
```

**Labels.** Each text the chart and the table show can be the rule's own,
written as one text or as a text per Multi-Language Management language:

```text
# on: weight_kg — French labels beside the English ones
@UVRANGE={"reference":"who-wfa","sex":"[sex]","male":"1","female":"2","age":{"dob":"[dob]","at":"[visit_date]"},"soft":[-2,2],"hard":[-5,5],"show":{"chart":{"labels":{"title":{"en":"Weight for age","fr":"Poids pour l'âge"},"point":{"en":"Today","fr":"Aujourd'hui"},"x":{"en":"Age ({unit})","fr":"Âge ({unit})"},"months":{"en":"months","fr":"mois"},"years":{"en":"years","fr":"ans"},"male":{"en":"boy","fr":"garçon"},"female":{"en":"girl","fr":"fille"}}},"table":{"labels":{"z":{"en":"z-score","fr":"score z"}}}}}
```

The chart takes `summary` (the box's heading), `title`, `x`, `y`, `point`,
`lines` (the heading over the curve names), `band`, the unit words `days`,
`weeks`, `months` and `years`, and the sex words `male` and `female`. `x`
may hold `{unit}`, filled with the word for the unit the axis counts in, so
the title follows the axis from months to years; an `x` without it is shown
as written. The table takes `summary`, `caption` and one per column it shows,
named by the column (`valueAtZ:2.5` names a column written `valueAtZ:2.50`).
A label is at most 200 characters on one line, and shows as text: `<b>` stays
`<b>`. A switch of language relabels the chart and the table in place,
without checking again.

The words the module writes itself are in English. They are the defaults of
the labels above ("Growth chart", "This visit", "Percentiles"), "at",
"z-score" and "percentile" in the point's description, the notes when nothing
is drawn, and the staff notes. Give a label to put one of the defaults in the
page's language.

**When nothing is drawn.**

- **The sex is not entered yet**, or is a code that is neither: the chart and
  the table say so.
- **The rule is not checked on this page.** An input on a form the page does
  not show, a rule past the page's table cap (see "How many references a page
  carries" above), a branch that does not apply: the chart and the table
  follow the check and are not shown.
- **The sex saved on another form is blank, or neither code.** There is
  nothing to draw for this record; staff see why.
- **The page's chart cap.** The curves of one chart take a few kilobytes. A
  page carries at most 4 sets of curves and tables of reference values, set by
  `"pageCharts"` in the folder's `index.json` (0 to 50; a value outside that
  range keeps 4). Charts that draw the same curves share one set, and a chart
  of z-scores or a table of this visit costs nothing. Each set counts once,
  whichever branch of a field draws it. Past the cap, staff see "Growth chart
  not shown:" with the reason; a survey shows nothing. The check is not
  affected.
- **A `span` outside the reference**, or a table of reference values with no
  `span` and more than 100 rows over the whole reference: that part is left
  off with the reason for staff, and the rule's check runs as before. The
  reference can change under a rule (an administrator may replace it), so this
  is never a configuration error. A table whose own or chart's `span` gives
  more than 100 rows is refused when the rule is saved.
- **Curves the page cannot carry.** A reference whose values are too large to
  send, or whose rows are too ragged to draw in 5,000 points: the chart is
  left off with the reason for staff.

**Surveys.** On a survey the box starts closed, and on a data entry form open.
`"open":true` or `"open":false` on the chart or the table sets it.

**How close the curves are.** The server works out each curve at every row of
the reference with the same functions the check uses, the WHO restricted method
beyond ±3 SD included, and keeps only the points the drawing needs. A drawn
curve lies within half a unit of the chart's 312-unit-tall plot of the exact
one at every row (and, for a table read with `linear`, between rows), at the
narrowest window the chart shows; the module's tests measure this on every
bundled reference.

| Key | Value | Default |
| --- | ----- | ------- |
| `show` | `{"chart":…}`, `{"table":…}` or both; each `true` for its defaults | none |
| `chart.lines` | `{"percentiles":[…]}` (0.1 to 99.9) or `{"z":[…]}` (-6 to 6), at most 9, two decimals at most | 0 and each of the rule's limits |
| `chart.bands` | `true` shades the `soft` range | `false` |
| `chart.y` | `value` (the measurement) or `z` | `value` |
| `chart.span` | `auto`, or `[low, high]` in the reference's axis unit | `auto` |
| `chart.open`, `table.open` | `true` or `false`: the box open when the page opens | open on data entry, closed on surveys |
| `chart.labels`, `table.labels` | label name to a text, or to a text per language | the module's English |
| `table.rows` | `history` (this visit) or `curve` (reference values) | `history` |
| `table.step` | above 0, in the reference's axis unit; `curve` only, and needed there | none |
| `table.span` | as `chart.span`; `curve` only | the chart's, else `auto` |
| `table.columns` | a list of values `return` takes, `age` and `value`, at most 16 | `age`, `value`, `z`, `percentile`; for `curve` the age and the chart's curves |

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
  `not-positive`, `plausible-low` or `plausible-high`, whatever `softBlock`
  says. A growth finding also carries `reference`, and `z` when the log keeps
  raw values. A rule that only
  shows notes still leaves a record of each unusual value. Every growth value
  with no z-score is logged as `uvalidate-not-evaluated`, with the reason,
  also when a `plausible` range judged it.
- **The Validation scan** counts these findings. Its report labels them
  "Unusual value", "Implausible value", "Not a number" or "Not a positive
  measurement", with the limits in the detail line ("Expected 12 to 17.5
  g/dL." or "Allowed 3 to 25 g/dL."). A growth finding shown with its value
  adds its z-score ("Its z-score is -3.40 on the growth reference who-wfa."),
  and a value outside
  `plausible` is an "Implausible value" with that range ("Allowed 0.5 to 30
  kg.").
  The report page and its download are not available while the scan is being
  rebuilt (see the README); the scan page shows the count. The scan's own
  result names the branch that judged each value, so the report can show that
  branch's limits; the stored run does not keep the branch yet.
- **Configure dialog:** none. `@UVRANGE` exists only as an action tag.

### `@UVRANGE` JSON keys

`soft`, `hard`, `softBlock`, `hardBlock`, `unit`, `when`, `message` and
`caseSensitive`, and for a growth reference `reference`, `sex`, `male`,
`female`, `age`, `by`, `plausible`, `measureScale`, `return`, `returnLimits`,
`decimals`, `percentileDecimals` and `show`. A rule needs `soft` or `hard` limits,
unless every value it returns is `z`, `percentile`, `valueAtZ:<z-score>` or
`valueAtPercentile:<percentile>`, or it returns nothing and has a `show`; a
value or a table column made from a limit still needs that limit. Any other
key is a configuration error.

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

# "return" writes what a growth reference gives.
# refused: only apply with a "reference"
@UVRANGE={"hard":[3,25],"return":{"z":"[wfa_z]"}}

# Two values cannot go to one field.
# refused: to the same field
@UVRANGE={"reference":"who-wfa","sex":"[sex]","male":"1","female":"2","age":{"days":"[age_days]"},"hard":[-5,5],"return":{"z":"[wfa_z]","range":"[wfa_z]"}}

# A bound needs the limit it comes from, and "soft" is the default.
# refused: low "soft" limit
@UVRANGE={"reference":"who-wfa","sex":"[sex]","male":"1","female":"2","age":{"days":"[age_days]"},"hard":[-5,5],"return":{"lower":"[wfa_low]"}}

# "decimals" sets how the returned bounds are written.
# refused: "decimals" must be a whole number from 0 to 6
@UVRANGE={"reference":"who-wfa","sex":"[sex]","male":"1","female":"2","age":{"days":"[age_days]"},"hard":[-5,5],"return":{"lower":"[wfa_low]"},"returnLimits":"hard","decimals":9}

# "decimals" shapes the bounds and the values at a z-score or percentile;
# a rule that returns none of them is refused.
# refused: and "return" has none of them
@UVRANGE={"reference":"who-wfa","sex":"[sex]","male":"1","female":"2","age":{"days":"[age_days]"},"return":{"z":"[wfa_z]"},"decimals":3}

# Each key once: "valueAtZ:2" and "valueAtZ:2.0" are one key written two ways.
# refused: are the same value
@UVRANGE={"reference":"who-wfa","sex":"[sex]","male":"1","female":"2","age":{"days":"[age_days]"},"return":{"valueAtZ:2":"[wfa_sd2]","valueAtZ:2.0":"[wfa_sd2b]"}}

# A value at a z-score names a z-score from -6 to 6 after a colon.
# refused: a number from -6 to 6 with at most 2 decimals
@UVRANGE={"reference":"who-wfa","sex":"[sex]","male":"1","female":"2","age":{"days":"[age_days]"},"return":{"valueAtZ:9":"[wfa_sd9]"}}

# A value at a percentile needs the percentile.
# refused: takes the percentile after a colon
@UVRANGE={"reference":"who-wfa","sex":"[sex]","male":"1","female":"2","age":{"days":"[age_days]"},"return":{"valueAtPercentile":"[wfa_p97]"}}

# "percentileDecimals" sets how the percentile is written, 0 to 3 decimals.
# refused: "percentileDecimals" must be a whole number from 0 to 3
@UVRANGE={"reference":"who-wfa","sex":"[sex]","male":"1","female":"2","age":{"days":"[age_days]"},"return":{"percentile":"[wfa_pct]"},"percentileDecimals":5}

# ... and applies only when "return" has a percentile.
# refused: and "return" has none
@UVRANGE={"reference":"who-wfa","sex":"[sex]","male":"1","female":"2","age":{"days":"[age_days]"},"return":{"z":"[wfa_z]"},"percentileDecimals":2}

# A reference sets the unit itself. "unit" goes with a reference only
# beside "measureScale", to name the unit the field holds.
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

# Sex codes are matched without regard to letter case.
# refused: differ only in letter case
@UVRANGE={"reference":"who-wfa","sex":"[sex]","male":"a","female":"A","age":{"days":"[age_days]"},"hard":[-5,5]}

# "plausible" and "measureScale" are for growth references. Fixed limits are already in the field's unit.
# refused: only apply with a "reference"
@UVRANGE={"hard":[3,25],"plausible":[1,30]}

# The plausible range is low, then high.
# refused: "plausible" low limit
@UVRANGE={"reference":"who-wfa","sex":"[sex]","male":"1","female":"2","age":{"days":"[age_days]"},"hard":[-6,5],"plausible":[30,0.5]}

# A scale of 0 would make every value 0.
# refused: "measureScale" must be a number from 0.000001 to 1000000
@UVRANGE={"reference":"who-wfa","sex":"[sex]","male":"1","female":"2","age":{"days":"[age_days]"},"hard":[-6,5],"measureScale":0}

# A chart draws the curves of a growth reference.
# refused: "show" only applies with a "reference"
@UVRANGE={"hard":[3,25],"show":{"chart":true}}

# The chart's settings are an object, not a list.
# refused: "show" "chart" must be true or an object of its settings
@UVRANGE={"reference":"who-wfa","sex":"[sex]","male":"1","female":"2","age":{"days":"[age_days]"},"hard":[-5,5],"show":{"chart":[]}}

# A column's label is given once, however the column is written.
# refused: labels "valueAtZ:2" twice, written two ways
@UVRANGE={"reference":"who-wfa","sex":"[sex]","male":"1","female":"2","age":{"days":"[age_days]"},"hard":[-5,5],"show":{"table":{"columns":["age","valueAtZ:2"],"labels":{"valueAtZ:2":"+2 SD","valueAtZ:2.0":"2 SD"}}}}

# At most 9 curves on one chart.
# refused: has more than 9 lines
@UVRANGE={"reference":"who-wfa","sex":"[sex]","male":"1","female":"2","age":{"days":"[age_days]"},"hard":[-5,5],"show":{"chart":{"lines":{"percentiles":[1,3,5,10,25,50,75,90,95,97]}}}}

# A percentile curve lies from 0.1 to 99.9.
# refused: "show" "chart" "lines" "percentiles" takes a number from 0.1 to 99.9 with at most 2 decimals for each line — got 100.
@UVRANGE={"reference":"who-wfa","sex":"[sex]","male":"1","female":"2","age":{"days":"[age_days]"},"hard":[-5,5],"show":{"chart":{"lines":{"percentiles":[3,50,100]}}}}

# The band shades the usual range, so it needs "soft" limits.
# refused: "bands" shades the "soft" range
@UVRANGE={"reference":"who-wfa","sex":"[sex]","male":"1","female":"2","age":{"days":"[age_days]"},"hard":[-5,5],"show":{"chart":{"bands":true}}}

# A table of reference values needs the distance between its rows.
# refused: "rows":"curve" needs "step"
@UVRANGE={"reference":"who-wfa","sex":"[sex]","male":"1","female":"2","age":{"days":"[age_days]"},"hard":[-5,5],"show":{"table":{"rows":"curve"}}}

# ... and at most 100 of them over its span: a row a day for a year is too many.
# (Over a reference with no span, the table is left off with a note to staff instead.)
# refused: has more than 100 rows over its "span" [0, 365]
@UVRANGE={"reference":"who-wfa","sex":"[sex]","male":"1","female":"2","age":{"days":"[age_days]"},"hard":[-5,5],"show":{"table":{"rows":"curve","step":1,"span":[0,365]}}}

# "step" is for a table of reference values.
# refused: only applies with "rows":"curve"
@UVRANGE={"reference":"who-wfa","sex":"[sex]","male":"1","female":"2","age":{"days":"[age_days]"},"hard":[-5,5],"show":{"table":{"step":30}}}

# Reference values have no measurement, so no z-score.
# refused: needs a measurement, and a table of reference values
@UVRANGE={"reference":"who-wfa","sex":"[sex]","male":"1","female":"2","age":{"days":"[age_days]"},"hard":[-5,5],"show":{"table":{"rows":"curve","step":365.25,"columns":["age","z"]}}}

# A column is a value "return" takes, "age" or "value".
# refused: unknown "show" "table" "columns" entry "bmi"
@UVRANGE={"reference":"who-wfa","sex":"[sex]","male":"1","female":"2","age":{"days":"[age_days]"},"hard":[-5,5],"show":{"table":{"columns":["age","bmi"]}}}

# A label is one line of text.
# refused: must be a text of 1 to 200 characters on one line
@UVRANGE={"reference":"who-wfa","sex":"[sex]","male":"1","female":"2","age":{"days":"[age_days]"},"hard":[-5,5],"show":{"chart":{"labels":{"title":" "}}}}
```

These are refused once the data dictionary is read, with a configuration error
under the field: a `reference` this server does not have (the error lists the
ones it has), `age` on a reference read by length or height (and `by` on one
read by age), an input field that does not exist, is the wrong kind or is the
measured field itself, a whole-number `months` field on a reference read by
day, a `male` or `female` code that is not among the sex field's choices or
matches two of them once letter case is ignored, and a limit the reference
cannot reach (see "Limits the reference can reach" above).

## `@UVDELTA` — how far a number may change

`@UVRANGE` judges one value on its own, and `@UVASSERT` can say that a weight
is not below the previous one. Neither can say "this weight may not change by
more than 10% since the last visit". `@UVDELTA` compares a number with an
**earlier value** and flags a change larger than its limit: a number of units
(`max`), a percent of the earlier value (`maxPct`), or both. JSON form only.

### Level 1 — a limit on the change from another field

```text
# on: weight — at most 5 kg either way from the screening weight
@UVDELTA={"vs":"[weight_screening]","max":5,"unit":"kg"}
```

```text expect
74 from 70     => ok
75 from 70     => ok
75.01 from 70  => up
64.9 from 70   => down
abc from 70    => not-a-number
70 from n/a    => earlier-not-a-number
```

`vs` names the earlier value, and `max` the largest change allowed, in the
field's own unit. Both ends are included, so a change of exactly 5 passes. The note
under the field names the change, its percent, the earlier value and the limit:

> ✗ This value rose by 5.01 kg (7.2%) from 70 kg ([weight_screening]). Allowed: a change of at most 5 kg.

The note appears when the user leaves the field. A blank value, a blank earlier
value and a missing data code on either side check nothing.

### Level 2 — a percent, a direction, or both limits

```text
# on: weight — a rise of at most 10%; a fall is not limited by this tag
@UVDELTA={"vs":"[weight_prev]","maxPct":10,"direction":"up"}
```

```text expect
110 from 100       => ok
110.0001 from 100  => up
50 from 100        => ok
5 from 0           => blank
```

```text
# on: hb — at most 2 g/dL and at most 15%
@UVDELTA={"vs":"[hb_prev]","max":2,"maxPct":15,"unit":"g/dL"}
```

```text expect
13 from 12     => ok
14.5 from 12   => up
3.5 from 3     => up
2.9 from 3     => ok
```

```text
# on: height_cm — a height may not fall; any rise passes
@UVDELTA={"vs":"[height_prev]","max":0,"direction":"down","unit":"cm"}
```

```text expect
149.9 from 150  => down
152 from 150    => ok
```

- `maxPct` is a percent of the earlier value. With both `max` and `maxPct`, a
  change must keep both: 3.5 after 3 is 0.5 g/dL but 16.7%.
- `direction` is `both` (the default), `up` or `down`. The limits apply to
  changes that way; a change the other way always passes.
- An earlier value of 0 has no percent, so `maxPct` does not apply to it.
  `max` still does. For the same reason `"maxPct": 0` is refused; `"max": 0`
  says "no change", from 0 too.
- Values and limits are compared as exact decimals. The percent test is
  `|change| × 100` against `maxPct × |earlier value|`, with no division and no
  rounding, so a change of exactly 10% is 10%. A number with an exponent is
  written out in full; one longer than 4,096 digits written out is too long to
  compare and is reported, not judged.
- The note gives the percent to one decimal. A percent that rounds to the
  limit it broke reads "more than 10%", one that rounds to 0 reads "under
  0.1%", and the earlier value is written in one form (`070` and `+70` are 70).

### Level 3 — the previous or first event or instance

These need **Enable event and instance references** in the project settings.
Without it the tag shows a configuration error that says so.

```text
# on: weight, on a form at every visit
@UVDELTA={"vs":"previous-event","maxPct":10,"unit":"kg"}

# on: cd4, on a repeating form — a fall of at most 50% since the last result
@UVDELTA={"vs":"previous-instance","maxPct":50,"direction":"down"}

# on: weight — at most 15% below baseline
@UVDELTA={"vs":"first-event","maxPct":15,"direction":"down"}
```

| `vs`                | Means                                         | Written out for a field `weight`  |
| ------------------- | --------------------------------------------- | --------------------------------- |
| `previous-event`    | This field at the event before this one, among those with the form | `[previous-event-name][weight]`   |
| `first-event`       | This field at the first event of the arm with the form             | `[first-event-name][weight]`      |
| `previous-instance` | This field in the instance before this one    | `[weight][previous-instance]`     |
| `first-instance`    | This field in the first instance              | `[weight][first-instance]`        |

Any one field reference works too, in this entry, another event or another
instance:

```text
@UVDELTA={"vs":"[baseline_arm_1][weight]","maxPct":15,"direction":"down"}
# where the form repeats at every visit: the last instance of the visit before
@UVDELTA={"vs":"[previous-event-name][weight][last-instance]","max":2,"unit":"kg"}
```

- `previous-event` is the event before this one, in the same arm, that has
  this form, whether or not anything was saved there. On the first such event
  and the first instance there is no earlier value, so nothing is checked.
- Each entry resolves its shorthand on its own, so the tag is checked at every
  event that holds the form, arm by arm:
  - An event shorthand needs, in some arm, two or more events with the form.
  - An event shorthand is refused when an event it names holds the form more
    than once (the form repeats there, or the whole event repeats): it does not
    say which instance to compare with. The refusal gives a reference that
    names the event and the instance and works from every event, such as
    `[previous-event-name][weight][last-instance]`, when there is one. A form
    that repeats only at the
    last visit is fine, because no shorthand names that visit.
  - An instance shorthand needs the form to repeat at every event that holds
    it, on its own or in a repeating event.
- A reference that always names this same entry is refused, since the change
  would always be 0: `[event-name][weight]`, `[weight][current-instance]` on a
  form that repeats, and the one event the form is on by name or as
  `[first-event-name]` or `[last-event-name]`.
- A reference that names an instance (`[weight][1]`, `[weight][first-instance]`)
  is refused where the form does not repeat at an event it lands on: there is
  only one entry there, and on a project with events the refusal names them.
- `[current-instance]`, `[previous-instance]` and `[next-instance]` count from
  the instance of the field that carries the tag, so they are refused where
  that field's own form is held once at an event the reference is read from
  (`[w_rep][previous-instance]` on an enrolment form). Name the instance
  instead, as `[w_rep][last-instance]`.
- A reference with no instance is refused where its form repeats, unless the
  row is the tagged field's own (its own repeating form, or a form of the same
  repeating event). `[w_rep]` read from a form held once does not say which
  instance it means, and neither does `[enrol_arm_1][w_rep]`; the refusal
  suggests `[enrol_arm_1][w_rep][last-instance]`.
- A refusal ends with a reference to write instead only when that reference
  passes the same check from every event where the field is entered. Where
  the field's form is entered in two arms it suggests a relative event such
  as `[first-event-name][w_enrol]`, never an event of one arm, which holds a
  value from the other arm only for a record entered in both, even when the
  reference as written named that event (written by hand, such an event is
  accepted). It never suggests the next event, nor, on the field's own form,
  the last event or a later visit named outright, which compare with a later
  entry, nor a reference with the `vs` and direction of another `@UVDELTA`
  tag on the field under the same condition. For the field itself it suggests
  `[previous-instance]` only at the field's own event and `[last-instance]`
  only at another. When nothing works it lists the events and instances it
  tried and says none of them can be read.
- The event a reference lands on must hold the `vs` form. `[visit1_arm_1][w_rep]`
  is refused when `w_rep`'s form is not designated at visit 1, and a plain
  `[w_enrol]` is refused on a visit form when only enrolment collects it; the
  refusal suggests `[enrol_arm_1][w_enrol]`.
- A relative event that finds no event from anywhere the field is entered is
  refused, since it would compare nothing: `[next-event-name][w_enrol]` on a
  visit form when only enrolment holds `w_enrol`. One that finds nothing from
  some events only (the first visit has no previous one) is fine.
- An earlier value from another event or instance is read when the page opens.
  That check shows a red note but never blocks, and its note says it is
  re-checked after the save.
- Saving an earlier visit re-checks the visits that compare with it, in the
  post-save audit.

### Level 4 — several comparisons on one field

```text
# on: weight — at most 5% from the previous visit and 15% from baseline
@UVDELTA={"vs":"previous-event","maxPct":5}
@UVDELTA={"vs":"first-event","maxPct":15}

# on: heart_rate — a rise of at most 20% and a fall of at most 10%
@UVDELTA={"vs":"[hr_prev]","maxPct":20,"direction":"up"}
@UVDELTA={"vs":"[hr_prev]","maxPct":10,"direction":"down"}
```

Tags that compare with different values, or limit different directions, all
apply, and each one that fails says its own line. Two tags with the same `vs`
and direction branch like other tags, so each needs its own `when`:

```text
@UVDELTA={"vs":"previous-event","maxPct":10,"when":"[age_years]>=18"}
@UVDELTA={"vs":"previous-event","maxPct":20,"when":"[age_years]<18"}
```

### Level 5 — enforcement and your own message

```text
# on: weight — ask before saving a change of more than 5 kg since screening
@UVDELTA={"vs":"[weight_screening]","max":5,"unit":"kg","blockSave":"confirm","message":"Weight changed by more than 5 kg since screening. Re-weigh before saving."}
```

By default `blockSave` is `off`, so the note shows and the save goes ahead. Only
a `vs` field on the same page can block. A `vs` on another form, event or
instance is a saved value read when the page opened, so that check never
blocks, and a calc field never blocks.

### Semantics worth knowing

- **Field types.** Text fields with integer or number validation (any number of
  decimals, comma decimals included) or with no validation, calc fields and
  sliders. A `vs` field must hold numbers too. On a comma-decimal field `72,5`
  reads as 72.5, and the note writes numbers with a comma.
- **Not a number.** A value that is not a number is left to REDCap's own
  validation on the page. A saved one, or an earlier value that is not a
  number, is a rule problem in the audit and the scan, never a pass.
- **Surveys.** A survey is never sent a value of another form, event or
  instance. On a survey the rule checks only a `vs` field on the same page, and
  the note does not name what it compares with. The audit and the scan check
  the rest.
- **Rights.** A `vs` on a form the user may not view is not sent to the page.
  Staff see that the change is checked when the record is saved.
- **Typing.** A note waits while the person is still typing in a field the
  rule reads (the field itself, its `vs` or a field of its `when`), and comes
  when they leave it. The save judges every field first either way.
- **Audit and scan.** The post-save audit logs a change past a limit as
  `type: delta` with reason `up` or `down`, and names the `vs` it compared
  with, so two tags on one field log findings that can be told apart. The
  Validation scan counts them as "Rose more than allowed" or "Fell more than
  allowed", with the limits and the value compared with in the detail line.
  Configure via field annotation only.

These are refused, with a configuration error under the field:

```text invalid
# refused: needs its settings as JSON
@UVDELTA=previous-event
# refused: needs "vs"
@UVDELTA={"max":5}
# refused: needs a limit
@UVDELTA={"vs":"previous-event"}
# refused: "vs" must be previous-event, previous-instance, first-event or first-instance
@UVDELTA={"vs":"previous-visit","max":5}
# refused: exactly one field reference
@UVDELTA={"vs":"[a] or [b]","max":5}
# refused: "max" must be 0 or more
@UVDELTA={"vs":"previous-event","max":-1}
# refused: "maxPct": 0 does not stop a change from an earlier 0
@UVDELTA={"vs":"previous-event","maxPct":0}
# refused: "direction" must be both, up or down
@UVDELTA={"vs":"previous-event","max":5,"direction":"left"}
# refused: unknown @UVDELTA option(s): min
@UVDELTA={"vs":"previous-event","max":5,"min":1}
```

Once the data dictionary is read, the module also refuses a field that does not
hold numbers, a `vs` that is not a field, does not hold numbers, always names
this same entry, or could not be read on any save of some entry (an event that
does not hold the form, an instance of a form that does not repeat, a relative
instance where the field's own form does not repeat, no instance of a form
that repeats, a relative event that never finds one), and a shorthand that
cannot name an earlier entry at every event that holds the form (see Level 3).

---

## `@UVWRITEONCE` — values entered once

A consent date, a randomisation arm or a baseline weight is entered once and
then left alone. `@UVWRITEONCE` leaves the field open until its first save of
a value and locks it from then on. Named user roles can still change it.

### Level 1 — lock for everyone

```text
@UVWRITEONCE
```

The first save of a value locks the field, whoever makes it. A blank and a
missing data code lock nothing, so a field marked "unknown" can still be
filled in later. On a form or a survey the locked field keeps the value it
opened with, and a note under it reads "This value was saved once and is
locked. It cannot be changed here."

### Level 2 — roles that may still change it

```text
@UVWRITEONCE={"roles":["Data manager"]}
@UVWRITEONCE={"roles":["Data manager","PI"],"message":"Ask the data manager to correct this date."}
```

- `roles` names user roles by the name the User Rights page shows or by the
  unique role name, up to 20. Letter case does not matter in any alphabet, and
  a run of spaces counts as one. Each must be a role of the project, or the
  field shows a configuration error.
- A member of a role sees the field open. A change by a member moves the lock
  to the new value; a member who clears it releases the lock, and the next
  value saved locks it again.
- A survey respondent holds no role, so a survey locks the field for everyone.
- Roles are read when the audit or the scan runs, not as they were at the
  save, because REDCap's log names who saved but not the role they held. A
  change counts as the role's while its maker holds the role.
- Rights that have expired and a suspended account hold no role. An
  administrator who is not a user of the project holds none either, and is
  locked out.
- When the user roles cannot be read, the page locks the field for everyone
  and the note adds "Your user role could not be checked, so the field is
  locked for now."
- REDCap logs a field only when its value changes. A member who wants to keep
  a value that someone outside the roles saved saves the field twice: another
  value, then that one.
- `message` replaces the note under the field and the scan's detail line. It
  may be a map of language ids to texts.

### Level 3 — a project that already holds data

```text
@UVWRITEONCE={"since":"2026-11-01"}
```

Saves before `since` lock nothing. The day starts at 00:00 in the project's
time zone: the value the field held at that moment is locked from then on, and
the page locks nothing before that day. The date is written YYYY-MM-DD.

### Semantics worth knowing

- The lock is the browser's only. A change made with the Data Import Tool or
  the API is not stopped. It is found afterwards, from REDCap's log, by the
  audit of the next save of the form that holds the field and by the
  Validation scan.
- A text box is made read-only, the radio buttons are disabled and their
  "reset" link hidden, the enhanced radio buttons take no clicks, the slider is
  disabled, and the date picker is kept from opening, with its button and
  REDCap's Today and Now buttons hidden. Nothing the form posts is disabled,
  since REDCap would save the field blank: a dropdown takes no clicks instead,
  and a change made with the keyboard or a script is undone, and the note says
  so. A dropdown or SQL field whose saved code is no longer one of its
  choices gets that code back as an option. The lock touches only the field's
  own controls: a field embedded in its row keeps its own buttons.
- When the saved value cannot be read, the field is not locked. Staff see a
  note saying so, and the audit still checks a change.
- A person's own first answer is locked as soon as it is saved: after Save &
  Stay, or Next on a multi-page survey, going back finds it locked.
- Another tag on a locked field still shows its note. It holds the save only
  when it also reads a field of this form that is not locked (a calc counts
  only when a field it is worked out from is such a field), and only once
  that field has changed on the page, a checkbox option by option. The value
  each field opened with is read once the form exists. A tag that reads the
  locked field alone, a violation already saved when the page opened, and one
  between two locked fields never hold it, on Save or on Enter.
- The tag takes no `when`, `caseSensitive` or `blockSave`, and no `references`.
- Not on a calc or a checkbox field, a field with `@CALCTEXT`, `@CALCDATE`,
  `@SETVALUE`, `@PREFILL` or `@DOWNLOAD-COUNT`, or a field another rule returns a value to
  (`@UVEXISTS` or `@UVRANGE` "return"): each of those is written again by
  itself. Not on a field with `@RICHTEXT` or an ontology search (BioPortal,
  FHIR or any other service) either, whose widget keeps its own copy of the
  text.
- A field given `@UVWRITEONCE` twice is refused: the tag takes no `when`, so
  there is nothing to choose between them.
- The audit logs `type: writeonce` with reason `changed` or `cleared`, plus
  `locked_at` and `changed_at`. Each record and event is read on its own,
  back to the row REDCap writes when it makes the record. A deletion of the
  whole record starts a new history, and so does an erase of all the
  project's records; a deletion of one form or one event at another event
  changes nothing. A log that holds more rows of the record and event than
  are read (5,000), a deletion of this event's data or of one arm, a deletion
  at another event the log does not describe as one form or one event (and in
  a project of several arms, every deletion of the record), a record whose log
  does not show it being made (renamed while its saves stay under the old
  name, or brought in with a copied project), a save the log names no event
  for, a save the log does not spell out, a value the log never shows saved,
  and roles that cannot be read when a change needs them are rule problems,
  never a pass.
- The log lists a save's fields in the data dictionary's order, and a line out
  of that order is how a Notes value typed to look like another field's line
  shows. From the first Text or Notes value before such a line, the save reads
  as not spelled out. A save written before a field was moved breaks the order
  too, so its later fields are rule problems for that save.
- A forged line that keeps the order reads as a save of its field. After the
  lock, a save by a person with no role moves nothing, so the line gives at
  worst a false finding or a rule problem. Before the lock, or before
  `since`, it can stand for a first value that was never saved; the next real
  save of another value is then reported as a change. Anyone could have saved
  that value first, so the line gives no one a value they could not have
  saved, and the forged row stays in the log.

```text invalid
# refused: takes no value
@UVWRITEONCE=yes
# refused: unknown @UVWRITEONCE option(s): when
@UVWRITEONCE={"when":"[consent]='1'"}
# refused: unknown @UVWRITEONCE option(s): blockSave
@UVWRITEONCE={"blockSave":"hard"}
# refused: must name at least one role
@UVWRITEONCE={"roles":[]}
# refused: must be a list of role names
@UVWRITEONCE={"roles":"Data manager"}
# refused: "since" must be a date written YYYY-MM-DD
@UVWRITEONCE={"since":"01/11/2026"}
```

Once the data dictionary and the user roles are read, the module also refuses a
role the project does not have, a calc or checkbox field, a field with
`@CALCTEXT`, `@CALCDATE`, `@SETVALUE`, `@PREFILL` or `@RICHTEXT`, a field
that searches an ontology, a field given the tag twice, and a field another
rule returns a value to.

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
@UVASSERT={"assert":"[participant_id]=[participant_id_confirm]","exact":true,"message":"The two ID entries do not match","blockSave":"hard"}
@UVUNIQUE={"scope":"dag","blockSave":"hard"}
```

`"exact":true` makes the two entries match character for character: `TB-000123` does
not confirm `tb-000123`, and a dropped leading zero is caught. Leave it out only when
letter case should not matter and the value is never a number.

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
  tags of the same kind on one field; two tags whose `when` conditions say the same
  thing, however written (keyword case, spacing, quote style, parentheses, the order of
  `and`/`or` members and of a comparison's sides aside; letter case too when both rules
  ignore it), since they could never be told apart; a single-value and a pooled
  `@UVALIDATE` sharing a field.
- **Overlapping conditions are a runtime conflict.** If two conditions are ever true at
  once, the field shows a "Validation conflict" notice naming both, validates nothing,
  and **never** blocks the save; the server logs the same conflict. Mutually exclusive
  conditions (`='2'` vs `<>'2'`) can never conflict.
- **`@UVRANGE` branches per reference.** These rules hold among the `@UVRANGE` tags
  that name the same growth reference, and among those with fixed limits. Tags that
  name different references (or a reference and fixed limits) all apply side by side,
  each without a `when` if need be; the worst result decides (see `@UVRANGE` Level 5).
  A condition that reads a saved value, or one that cannot be resolved, affects only
  the tags of its own reference.
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

| Supported                                                                                     | Rejected when the rule is saved                         |
| --------------------------------------------------------------------------------------------- | ------------------------------------------------------- |
| `[field]` and `[checkbox(code)]` references                                                   | Arbitrary functions (`datediff(...)`, etc.)             |
| Text/number literals and the comparisons `=` `<>` `!=` `>` `<` `>=` `<=`                      | Arithmetic and piping                                   |
| `and` / `or` / `not` in any letter case, and parentheses                                      | `&&`, `\|\|` and `!`: write `and`, `or`, `not`          |
| With extended references enabled: `[event][field]`, instance selectors and `{alias}` bindings | Unsupported smart variables such as `[record-name]`     |

One condition may mix `and`, `or` and `not`. `not` binds tightest, then `and`, then `or`,
as in REDCap: `[a]='1' or [b]='1' and [c]='1'` means `[a]='1' or ([b]='1' and [c]='1')`.
Add parentheses when you mean the other grouping. A condition nests at most 10 levels
deep, where each pair of parentheses and each `not` is one level, and holds at most 500
characters and 20 field references.

```text
@UVASSERT="([visit_type]='1' or [visit_type]='2') and [visit_date]>=[enrol_date]"
@UVALIDATE={"algorithm":"verhoeff","when":"[site]='3' AND NOT ([consent]='0' Or [withdrawn(1)]='1')"}
```

```text invalid
# REDCap logic writes "and", not "&&".
# refused: write "and" instead
@UVASSERT="[visit_date]>=[enrol_date] && [visit_date]<>''"

# refused: write "or" instead
@UVREQUIRED="[site]='3' || [site]='4'"
```

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

  A refused reference makes the whole rule deferred (with several `@UVRANGE`
  references on one field, only the tags of the reference it belongs to) — sibling terms of an `and`/`or`
  still fold on their own merits, but no verdict is shown for the rule and nothing is
  blocked. The reason names the field and the fix. For `@UVASSERT` the server reports it
  as well: a `uvalidate-unconfigurable` module-log entry after a save (all three cases),
  and a *Rule problems* line on the Validation scan page (the cross-repeating-instrument
  case — the scan reads a whole record at once, so the other two cannot arise there).
- **A `when` gate on another instrument makes the rule advisory too.** Stale applicability
  is as wrong as a stale verdict: the gate decides whether the rule applies at all, and it
  was read once when the page opened. The rule still gives live feedback; it does not
  block. Same for a branched rule whose branch selector lives off-page — the branch that
  gets chosen rests on page-load truth, so no branch of that rule blocks (with several
  `@UVRANGE` references on one field, no branch of that reference).
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
@UVASSERT={"assert":"[lot_code]=[lot_code_confirm]","exact":true,"message":"Lot codes must match exactly"}
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

Extended browser checks are advisory, except the parts of `@UVWINDOW` that
read only values on the current page: a `from` date on this page and `notFuture`
keep the rule's `blockSave` (see [@UVWINDOW](#uvwindow--dates-within-a-window)).
Current-page values can stay live; other events/instances are snapshots. A missing row, unavailable metadata or an
ambiguous match is unresolved, not a saved blank. An unresolved branch does not
activate its fallback. Save/reload to refresh snapshots, and run a scan to reconcile
imports, deleted instances or writes that do not invoke a save hook.

To limit how far a value may change since an earlier event or instance, rather
than compare it, use [`@UVDELTA`](#uvdelta--how-far-a-number-may-change).

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
project/DAG/event scopes retain their existing cross-record behavior. A record-scope
rule takes no `also`: to compare two fields of one record, use `@UVASSERT`.

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

> **Dates in any display format.** REDCap stores every date as Y-M-D. A condition
> compares that stored form on the page, in the post-save audit and in the scan, so
> `[discharge_date]>=[admission_date]` orders by year, month and day even when the
> fields show D-M-Y or M-D-Y. Write a date in a condition as Y-M-D (`'2026-03-01'`),
> as REDCap's own logic does. A date that is still being typed is compared as typed
> until it is a whole date. Up to 2.1.0 the page compared the text as shown, so a
> D-M-Y comparison could order by day on the page.

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
@UVASSERT={"assert":"[participant_id]=[participant_id_confirm]","exact":true,"message":"IDs do not match - re-type","blockSave":"hard"}

# on: phone_confirm
@UVASSERT={"assert":"[phone_number]=[phone_confirm]","exact":true,"message":"Phone numbers do not match"}

# on: specimen_id_confirm
@UVASSERT={"assert":"[specimen_id]=[specimen_id_confirm]","exact":true,"message":"Specimen ID confirmation mismatch - re-scan"}
```

`"exact":true` is what makes these checks work: without it `=` compares numbers by
value, and `0677123456` would confirm `677123456`.

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

#### One ID, several fields (`also`)

```text
# on: study_id_typed — typed when the scan fails; must not repeat a scanned ID
@UVUNIQUE={"also":["study_id_scan"],"message":"This ID is already registered","blockSave":"hard"}

# on: study_id_scan — must not repeat a typed ID either
@UVUNIQUE={"also":["study_id_typed"],"blockSave":"hard"}

# on: screening_id — also not a legacy ID, nor an ID from the old paper form
@UVUNIQUE={"also":["legacy_id","paper_id"],"message":"This ID is already in use"}
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

#### Timeliness, appointments and reporting periods

```text
# on: visit_date — entered within 7 days of the visit; staff may confirm a late entry
@UVWINDOW={"from":"today","window":[-7,0],"blockSave":"confirm"}

# on: next_visit_date — booked for today or later
@UVWINDOW={"notPast":true,"blockSave":"hard"}

# on: monthly_report_date — the report covers last month
@UVWINDOW={"period":"month","offset":-1}

# on: drug_given_at (datetime) — recorded within 4 hours of giving the drug
@UVWINDOW={"from":"now","window":[-4,0],"unit":"hours"}

# on: quarter_end_count — in the quarter that has just ended
@UVWINDOW={"period":"quarter","offset":-1}
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
# on: weight_kg — weight-for-age for under-fives, and weight-for-height from 2 years (age_days: a calc, datediff([dob],[visit_date],"d"))
@UVRANGE={"reference":"who-wfa","sex":"[sex]","male":"1","female":"2","age":{"dob":"[dob]","at":"[visit_date]"},"soft":[-3,3],"hard":[-6,5]}
@UVRANGE={"reference":"who-wfh","sex":"[sex]","male":"1","female":"2","by":"[height_cm]","soft":[-3,3],"hard":[-5,5],"when":"[age_days] >= 731"}

# on: height_cm — the date of birth is saved once, at enrolment, in another event
@UVRANGE={"reference":"who-lhfa","sex":"[enrolment_arm_1][sex]","male":"1","female":"2","age":{"dob":"[enrolment_arm_1][dob]","at":"[visit_date]"},"soft":[-3,3],"hard":[-6,6]}

# on: muac_cm — no check for a child with oedema
@UVRANGE={"reference":"who-acfa","sex":"[sex]","male":"1","female":"2","age":{"dob":"[dob]","at":"[visit_date]"},"hard":[-5,5],"when":"[oedema]<>'1'"}
```

The limits -6 and 5 for weight-for-age, and ±6 for length/height-for-age, are
the ones WHO's software uses to flag a z-score as implausible.

### `@UVDELTA` recipes

The shorthands and any `vs` in another event or instance need event and instance
references on.

#### Weight and growth across visits

```text
# on: weight_kg — at most 10% either way since the previous visit
@UVDELTA={"vs":"previous-event","maxPct":10,"unit":"kg"}

# on: weight_kg — at most 5% since the previous visit and 15% below baseline
@UVDELTA={"vs":"previous-event","maxPct":5}
@UVDELTA={"vs":"first-event","maxPct":15,"direction":"down"}

# on: height_cm — a child's height may not fall (0.5 cm allowed for measurement error)
@UVDELTA={"vs":"previous-event","max":0.5,"direction":"down","unit":"cm"}
```

#### Laboratory trends

```text
# on: creatinine (µmol/L), a repeating lab form — ask before saving a rise of more than 50%
@UVDELTA={"vs":"previous-instance","maxPct":50,"direction":"up","blockSave":"confirm"}

# on: hb (g/dL) — at most 2 g/dL from the screening result on another form
@UVDELTA={"vs":"[hb_screening]","max":2,"unit":"g/dL"}
```

#### Vital signs and repeated measurements on one form

```text
# on: sbp_2 — the second reading within 20 mmHg of the first, held until fixed
@UVDELTA={"vs":"[sbp_1]","max":20,"unit":"mmHg","blockSave":"hard","message":"The two readings differ by more than 20 mmHg. Take a third."}

# on: weight_2 — a second weighing within 0.2 kg of the first
@UVDELTA={"vs":"[weight_1]","max":0.2,"unit":"kg","blockSave":"confirm"}
```

### `@UVWRITEONCE` recipes

```text
# on: consent_date — entered once, never changed on a form
@UVWRITEONCE

# on: rand_arm — only the data manager may correct the arm
@UVWRITEONCE={"roles":["Data manager"],"message":"Ask the data manager to correct the arm."}

# on: baseline_weight, added to a running study — lock what was held on 1 November
@UVWRITEONCE={"since":"2026-11-01"}
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
@UVASSERT={"assert":"[participant_id]=[participant_id_confirm]","exact":true,"message":"The two ID entries do not match","blockSave":"hard"}
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
| Two `@UVALIDATE` tags, neither with a `when`            | Configuration error — two when-less rules of one kind            |
| Two `@UVASSERT` tags whose `when` says the same thing (`[a]='1' AND [b]='2'` and `([b]='2') and [a]='1'`) | Configuration error — they could never be told apart |
| A `single` and a `pooled` `@UVALIDATE` on one field   | Configuration error — mixed types in one kind                    |
| Two `@UVREQUIRED` tags whose conditions are true at once  | Runtime conflict — a notice, validates nothing, never blocks     |
| `@UVREQUIRED` or `@UVUNIQUE` on a **calc** field | Configuration error — a data enterer cannot fix a calc           |
| `@UVALIDATE` on a dropdown/radio/slider                  | Configuration error — check/format rules are Text and Notes only |

## Messages in several languages

Every tag except `@UVALIDATE` takes a `message`, one string that every reader sees.
In a project that uses REDCap's Multi-Language Management (MLM), `message` may instead
be a map from language id to text.

```text
@UVREQUIRED={"message":{"default":"Phone number is needed","fr":"Le numéro de téléphone est requis"},"blockSave":"hard"}
@UVRANGE={"hard":[40,250],"unit":"mmHg","message":{"default":"Check the blood pressure","fr":"Vérifiez la tension artérielle"}}
@UVASSERT={"assert":"[end_date]>=[start_date]","message":{"en":"End date is before the start date","fr":"La date de fin précède la date de début"}}
```

- The language ids are the ones on the project's Multi-Language Management page, such
  as `en`, `fr` or `fr-CM`. Letter case does not matter.
- `default` is the text for any language the map does not name, and for a page without
  MLM. Without a `default` key, the first language listed is the default: `en` in the
  third example.
- The form or survey shows the text of the language on screen. A regional id with no
  text of its own uses its main language (`fr-CM` uses `fr`), or another region of that
  language, before the default.
- Switching language changes the message at once. The switch does not run the check
  again: it asks the server nothing and writes no field.
- The post-save audit log and the Validation scan report use the default text.
- `@UVEXISTS` `whereMessage` takes a map the same way.
- A rule with a plain string message is unchanged. To translate one, replace the string
  with a map that keeps the old text under `default`. The rest of the rule stays as it
  is.

The labels of an `@UVRANGE` growth chart and page table (`show`, see
[Level 8](#level-8--a-growth-chart-and-a-table-on-the-form-show)) take the same maps,
one per label, and follow the same rules. A switch of language relabels the chart and
the table in place.

The module's own wording stays in English. That covers the line shown when a rule has
no `message`, notes such as "Could not check", the save dialogs and configuration
errors, and the chart's and table's words that have no label of the rule's own: the
default labels, the point's description and the notes shown when nothing is drawn.
The `@UVRANGE` `unit` label and messages typed in the Configure dialog, which takes
one string, stay as written.

A map names at most 50 languages, `default` included. These are refused, with a
configuration error under the field:

```text invalid
# refused: must be a string, or an object of language id to text
@UVREQUIRED={"message":["Phone number is needed","Le numéro est requis"]}
# refused: names language "fr" twice
@UVREQUIRED={"message":{"fr":"Requis","FR":"Obligatoire"}}
# refused: text for "fr" must be a non-empty string
@UVREQUIRED={"message":{"default":"Phone number is needed","fr":""}}
# refused: language id "fr ca" is not valid
@UVREQUIRED={"message":{"default":"Phone number is needed","fr ca":"Requis"}}
```

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
@UVUNIQUE={"also":["a","b","c","d","e","f"]}                     at most 5 "also" fields
@UVUNIQUE={"also":"study_id_scan"}                               also is a list: ["study_id_scan"]
@UVUNIQUE={"with":["site"],"also":["site"]}                      a field is in with or in also, not both
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
| Other fields to look for the value in | Unique | `also` |
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
| `message`       | string or map | generic line   | Your own wording — recommended                         |
| `blockSave`     | string  | `off`        | `off`, `confirm`, `hard`                          |
| `when`          | string  | *(none)*     | Enforce the constraint only while true                  |
| `caseSensitive` | boolean | `false`      | Exact-case text in`assert` and `when`               |
| `exact`         | boolean | `false`      | `=` and `<>` in `assert` compare character for character: `00123` is not `123` |

### `@UVREQUIRED`

| Key               | Type    | Default      | Notes                                                   |
| ----------------- | ------- | ------------ | ------------------------------------------------------- |
| `when`          | string  | *(none)*   | Required only while true; the bare short form sets this |
| `message`       | string or map | generic line | Your own wording                                        |
| `blockSave`     | string  | `off`      | `off`, `confirm`, `hard`                          |
| `caseSensitive` | boolean | `false`    | Exact-case text in`when`                              |

### `@UVUNIQUE`

| Key               | Type            | Default      | Notes                                                          |
| ----------------- | --------------- | ------------ | -------------------------------------------------------------- |
| `with`          | list of strings | *(none)*   | Composite key fields; max 5, no duplicates, must exist         |
| `also`          | list of strings | *(none)*   | Other fields the value is looked for in; max 5, not in `with`, stored the way the field is; not with `record` scope; the same on every tag of one field; with `surveys`, on the field's own instrument |
| `scope`         | string          | `project`  | `project`, `dag`, `event`, `record` (record needs event and instance references enabled); the bare short form sets this |
| `surveys`       | boolean         | `false`    | Opt in to the check on surveys; boolean answer only            |
| `when`          | string          | *(none)*   | Check this record's value only while true; other records are searched whatever their `when` says |
| `message`       | string or map   | generic line | Your own wording                                               |
| `blockSave`     | string          | `off`      | `off`, `confirm`, `hard`                                 |
| `caseSensitive` | boolean         | `false`    | Exact-case text in`when` and the duplicate check             |

### `@UVCHOICES`

| Key               | Type            | Default        | Notes                                                                  |
| ----------------- | --------------- | -------------- | ---------------------------------------------------------------------- |
| `show`          | list of strings | *(one of)*   | Offer only these choice codes; up to 200 codes                         |
| `hide`          | list of strings | *(one of)*   | Offer every code except these. Exactly one of `show`/`hide`        |
| `when`          | string          | *(none)*     | The branch applies while true; a tag without `when` is the fallback  |
| `message`       | string or map   | generic line   | Shown when a saved or selected code is not offered                     |
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
| `message`       | string or map   | generic line   | Replaces the line that names the allowed dates                         |
| `blockSave`     | string          | `off`        | `off`, `confirm`, `hard`; a `from` date on another form never blocks |
| `caseSensitive` | boolean         | `false`      | Exact-case text in `when`                                            |

### `@UVEXISTS`

| Key               | Type            | Default        | Notes                                                                  |
| ----------------- | --------------- | -------------- | ---------------------------------------------------------------------- |
| `in`            | string          | *(none)*     | `record`, or one field reference such as `[specimen_id]`; the shorthand `@UVEXISTS=[field]` sets it |
| `project`       | number or string | *(this project)* | Another project's id, or an alias from this project's settings. Not with `"scope":"dag"` or `"scope":"event"` |
| `event`         | string          | *(any)*      | One unique event name to look in. Not with `"scope":"event"`; with `"in":"record"` only beside `return` or `where`, where it names the event the values are read or tested in |
| `scope`         | string          | `project`    | `project`, `dag` (this record's group), `event` (this entry's event)   |
| `match`         | object          | *(none)*     | `{"target_field":"[field of this record]"}`, up to 5 pairs, same entry as the searched value |
| `return`        | object          | *(none)*     | `{"field there":"[field of this form]"}`, up to 10 pairs; `record` copies the record ID found. Fills the fields from the entry found |
| `where`         | string          | *(none)*     | A condition on the entry found: up to 10 of its fields, against fixed values or each other (Level 7) |
| `surveys`       | boolean         | `false`      | Also check on surveys; refused when a touched field is an Identifier, and with `return` |
| `when`          | string          | *(none)*     | Check only while true                                                  |
| `message`       | string or map   | generic line   | Replaces the "not found" line                                          |
| `whereMessage`  | string or map   | generic line   | Replaces the line for a value saved only in entries that fail `where`; staff only. Needs `where` |
| `blockSave`     | string          | `off`        | `off`, `confirm`, `hard`; "could not check" never blocks          |
| `caseSensitive` | boolean         | `false`      | Exact-case text in `when`, in the lookup and in `where`              |

### `@UVRANGE`

| Key               | Type            | Default        | Notes                                                                  |
| ----------------- | --------------- | -------------- | ---------------------------------------------------------------------- |
| `soft`          | list of two     | *(none)*     | `[low, high]` usual range, both included; `null` leaves an end open; inside `hard` |
| `hard`          | list of two     | *(none)*     | `[low, high]` plausible range, both included; `null` leaves an end open |
| `softBlock`     | string          | `confirm`    | `off`, `confirm`: for a value outside `soft`                       |
| `hardBlock`     | string          | `hard`       | `confirm`, `hard`: for a value outside `hard` or not a number; a calc never blocks |
| `unit`          | string          | *(none)*     | Label shown after the limits in the notes, up to 20 characters         |
| `when`          | string          | *(none)*     | Check only while true; another event or instance needs event and instance references |
| `message`       | string or map   | generic line   | Replaces the generated note for both levels                            |
| `caseSensitive` | boolean         | `false`      | Exact-case text in `when`, and exact sex codes for a growth reference  |
| `reference`     | string          | *(none)*     | A growth reference id, such as `who-wfa`; `soft` and `hard` are then z-scores, from -20 to 20 |
| `sex`           | string          | *(none)*     | With `reference`: the field holding the sex, e.g. `"[sex]"`            |
| `male`, `female` | string         | *(none)*     | With `reference`: the codes that field stores for male and for female  |
| `age`           | object          | *(none)*     | With a reference read by age: `{"dob":"[dob]","at":"[visit_date]"}`, `{"days":"[age_days]"}` or `{"months":"[age_months]"}` |
| `by`            | string          | *(none)*     | With a reference read by length or height: the field holding it, in cm |
| `plausible`     | list of two     | *(none)*     | With `reference`: `[low, high]` in the reference's unit, judged when no z-score can be worked out |
| `measureScale`  | number          | *(none)*     | With `reference`: multiplies the typed value before the z-score, 0.000001 to 1000000 |
| `return`        | object          | *(none)*     | With `reference`: `{"z":"[wfa_z]"}`, a value to a field of this form, up to 16. The values are `z`, `lower`, `upper`, `range`, `percentile`, `valueAtZ:<z-score>` and `valueAtPercentile:<percentile>` |
| `returnLimits`  | string          | `soft`       | With `lower`, `upper` or `range` returned or in a page table: `soft` or `hard`, the limits they are made from |
| `decimals`      | integer         | the measured field's | With `lower`, `upper`, `range` or a value at a z-score or percentile returned or in a page table: 0 to 6, their decimals |
| `percentileDecimals` | integer    | `1`          | With `percentile` returned or in a page table: 0 to 3, its decimals    |
| `show`          | object          | *(none)*     | With `reference`: `{"chart":…}`, `{"table":…}` or both, drawn on the page only, never saved; see [Level 8](#level-8--a-growth-chart-and-a-table-on-the-form-show) |

A limit is a JSON number, or a decimal in quotes for more digits than a JSON
number keeps. `blockSave` is refused.

### `@UVDELTA`

| Key               | Type            | Default        | Notes                                                                  |
| ----------------- | --------------- | -------------- | ---------------------------------------------------------------------- |
| `vs`            | string          | *(none)*     | Required. `previous-event`, `previous-instance`, `first-event`, `first-instance` (this field there), or one field reference such as `[weight_screening]` or `[baseline_arm_1][weight]`. The shorthands and another event or instance need event and instance references |
| `max`           | number          | *(none)*     | The largest change allowed, in the field's unit, 0 or more             |
| `maxPct`        | number          | *(none)*     | The largest change allowed as a percent of the earlier value, more than 0; not applied to an earlier 0. At least one of `max` and `maxPct` |
| `direction`     | string          | `both`       | `both`, `up`, `down`: the changes the limits apply to                  |
| `unit`          | string          | *(none)*     | Label shown after the numbers in the note, up to 20 characters         |
| `when`          | string          | *(none)*     | Check only while true                                                  |
| `message`       | string or map   | generic line   | Replaces the line that names the change and the limit                  |
| `blockSave`     | string          | `off`        | `off`, `confirm`, `hard`; a `vs` saved elsewhere and a calc never block |
| `caseSensitive` | boolean         | `false`      | Exact-case text in `when`                                            |

### `@UVWRITEONCE`

| Key       | Type          | Default         | Notes                                                                              |
| --------- | ------------- | --------------- | ---------------------------------------------------------------------------------- |
| `roles`   | list          | *(none)*        | User roles that may still change or clear the value; by name or unique role name, any letter case, spaces folded, 1 to 20 |
| `since`   | string        | *(none)*        | YYYY-MM-DD, from 00:00 project time: saves before it lock nothing; the value held then is locked |
| `message` | string or map | generic line    | The note under a locked field and the scan's detail line                            |

No `when`, `caseSensitive`, `blockSave` or `references`.

### Keys every tag also accepts

| Key            | Type   | Notes                                                                                  |
| -------------- | ------ | -------------------------------------------------------------------------------------- |
| `references` | object | Named bindings used as `{alias}` in `when`/`assert`; at most 20; feature must be enabled |
| `alternates` | list   | `@UVALIDATE` only: `label`, `pattern`, `algorithm`, `source`, `strip`, `lengths` per entry |
| `message`    | string or map | Every tag except `@UVALIDATE`: one string, or a map of language id to text ([Messages in several languages](#messages-in-several-languages)) |

`@UVWRITEONCE` takes no `references`.

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
| `@UVUNIQUE` `also` fields | 5 |
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
| Languages in one `message` map | 50, `default` included |

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
@UVASSERT={"assert":"[participant_id]=[participant_id_confirm]","exact":true}   type it twice
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
@UVUNIQUE={"also":["study_id_scan"]}                  not in this field nor in study_id_scan of another record

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
@UVRANGE={"reference":"who-wfa","sex":"[sex]","male":"1","female":"2","age":{"dob":"[dob]","at":"[visit_date]"},"soft":[-2,2],"hard":[-5,5]}
                                                                  z-score limits of a growth reference
@UVRANGE={"reference":"who-wfa","sex":"[sex]","male":"1","female":"2","age":{"dob":"[dob]","at":"[visit_date]"},"soft":[-2,2],"hard":[-5,5],"return":{"z":"[wfa_z]"}}
                                                                  ... and write the z-score into a field
@UVRANGE={"reference":"who-wfa","sex":"[sex]","male":"1","female":"2","age":{"dob":"[dob]","at":"[visit_date]"},"soft":[-2,2],"hard":[-5,5],"show":{"chart":true,"table":true}}
                                                                  ... and draw a growth chart and a table under it

# ── @UVDELTA — how far a number may change ──────────────────────────────────
@UVDELTA={"vs":"[weight_screening]","max":5,"unit":"kg"}           at most 5 kg either way
@UVDELTA={"vs":"previous-event","maxPct":10}                       at most 10% since the previous visit
@UVDELTA={"vs":"previous-instance","maxPct":50,"direction":"up"}   a rise of at most 50%
@UVDELTA={"vs":"first-event","maxPct":15,"direction":"down"}       at most 15% below baseline
@UVDELTA={"vs":"previous-event","max":0,"direction":"down"}        may not fall

# ── @UVWRITEONCE — values entered once ───────────────────────────────────────
@UVWRITEONCE                                                       locked by its first save
@UVWRITEONCE={"roles":["Data manager"]}                            the data manager may still change it
@UVWRITEONCE={"since":"2026-11-01"}                                locks what was held on that day

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
@UVASSERT={"assert":"[code]=[code_confirm]","exact":true}              also 007 is not 7
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
