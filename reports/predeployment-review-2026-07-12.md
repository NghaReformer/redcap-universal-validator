# Predeployment Adversarial Review

> Condensed archive of the review received on 2026-07-12: the finding table,
> verdicts, and measured evidence the fixes are validated against. The
> remediation state is recorded in `CHANGELOG.md` (0.5.0); the open
> release-gate items live at the top of `docs/TESTING.md`.

**Module:** Universal Field Validator — IDs, codes & patterns
**Repository:** `redcap-universal-validator`
**Reviewed commit:** `0263a93` (`main`)
**Review date:** 2026-07-12
**Review posture:** Pre-submission gate for the REDCap Repository of External Modules
**Overall verdict:** **DO NOT SUBMIT in the current state**

## 1. Executive summary

The module has a strong core: its check-character implementations are carefully
cross-tested, the PHP and JavaScript engines agree on the supplied fixtures, the
configuration-to-script boundary is materially hardened against XSS, and the
documentation is unusually candid about the post-save nature of the server hook.

That core is not yet enough for a REDCap Repo submission. This review found:

- **4 submission blockers**
- **7 high-severity findings**
- **11 medium-severity findings**
- **4 low-severity findings**

The most important issues are:

1. There is no public, tagged release available to the submission process, and no
   evidence of a passing REDCap security scan on the latest REDCap release.
2. The module's own live test notes say Data Import Tool writes on REDCap 17.0.6
   produced no audit log. The Repo-facing description nevertheless advertises a
   server-side audit of API and import writes.
3. A JavaScript-valid regex can pass the ReDoS gate and freeze the browser with
   only 42 attacker-controlled characters.
4. Project IDs are explicitly threaded into dictionary/data reads but not into
   `getSubSettings()` or `getProjectSetting()`, undermining rules and privacy-mode
   resolution in exactly the API/import contexts the code says are unreliable.
5. The exception path logs a raw record ID even in strict or logging-off modes.
6. The event fallback can validate a value from the wrong longitudinal event.
7. Every save re-reads and revalidates every configured field across the project,
   including unchanged fields on other instruments, causing duplicate logs and
   unnecessary server load.
8. Dynamic validation results are not exposed as status/error relationships to
   assistive technologies; the current implementation is likely a WCAG 2.2
   Success Criterion 4.1.3 failure.

The recommended disposition is to fix all Blocker and High findings, complete a
live REDCap/browser/accessibility test matrix, run REDCap's current security
scanner, then perform a second independent submission-gate review.

## 2. Findings summary

| ID | Severity | Area | Finding |
|---|---|---|---|
| PRE-001 | Blocker | Submission | No public SemVer release/tag is available to the REDCap submission process. |
| PRE-002 | Blocker | Security assurance | REDCap's mandatory current security scan has not been run or evidenced. |
| PRE-003 | Blocker | Integration assurance | No completed recent live REDCap/browser test matrix; a documented import test already failed. |
| PRE-004 | Blocker | Claims/compliance | Repo-facing API/import audit claim is stronger than observed coverage. |
| SEC-001 | High | ReDoS/availability | Regex safety gate misses a pattern that freezes the browser at 42 characters. |
| SEC-002 | High | Privacy/audit | Project ID is omitted from project-setting reads in unreliable contexts. |
| SEC-003 | High | Privacy | Audit-error logging exposes raw record IDs regardless of log privacy mode. |
| COR-001 | High | Longitudinal correctness | Cross-event fallback can audit the wrong event's value. |
| PER-001 | High | Server/logging | Every save audits all configured fields, including unrelated instruments. |
| PER-002 | High | Client/server availability | Pooled parser bounds are insufficient; a legal rule took 9.25 seconds per parse. |
| COR-002 | High | Configuration integrity | Settings are not validated atomically; invalid rules fail open or abort later audits. |
| COR-003 | Medium | Field compatibility | Settings picker accepts unsupported non-text fields. |
| COR-004 | Medium | Runtime parity | JavaScript and PCRE/Unicode semantics are not equivalent beyond fixture scope. |
| SEC-004 | Medium | Privacy | Unsalted SHA-256 identifiers remain enumerable and cross-project linkable. |
| SEC-005 | Medium | Log availability | Invalid-save logs are unthrottled and repeatedly duplicated. |
| A11Y-001 | Medium | Accessibility | Dynamic results lack live-region, error-state, and descriptive relationships. |
| A11Y-002 | Medium | Accessibility | Pooled "junk" chip text fails WCAG AA normal-text contrast. |
| UX-001 | Medium | Usability | Configuration errors appear too late and can be shown to unrelated survey users. |
| UX-002 | Medium | Usability | Settings surface is overloaded and contains undocumented normalization edge cases. |
| UX-003 | Medium | Enforcement | Browser "compulsory" mode is bypassable and may trap users on readonly fields. |
| CMP-001 | Medium | REDCap/MLM | Hard-coded globals/messages and one-time binding do not follow modern JSMO/MLM patterns. |
| TST-001 | Medium | Assurance | Current tests mask key context bugs and do not cover declared compatibility. |
| COR-005 | Low | Edge case | Prototype-inherited field names can silently drop client validators. |
| CI-001 | Low | Supply chain | GitHub Actions are tag-pinned only and token permissions are not minimized. |
| DOC-001 | Low | Documentation | Test counts and several user-facing claims are stale or internally inconsistent. |
| PKG-001 | Low | Packaging | No reproducible release/package check enforces the required module directory shape. |

## 3. Measured evidence (as received)

```text
ReDoS probe: (a|aa)+ against 42 x "a" + "b"
gate=false; process timed out after 3 seconds                   FAIL

Pooled parse, 8,192 chars, length range 100..199
~9,250 ms for one parse                                        FAIL

Contrast: #b26a00 on #fbf6e8
3.93:1 at 12px                                                 FAIL WCAG AA normal text
```

## 4. Minimum acceptance criteria before submission (as received)

- [ ] PRE-001 through PRE-004 closed with evidence.
- [ ] All High findings fixed and regression-tested.
- [ ] No known regex can exceed an agreed client/server time budget at allowed
      input/config limits.
- [ ] Exact event/instrument/instance audit behavior proven.
- [ ] Strict/off logging modes proven on success and exception paths.
- [ ] WCAG status/error behavior tested with at least one screen reader.
- [ ] Recent REDCap standard and LTS manual/integration matrices signed.
- [ ] API/import wording matches verified hook coverage.
- [ ] Latest REDCap External Module security scan passes.
- [ ] Public GitHub SemVer release and correctly named package are anonymously
      downloadable.
- [ ] Final review is performed against the immutable release tag.

*(The full narrative findings, threat model, and remediation phases from the
original report are tracked one-to-one in the 0.5.0 changelog entry; this
archive keeps the identifiers, verdicts, and measured evidence that the fixes
are validated against.)*
