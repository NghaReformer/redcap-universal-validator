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

- [ ] Import an invalid ID via Data Import Tool → a module log entry appears
      (Control Center → External Modules → view logs, or the project's module
      logs) with reason `check-character` / `format` / pooled reasons.
- [ ] Write an invalid ID via the API → same.
- [ ] Save a form with JavaScript disabled → same.
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
