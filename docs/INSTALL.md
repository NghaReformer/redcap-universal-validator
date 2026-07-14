# Install and configure

## 1. Install the module (REDCap administrator, once per server)

**From a downloaded copy**

1. Put the module folder on the server under `modules/` named with its version,
   e.g. `redcap/modules/universal_validator_v0.4.0/` (the folder must contain
   `config.json` at its top level).
2. In the **Control Center → External Modules → Manage**, the module appears as
   available. Click **Enable**.

**From the REDCap repository** (once published): Control Center → External
Modules → **View modules available in the repository** → find *Universal Field
Validator* → **Download** → **Enable**.

## 2. Enable it on a project (project administrator)

Project **External Modules** page → **Enable a module** → *Universal Field
Validator*.

## 3. Add validation rules

One rule = one kind of validation, applied to any number of fields. Two places to
declare rules; they mix freely, and a field claimed twice shows a configuration
error rather than running two validators.

### A. The Configure dialog (project settings)

Add one **Validation rule** per kind of validation:

- **Rule label** — optional, your own name for the rule (e.g. "Specimen IDs").
- **Field type** — *Single value* (one ID/code per field) or *Pooled* (several
  IDs in one box).
- **Field(s)** — pick fields with the picker and click its **+** to add more
  fields to the same rule, and/or type extra field names into the **fast entry**
  box (comma- or space-separated; unknown names show a configuration error).
- **Check-character method** — must match how the IDs were minted. The generator's
  default is *ISO 7064 Mod 37,36*. Validating a plain format with no check
  character (study codes, legacy IDs)? Choose *No check character* and set the
  format pattern.

### B. `@UVALIDATE` field annotations (bulk setup)

Tag fields where you already design them — the **Action Tags / Field Annotation**
box in the Online Designer, or the `field_annotation` column of the data
dictionary CSV. To validate 50 fields, fill one spreadsheet column and upload the
dictionary once.

```text
@UVALIDATE                                            default check (ISO 7064 Mod 37,36), message only
@UVALIDATE=verhoeff                                   pick the algorithm
@UVALIDATE={"algorithm":"none","pattern":"FC[0-9]{4}","blockSave":"hard"}
@UVALIDATE={"type":"pooled","expectedIds":3}          pooled field, warn unless 3 IDs
```

JSON keys: `type`, `algorithm`, `source`, `pattern`, `strip`, `keepChars`,
`idLengths`, `idMinLen`, `idMaxLen`, `expectedIds`, `blockSave`, `when`, `note`.
Use double quotes inside the JSON. A malformed tag (typo'd key, unknown
algorithm, bad JSON) — or a tag on a non-Text/Notes field — shows a configuration
error in a notice at the top of the form. Fields with identical tags are grouped
into one rule automatically. Tags work on Text and Notes fields.

The optional `when` key makes a rule conditional — it validates only while a
REDCap-style condition is true, e.g.
`@UVALIDATE={"algorithm":"verhoeff","when":"[specimen_type]='2'"}`. The
Configure dialog offers the same thing as an "Only validate when" box per rule.
See the README's "Conditional validation" section for the supported syntax and
the exact semantics (a false condition skips the rule; it does NOT erase the
value).

The `algorithm` value accepts case-insensitive shorthands so you need not type
the full name: `3736` (or `37,36`, `mod37_36`) → `iso7064_mod37_36`, `9710` →
`iso7064_mod97_10`, `112` → `iso7064_mod11_2`, `mod10` → `luhn`, the digit-only
weighted schemes `gs1`/`aba`/`mrz`/`isbn` → `gs1_mod10`/`aba_mod10`/`mrz_mod10`/
`weighted_mod11`, `regex`/`format` → `none`, and so on. The full names still work;
see the table in the README for the complete list.

### Shared rule options
- **On an invalid value** — *Informational*, *Advisory*, or *Compulsory*. All
  three are **browser** behaviors; API and data-import writes cannot be blocked
  from this module (see the audit notes below). *Compulsory* never traps a
  read-only field — a person who cannot edit the value is not blocked by it.
- **Advanced (optional)** — payload source (whole ID / digits only / trailing
  number), a format regex (printable ASCII, matched against the UPPERCASED
  value; catastrophic shapes — `(a+)+`, `(a|aa)+`, and overlapping unbounded
  quantifiers like `.*.*` or `[0-9]*[0-9]*` — are rejected when you save the
  settings), and separators to ignore. For *Pooled* rules only:
  exact ID length(s) or a min/max range (up to 64 characters per ID), extra
  characters to keep, and the expected pool size.

Invalid rules are rejected when you press Save in the Configure dialog, with a
message naming the rule and the problem — a bad rule never reaches data-entry
staff silently.

There is also one project-level setting, **How to log invalid values caught by
the post-save audit**. Every mode except *off* logs field, instrument, event,
and instance; the modes differ in how the invalid value and the record ID are
stored: *hashed* (default) stores a keyed hash of the value (HMAC-SHA-256 with
a module-held secret, scoped to the project) with the raw record ID so staff
can fix the record; *strict* stores no value and applies the keyed hash to the
record ID too (for sites where record IDs are themselves identifying); *raw*
stores both readable; *off* logs no detections (identifier-free audit ERRORS
are still logged so failures stay visible). A keyed hash is pseudonymization,
not anonymity — treat the module log as identifying data in your access and
retention policies. Pick the option your data-governance policy allows before
turning the module loose on real IDs.

Save. Open any record with those fields and type an ID — you'll see the live
verdict. A field listed in more than one rule shows a configuration error instead
of running two conflicting validators; give each field exactly one rule.

## 4. Confirm it works

- Type a correct minted ID → green "verified".
- Change one character → red "typo?" (and, if the format is still valid, it says
  the check character is the problem, not the format).
- Set a rule to *Compulsory* and try to save with a bad ID → the save is blocked
  until you fix it.

## Notes

- The module injects its own JavaScript; the JavaScript Injector module is **not**
  required and should not also carry an ID-check script for the same fields.
- The server-side audit validates the saved value with the same rules as the
  client — single and pooled fields, check character, format pattern, and
  regex-only — and logs any invalid value to the module log, scoped to the
  instrument that was saved. It fires *after* the write, and only where REDCap
  invokes `redcap_save_record`: **verify on your own instance whether that
  covers Data Import Tool and API writes** (the check is in
  [`TESTING.md`](TESTING.md)) before relying on it for those paths.
- Format-pattern auditing applies to printable-ASCII values; a value containing
  other characters is left to the client and check-character validation rather
  than risking a browser/server disagreement.
- Requires REDCap 13.7+ and PHP 7.4+ with the `mbstring` and `ctype` extensions
  (both ship enabled in standard PHP builds, but some minimal builds omit them;
  declared in `config.json`; External Module Framework version 14).
- After installing or upgrading, run the manual integration checklist in
  [`TESTING.md`](TESTING.md) on a test project.
