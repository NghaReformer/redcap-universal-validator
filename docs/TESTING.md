# Manual REDCap test checklist

The repository's automated tests (see [`../tests/README.md`](../tests/README.md))
prove engine correctness and JS/PHP parity, but they run against Node/PHP stubs —
not a live REDCap. This checklist is the integration pass to run on a real REDCap
instance (≥ 13.7.0) after installing or upgrading the module. Check every box
before tagging a release.

## Install / framework

- [ ] Module enables from Control Center without errors on the target REDCap
      version (the module declares `framework-version: 14`; REDCap ≥ 13.7.0).
- [ ] Project-level enable works and the Configure dialog opens.
- [ ] Configure dialog: add one **Single** rule and one **Pooled** rule. All
      sub-settings render and save correctly in both (the pooled-only settings are
      labeled "Pooled only:" and are shown for every rule — confirm the labels are
      clear enough, since `branchingLogic` is deliberately not used inside the
      repeatable sub-settings).

## Configuration channels

- [ ] One rule, many fields: add 3+ fields to a single rule with the picker's
      **+** button — all get the same live validation.
- [ ] Fast entry: type two more field names (comma-separated) into the rule's
      fast-entry box — they validate too; a misspelled name shows a configuration
      error naming it under the rule's fields.
- [ ] Fast entry, ALL names wrong (no picker field): a rule whose fast-entry box
      contains only mis-typed names must NOT vanish silently — a page-level
      "configuration error(s)" notice appears at the top of the form naming the
      unknown fields.
- [ ] `@UVALIDATE` bare tag in a field's Action Tags box → default check runs on
      that field with no module-dialog rule.
- [ ] `@UVALIDATE=verhoeff` → that algorithm runs.
- [ ] `@UVALIDATE={"algorithm":"none","pattern":"FC[0-9]{4}","blockSave":"hard"}`
      → regex-only validation with hard block.
- [ ] Bulk: download the data dictionary, put a tag in `field_annotation` for
      several rows, upload → all tagged fields validate after one upload.
- [ ] A malformed tag (`@UVALIDATE={"algoritm":"damm"}`) → configuration error
      under that field naming the bad key.
- [ ] A field both tagged AND in a dialog rule → duplicate-rule configuration
      error (not two validators).
- [ ] Tag on a radio/dropdown/calc field → the "only works on Text or Notes
      fields" error appears in the top-of-form configuration-error notice (there
      is no text input under a non-text field to attach it to).
- [ ] `@UVALIDATE` appears in the Online Designer's **Action Tags** popup with its
      description (declared in `config.json` `action-tags`).

## Data-entry form (classic project)

- [ ] Type a correct minted ID → green "verified" message.
- [ ] Change one character → red message naming the CHECK character as the problem.
- [ ] With a format pattern set, type a wrong-shape value → red message naming the
      FORMAT as the problem, with progressive "remaining" guidance while typing.
- [ ] Pooled field: paste several IDs (with and without separators) → one chip per
      member; junk, duplicates, and wrong pool size are each flagged.
- [ ] `blockSave = hard`: saving with an invalid ID is blocked, focus moves to the
      field. `confirm`: a Save-anyway dialog appears. `off`: message only.
- [ ] A field listed in two rules shows the duplicate-rule configuration error
      instead of two validators.
- [ ] A catastrophic regex (e.g. `(a+)+`) in the pattern box shows a configuration
      error under the field; the form stays responsive.

## Survey

- [ ] Same verdicts render on a public survey page.
- [ ] `hard` block on a survey: confirm this is acceptable for your study flow (a
      respondent who cannot fix the ID cannot submit — consider `confirm` instead).

## Longitudinal / repeating

- [ ] Longitudinal project: an invalid ID saved on event A is logged with event
      A's id, and a valid ID on event B produces no log.
- [ ] Repeating instrument: save an invalid ID in instance 2 while instance 1 is
      valid → exactly one log entry, `instance = 2`.
- [ ] Repeating event: same check with a repeating event instead of instrument.

## Server-side safety net (API / import / JS-off)

> **Verify this on your instance — do not assume it.** During live testing on
> REDCap 17.0.6, a Data Import Tool import of invalid IDs produced **no** module
> log entry, meaning the `redcap_save_record` hook did not audit that import path
> on that build. Whether it fires for imports/API depends on your REDCap version.
> Run the checks below and confirm the expected log entries actually appear at
> **Control Center → External Modules → View Logs** (module `universal_validator`)
> or by querying `redcap_external_modules_log`. If nothing appears:
> - a `uvalidate-audit-error` entry means the hook fired but the audit hit an
>   error (the message says where) — report it;
> - *no* entry at all means the hook did not fire for that path on your version —
>   treat import/API coverage as unavailable and rely on the client block plus a
>   periodic Data-Quality/export check instead.

- [ ] Save a data-entry **form** with an invalid ID (rule set to *Informational*
      so the save completes) → a `invalid-id-saved` log entry appears with reason
      `check-character` / `format` / pooled reasons. (This is the baseline: if
      even a form save does not log, the hook is not registering.)
- [ ] Import an invalid ID via **Data Import Tool** → check for the same entry.
- [ ] Write an invalid ID via the **API** → check for the same entry.
- [ ] Save a form with **JavaScript disabled** → check for the same entry.
- [ ] Confirm an `@UVALIDATE`-tagged field is audited on the server too (not only
      dialog-rule fields) by importing an invalid value into a tagged field.
- [ ] `log-values` modes behave as documented: `hashed` (default) stores
      `value_sha256` + raw `record`; `none` stores `record_sha256` and no value;
      `raw` stores both raw; `off` stores nothing.
- [ ] A valid ID via any path produces NO log entry.

## Security spot-checks

- [ ] Put `</script><script>alert(1)</script>` in a rule's pattern/strip boxes →
      no alert anywhere; the string appears fully escaped in the page source.
- [ ] Put `[<img src=x onerror=alert(1)>]+` as a pattern → the validation message
      under the field shows the text escaped, no alert.
- [ ] Run REDCap's built-in External Module security scan (Control Center) and
      review its findings.
