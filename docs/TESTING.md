# Manual REDCap test checklist

The repository's automated tests (see [`../tests/README.md`](../tests/README.md))
prove engine correctness and JS/PHP parity, but they run against Node/PHP stubs —
not a live REDCap. This checklist is the integration pass to run on a real REDCap
instance (≥ 13.7.0) after installing or upgrading the module. Check every box
before tagging a release.

## Release gate — REDCap Repo submission blockers

None of the items below can be produced by this repository's CI; each one must
be done by a person with the release candidate, and the submission is blocked
until all are done and recorded (date, versions, who ran it):

- [ ] **Public repository + SemVer release.** The GitHub repository is public,
      the release commit carries an annotated `vX.Y.Z` tag matching
      `CHANGELOG.md`, and the release archive downloads anonymously. The CI
      `package` job proves the archive shape; a person must prove the public
      URL.
- [ ] **REDCap security scan.** Install the release candidate on the latest
      REDCap, run *Control Center → External Modules → Manage → Module Security
      Scanning*, resolve every real finding, and record the scan output,
      REDCap version, and date. The Repo does not approve modules without a
      current pass.
- [ ] **This checklist executed end-to-end** on a current standard REDCap and
      on the oldest supported LTS, in Chrome, Firefox, and Edge or Safari —
      including the accessibility section with at least one real screen reader.
- [ ] **Performance spot-check** on a large project (1,000+ records, 10+
      configured fields): form save latency and module log growth stay
      acceptable.

Known limitation to disclose at submission: validation messages are English
only. The module does not yet use the framework's JavaScript Module Object /
`tt()` translation plumbing, so Multi-Language Management cannot translate its
strings; MLM language switching must not break validation (re-check the form
after switching), it only leaves the messages untranslated.

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
- [ ] A malformed tag (`@UVALIDATE={"algoritm":"damm"}`) → configuration error in
      the top-of-form notice naming the bad key (all configuration errors collect
      in that one notice, whatever field type they came from).
- [ ] A field both tagged AND in a dialog rule → duplicate-rule configuration
      error (not two validators).
- [ ] Tag on a radio/dropdown/calc field → the "only works on Text or Notes
      fields" error appears in the top-of-form configuration-error notice (there
      is no text input under a non-text field to attach it to).
- [ ] `@UVALIDATE` appears in the Online Designer's **Action Tags** popup with its
      description (declared in `config.json` `action-tags`).

## Conditional validation (`when`)

- [ ] Dialog rule with **Only validate when** `[specimen_type]='2'` on a form
      where `specimen_type` is a dropdown/radio: with the wrong option chosen,
      an invalid ID shows NO message and a `hard` rule does NOT block the save;
      picking the right option makes the verdict (and the block) appear
      immediately, without leaving the field.
- [ ] Same via the tag: `@UVALIDATE={"algorithm":"verhoeff","when":"[stype]='2'"}`.
- [ ] Checkbox condition `[consent(1)]='1'`: ticking/unticking that one option
      toggles validation live.
- [ ] Condition referencing a field on ANOTHER instrument: the rule follows
      that field's SAVED value (edit it on its own form, save, re-open this
      form). On a brand-new record it reads as empty.
- [ ] While the condition is false, an invalid value SAVES and is NOT erased —
      and the module log shows no `invalid-id-saved` entry for it; after making
      the condition true and re-saving, the entry appears.
- [ ] Calc-field ref caveat: a condition on a calc field does not re-evaluate
      the instant the calc silently changes — it refreshes at the next event on
      a watched field or on the validated field itself. Confirm the behavior is
      acceptable for your design (or gate on the calc's inputs instead).
- [ ] Bad conditions are rejected at save time with a clear message: a typo'd
      field name, `datediff(...)`, `[event][field]`, a checkbox ref without its
      `(code)`, an unknown checkbox code.
- [ ] Survey page: the same condition gates the same way for respondents.

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
      (in the top-of-form notice) instead of two validators.
- [ ] A catastrophic regex (e.g. `(a+)+`) in the pattern box shows a configuration
      error in the top-of-form notice; the form stays responsive.

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
      `value_hmac` + raw `record`; `none` stores `record_hmac` and no value;
      `raw` stores both raw; `off` stores no detections. The two hash fields are
      keyed HMACs — the same value gives the same hash within one project and a
      different hash in another project.
- [ ] Audit scope: save an **unrelated instrument** on a record whose validated
      field (on another form) still holds an old invalid value → NO new log
      entry (the audit is scoped to the saved instrument).
- [ ] Force an audit error (e.g. break a rule mid-test) in `none` mode → the
      `uvalidate-audit-error` entry carries `record_hmac`, never a raw record
      id; in `off` mode it carries no record identifier at all.
- [ ] A valid ID via any path produces NO log entry.

## Accessibility (screen reader + zoom)

Run with NVDA or JAWS on Windows (VoiceOver on macOS/Safari if available). The
DOM contract is covered by `tests/a11y_dom_js.cjs`; this section verifies what
assistive technology actually announces.

- [ ] Type an invalid ID, pause → the verdict is announced without moving focus
      (the message region is `role=status`/`aria-live=polite`).
- [ ] The announcement follows the field: focus the input → the screen reader
      reads the current validation message via `aria-describedby`.
- [ ] An invalid field reports "invalid" state (`aria-invalid=true`); fixing the
      value clears it.
- [ ] *Compulsory* block: the dialog is announced, names the field by its
      visible LABEL (not the variable name), and focus lands on the field after
      dismissing.
- [ ] Keyboard only: complete an entry + blocked save + fix + save round trip
      without a mouse.
- [ ] 200% and 400% zoom: messages and chips wrap without loss; nothing needs
      horizontal scrolling on a data-entry form.
- [ ] Pooled chips: junk/duplicate/invalid chips are distinguishable by their
      text marks alone (not color alone).

## Security spot-checks

- [ ] Put `</script><script>alert(1)</script>` in a rule's pattern/strip boxes →
      the settings save is rejected or the string appears fully escaped in the
      page source; no alert anywhere.
- [ ] Put `[<img src=x onerror=alert(1)>]+` as a pattern → the validation message
      under the field shows the text escaped, no alert.
- [ ] Put `(a|aa)+` as a pattern → rejected at settings-save time with a
      catastrophic-pattern message; the form stays responsive.
- [ ] Put `.*.*.*.*.*b` as a pattern → rejected at settings-save time with the
      same message; the form stays responsive. (This polynomial-overlap shape
      passed the pre-`0.5.1` gate and froze the browser tab — SEC-001R.)
- [ ] Configure a pooled rule with lengths `100`–`199` → rejected at save time
      (the work caps allow at most 64-character IDs).
- [ ] Run REDCap's built-in External Module security scan (Control Center) and
      review its findings.
