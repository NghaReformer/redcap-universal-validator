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
`idLengths`, `idMinLen`, `idMaxLen`, `expectedIds`, `blockSave`, `note`. Use
double quotes inside the JSON. A malformed tag (typo'd key, unknown algorithm,
bad JSON) shows a configuration error under that field. Fields with identical
tags are grouped into one rule automatically. Tags work on Text and Notes fields.

### Shared rule options
- **On an invalid ID** — *Informational*, *Advisory*, or *Compulsory*. *Compulsory*
  blocks the save **in the browser**; API and data-import writes cannot be blocked
  from this hook, but they are validated and logged server-side (see below).
- **Advanced (optional)** — payload source (whole ID / digits only / trailing
  number), a format regex (avoid nested quantifiers such as `(a+)+`), and
  separators to ignore. For *Pooled* rules only: exact ID length(s) or a min/max
  range, extra characters to keep, and the expected pool size.

There is also one project-level setting, **How to log invalid IDs caught on the
server**. Every mode except *off* logs field, instrument, event, and instance;
the modes differ in how the invalid value and the record ID are stored:
*hashed* (default) stores a SHA-256 of the value with the raw record ID so staff
can fix the record; *strict* stores no value and hashes the record ID too (for
sites where record IDs are themselves identifying); *raw* stores both readable;
*off* logs nothing. Pick the option your data-governance policy allows before
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
- The server-side check (for API/import/JavaScript-off paths) validates the saved
  value with the same rules as the client — single and pooled fields, check
  character, format pattern, and regex-only — and logs any invalid ID to the
  module log. It fires after the write, so treat it as detection/audit; the
  *Compulsory* client block is what prevents a human form save.
- Requires REDCap 13.7+ and PHP 7.4+ with the `mbstring` extension (declared in
  `config.json`; External Module Framework version 14).
- After installing or upgrading, run the manual integration checklist in
  [`TESTING.md`](TESTING.md) on a test project.
