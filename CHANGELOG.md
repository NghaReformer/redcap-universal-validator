# Changelog

Release notes for Universal Field Validator, a REDCap external module.
Git tags identify release snapshots. Release candidates are intended for development-project verification before production adoption.

## Unreleased

### Added

- **`@UVWINDOW`: date windows.** A date must fall inside a window around another date, and/or must not be after today. `@UVWINDOW={"from":"[visit_date_bl]","window":[21,35]}` accepts a visit 21 to 35 days after baseline, both ends included; `"window":[0,null]` leaves the latest end open; `"unit"` is `days` (default) or `weeks`, and also `minutes` or `hours` on datetime fields; `{"notFuture":true}` refuses a date after today. Works on Text fields with date, datetime or datetime-with-seconds validation in any display format, and the `from` field must be the same kind of date. The message shows the allowed dates the way the field shows them.
  - "Today" is the server's date. The page gets the server clock and advances it by how long the page has been open, so a computer whose clock is wrong cannot accept tomorrow's date and a page left open past midnight moves to the next day. The save-time recheck uses the current clock. A computer clock moved forward while the page is open moves it too; the post-save audit reads the server clock and still logs the date.
  - New project setting **Time zone for @UVWINDOW "notFuture"** (`window-timezone`): an IANA time zone name such as `Africa/Douala` that decides "today" when the study's time zone differs from the server's. Blank uses the server's. A name PHP does not know is refused when the settings are saved.
  - A blank value, or a blank `from` date, checks nothing; a date still being typed shows no verdict.
  - A `from` date on another form is read when the page opens: its saved value is used if the user may read that form, so the window part is advisory and never blocks. On a survey, or without rights to that form, the value is never sent: the browser checks only `notFuture`, staff see a note that the window is checked when the record is saved, and the post-save audit checks it. `notFuture` keeps the rule's `blockSave` in both cases, since "after today" does not depend on the `from` date.
  - A `from` that names the field itself is refused, including `[event-name][field]` and `[field][current-instance]`. A `from` in another event that happens to be the entry being saved (`[baseline_arm_1][visit_date]` on the baseline visit) gives that entry no window verdict. A `from` entry that does not exist yet counts as a blank `from` date.
  - On a multi-page survey the browser reads a `from` date only on the page being shown; with the `from` field on an earlier page the window is checked after the save.
  - With event and instance references enabled, `from` may name another event: `[baseline_arm_1][visit_date]`.
  - The post-save audit logs `type: window` with reason `window-early`, `window-late` or `future`. Saving only the form that holds the `from` date re-checks the windows counted from it. The Validation scan reports the same findings, with "Date outside allowed window" or "Date in the future" in the Issue column and the missed bound in the detail line. Each scan request, and each part of a durable scan, reads the clock when it runs.
  - A datetime field without seconds reads a `from` date that has seconds to the minute, so the bounds it is judged by are the bounds its message shows.
  - The rule re-checks when REDCap's date picker or its Today and Now buttons change the field without a native change event.
  - `tests/window_fixture.json` drives the server verdict (`TemporalLogic::windowVerdict`) and the browser one (`QRID_windowVerdict`), so the two cannot disagree about a date.
- **`@UVEXISTS`: values that must already exist.** `@UVEXISTS=record` accepts only the ID of a saved record; `@UVEXISTS=[specimen_id]` accepts only a value saved in `specimen_id` in some record. The JSON form adds `event` (look in one event), `scope` (`project`, `dag` or `event`), `match` (look only at entries whose target field equals a field of this record, up to 5 pairs), `surveys`, `when`, `message` and `blockSave`. Works on Text, dropdown, radio and SQL fields.
  - The page asks the server through a new module action, `exists-check`, when the value is entered or changed and once when the form opens, never on each keystroke; typing clears the last answer. Answers are found (green), not found (red, enforced per `blockSave`) and could not check (amber, never blocks). Where the rule looks (`in`, `event`, `scope`, `match`) is not sent to the page; the server reads it from the stored rule. A new `serverKeys` list in `php/modes.json` names such keys, and `ModeRegistry::clientShape` removes them from the page config.
  - The comparison is exact on stored values: trimmed, case-sensitive, dates as Y-M-D whatever the field's display format. The searched field and the field must hold the same kind of date, which is checked when the rule is saved. The server first asks REDCap for candidate entries only (`filterLogic`); a miss there is confirmed by a full read of the searched fields, and a read that fails answers "could not check", never "not found".
  - Signed-in users are answered only when they may open the forms that hold the searched field and the `match` fields. Staff see the record a value was found in unless they are in a Data Access Group and the record is not; a record-ID lookup never names a record. `match` fields not on the page are read from the saved record.
  - Surveys are opt-in (`"surveys":true`), answered found / not found only, under the survey rate limit, and refused when any field the lookup touches is an Identifier or the Identifier flags cannot be read.
  - The post-save audit logs `type: exists`, reason `not-found`; a lookup that cannot finish is logged as a rule problem. The Validation scan reads the searched field once per rule and request into an index and checks every record against it, with "Not found in its source" in the Issue column. A scan confined to one Data Access Group reports a rule that looks across groups as not evaluated.
  - The browser state for "could not check" is a new amber outline (`warn` in `QRID_setModeState`), which sets no `aria-invalid`. The AJAX transport helper is now `QRID_ajaxTransport`; `QRID_uniqueTransport` stays as an alias.
  - `ruleFindings` takes the record's DAG from the scan, so a `"scope":"dag"` rule reads no extra data per record.
  - Clicking Save re-checks the field and holds the save while an answer is on its way, so a value typed and saved at once is checked before the save is decided. After 10 seconds without an answer the value counts as could not check. Save does not ask again for a value that got no answer.
  - A branched rule is answered from the branch the page is enforcing: the page sends the values its `when` conditions read (`cond`), and the server picks the branch from them for fields of the form, from saved values for the others. A new record is answered too.
  - A user in a Data Access Group is answered only on records of their own group. A "not found" from a rule that looks across groups is kept only when the module sees other groups' records in the same request; otherwise it is could not check, and the audit reports a rule problem rather than a violation.
  - The searched field and the field must be stored the same way: a datetime to the minute and one to the second, or two times of different precision, are refused when the rule is saved.
  - Survey lookups may cause at most 60 reads of a whole searched field a minute per project (a new rate-bucket tier); past that, and when the counter cannot be kept, a miss answers could not check.
  - On a survey page, a misconfigured `@UVEXISTS` rule carries a general error text, and the "no access" reason staff see names no form or field.
  - Match fields the page does not carry (another form, another page of a multi-page survey) are left out of the request and read from the saved record. Saving the form that holds a match field re-checks the lookup.
  - The scan index keeps one hit per (group, event) under each value, so a searched field with few distinct values no longer slows the index down. Scan mode is passed per call (`$meta['existsIndex']`) and the index key names the project. A group-confined scan no longer reports findings for a rule it lists as not evaluated.
  - A durable scan with `@UVEXISTS` rules claims coverage through its change fence only when the change log lists no record inside the window (new `window-changes` count in the catch-up); otherwise it finishes as manifest-complete and says why.
  - Typing in a text field that a `when` condition reads asks nothing until the field is left. The answer cache keeps 16 answers keyed by value, match values and branch values. An autocomplete dropdown keeps its message after the visible widget, and the widget gets the outline.

### Fixed

- **`@UVUNIQUE` on a branched rule asked the server about another branch.** The server chose the branch from the saved record while the page used the values on the form, so a selector changed and not saved yet, or a new record, got an answer for the wrong branch or none. The page now sends its branch values, as for `@UVEXISTS`.
- **A late `@UVUNIQUE` answer could paint over the current one.** A cached answer now supersedes any request still in flight.
- **A `@UVUNIQUE` or `@UVEXISTS` value typed and saved at once was decided before its answer arrived.** Save now re-checks and waits for it.
- **A branch conflict in `@UVWINDOW` or `@UVEXISTS` lifted an `@UVALIDATE` block on the same field.** The conflict notice now clears only the guard of its own mode.
- **`@UVUNIQUE` on an autocomplete dropdown put its message between the dropdown and REDCap's widget.** It now sits after the widget, which gets the outline.
- **`@UVUNIQUE` `with` fields on another page of a multi-page survey were compared as blank.** The page leaves them out and the server reads the saved values.

### Security

- **`unique-check` and `exists-check` refuse a record outside the caller's Data Access Group.** Both read saved values of the record the request names; a group user naming another group's record could learn about its values. Such requests are refused, and so is a record whose group cannot be read.
- **`unique-check` now checks the signed-in caller's form rights and rate.** A signed-in user who could not open the form holding a `@UVUNIQUE` field (or one of its `with` fields) could still ask whether a value was used there. The endpoint now answers only about forms the user may open, and refuses when the rights cannot be read. Signed-in lookups are limited to 60 a minute per session, one budget shared with `exists-check`; the survey limits are unchanged.

### Changed

- `@UVREQUIRED`, `@UVUNIQUE` and `@UVCHOICES` share one check of the `when`, `blockSave` and `message` keys (`AnnotationRules::checkCommon`), with the same messages as before.
- The validation modes (one per action tag) are now listed in one file, `php/modes.json`: the tag, its rule types, the field types it accepts, its parser, validator and findings method, the keys its rules carry, and its scan labels. The server reads it through `php/ModeRegistry.php`. `tests/gen_mode_registry.cjs` writes the browser's copy into `js/engine.js`, and CI fails when that copy is stale. Before, each of these lists was kept by hand in about a dozen places. Behaviour is unchanged: `tests/registry_php.php` holds copies of the replaced lists and fails if the registry stops reproducing them.

### Fixed

- A stored Configure-dialog rule whose type is `choices` (a mode the dialog has no boxes for) now shows a configuration notice naming the `@UVCHOICES` action tag. It used to be assembled from the check-character boxes and refused with an unrelated message.
- **Long numbers no longer stall a save or a scan.** A data-entry user could type a number of up to 4,096 digits into a field read by an average, sum or minimum/maximum. Each member was then scaled and re-summed for every entry of the record, one digit at a time, and none of that work counted against the evaluation budget. With 160 repeat entries of 4,000 digits one audit took 166 seconds and one scan 260 seconds. Multiplication and addition now work in machine-integer blocks, and decimal arithmetic on values of 64 or more characters is charged to the record's budget each time it runs. The same record now finishes in about 0.2 seconds; entries past the budget are reported as unchecked ("context limit or evaluation budget reached") instead of being decided. Values shorter than 64 characters cost nothing extra, so ordinary data is checked exactly as before. (Wargame 2026-09-23, P1.)
- **A long `events` list no longer stalls a save.** A binding's `events` list accepted repeated entries, an entry for an event with no saved row cost no budget, and every relative entry re-scanned all of the project's events for every entry of the record. A 64 KB annotation repeating `previous-event-name` 2,900 times made one save take 70 seconds at 50 entries. An event listed twice is now refused when the rule is configured ("lists event … more than once"), event names are resolved once per request, and an event with no saved row costs one unit like any other read. The largest list still accepted, every event once plus the relative selectors, takes about 30 ms for that record. (Wargame 2026-09-23, P2.)

- A `when` condition on `@UVCHOICES` that names a field the project does not have is now reported as an `@UVCHOICES` configuration error. It was reported under `@UVALIDATE`, the wrong tag.
- **`@UVUNIQUE` on a D-M-Y or M-D-Y date field now finds duplicates while the user types.** The page sends a date the way the field shows it (`31-12-2024`) and REDCap stores `2024-12-31`, so the live check compared the two and always answered "Not used before". The server now rewrites a complete date or datetime into the stored form before it compares, for the field and for each `with` field; a date still being typed is compared as typed. A record-scope rule (`@UVUNIQUE=record`) had the same mismatch on the page and now gets the other entries' dates in the field's display format. The post-save audit and the Validation scan read stored values on both sides and were not affected.

### Upgrade note

- A binding whose `events` list names the same event twice now shows a configuration notice and is not evaluated until the repeat is removed. Removing it does not change the rule's result, because members were already counted once.

### Documentation

- The `alternates` section of `docs/action_tag_validation_examples.md` is rewritten as a set of tested recipes: several studies in one field, the same length with and without a check character, legacy and checked IDs of one study, formats with different separators, more formats than the 8-entry limit, per-entry `source` and `strip`, `expectedIds`, and choosing the format list with `when`. It also lists every rule refused when saved, with its message, and the rules that save but may not do what the designer meant.
- Each recipe carries a block of tested values (`input => result`). CI runs every line through the server verdict (`tests/docs_examples_php.php`) and the browser engine (`tests/docs_examples_js.cjs`), and each refused example must be refused for the reason printed beside it.
- Phase 2 example patterns use site digits `[1-9]` (`SK[1-9]`, `DT[1-9]`, `ST[1-9]`), matching the studies' current ID registry.
- The save check compares the characters kept by each entry of a one-ID `alternates` rule as well as a pooled one, so a one-ID rule mixing `-` and `/` separators needs `keepChars`. This is now documented.
- `@UVWINDOW` is documented in the README, the user guide FAQ, `docs/action_tag_validation_examples.md` (five levels, recipes, key and limit tables, cheat sheet, and twelve refused examples that CI checks), and `tests/README.md`. The examples page no longer says "five tags".
- `docs/testbed/WINDOW_LIVE_ACCEPTANCE.md` is the live run sheet for pid 149, with its fixture `docs/testbed/uvwindow_test_fields.csv` (18 fields on one new instrument). It also covers the `@UVUNIQUE` D-M-Y fix.

## 2.1.0-rc.2 — 2026-10-07

**Same-length families with and without a check character.** A pooled rule with `alternates` can now mix a format-only family and a check-bearing family of the same length when their patterns cannot match the same value. Real projects need this. The HIV viral-load field takes `C`–`Z` codes that carry a check character and legacy `A`/`B` codes that do not, all six characters long. Until now that rule was refused when saved.

### Changed

- **Check-bearing alternates own their shape.** A value whose shape matches any check-bearing alternate must pass that alternate's check character; format-only alternates are tried only when no check-bearing pattern matches. This applies to single-value and pooled fields, in the browser and on the server, and does not depend on declaration order. In a pooled field ownership is per declared length. Previously the first accepting alternate won.
- **Exact overlap test.** The save-time check for a format-only pattern that accepts a value a check-bearing pattern also accepts now searches both patterns for a common value instead of sampling two members of the check-bearing one. The sampler missed overlaps such as `SK[0-9]+[0-9A-Z]` beside `[A-Z0-9]{9}` (both samples were four characters long) and `[A-Z][0-9]{4}[0-9A-Z]` beside `[B-Y0-9][0-9]{5}`. A backreference, or lookaround whose effect cannot be settled, still makes the rule refused.
- The pooled refusal of a format-only alternate sharing any length with a check-bearing one is replaced by that test, run at each length the two share. Pooled alternates that share no length are not compared.
- The overlap refusal stays even with shape ownership. Ownership only covers mis-scans that keep the check-bearing shape; one that breaks it (an `O` for a `0`, a dropped digit) would land in an overlapping format-only shape and pass on format.
- Overlap messages now describe that failure instead of "whichever alternate accepts first wins", which is no longer how alternates are chosen.
- Patterns using `{,n}` or a quantifier with spaces (`{2, 3}`, `{ 2}`) are refused when saved, in the browser and on the server. JavaScript and PCRE before 10.43 read them as literal text; PCRE 10.43 and later (bundled with PHP 8.4) read them as quantifiers, so a server upgrade would have split the two runtimes. Write `{0,n}` or `{2,3}`, or escape the brace (`\{`) for literal text.

### Fixed

- Pooled cleaning no longer treats regex syntax as separator characters. The comma of a `{n,m}` quantifier and the `:`, `=`, `!`, `<`, `>` and group name of a `(?...` prefix were kept, so a pooled `[A-Z][0-9]{6,7}` field reported the commas typed between IDs as junk, and an alternate using `(?:...)` or a lookahead beside one that did not was refused with "disagree about which characters survive cleaning". Both runtimes now share one reader, `CheckCharacter::patternLiterals` and its JavaScript twin.
- An escaped separator (`\:`, `\/`, `\x3A`) is now kept by pooled cleaning like an unescaped one. It used to be dropped, so every member of a pooled `A\:B[0-9]{3}` field read as junk.
- Rules whose alternates agreed about kept characters only because the old reader counted regex syntax (the `:` of `(?:` beside a literal `:`) stay accepted when the field's cleaning is unchanged by the new reader.

### Upgrade note

- Some rules that saved under 2.1.0-rc.1 overlap in a way the old sampler missed and are now refused, with a message naming both entries and an example value. An existing field with such a rule shows a configuration notice and is not validated until the overlapping format-only pattern is narrowed. Each of these rules let a shape-breaking mis-scan of the check-bearing family pass on format. In a 6,000-rule random comparison, 161 rules moved from accepted to refused, every one with a confirmed common value.
- A rule whose pattern uses `{,n}` or a spaced quantifier is now refused the same way; rewrite it as `{0,n}` or without the spaces.
- A rule where one alternate writes a separator escaped (`LG\/[0-9]{5}`) and no other alternate keeps that character is now refused with "disagree about which characters survive cleaning". Before this release the escaped character was dropped by cleaning, so that alternate's IDs could not be read, while a sibling's IDs separated by that character read clean. Keeping it now would turn those separators into junk for the sibling, so the designer has to choose: add the character to `keepChars`, or stop using it as a separator. In 3,000 random pooled rules built around escaped separators, 36 moved from accepted to refused for this reason.
- No other rule moves from accepted to refused, and no input that parsed clean under 2.1.0-rc.1 parses unclean under a rule that still saves (checked over 4,500 random pooled rules built around separators, escapes and group syntax).

### Removed

- `CheckCharacter::patternWitness()` and `patternWitnesses()`, replaced by `CheckCharacter::patternOverlap()`.

## 2.1.0-rc.1 — 2026-09-18

**Release candidate: expanded validation and scan reliability.** This release combines case-insensitive conditions, validation across events and repeating instruments, dropdown/autocomplete choice filtering, and the merged scan-remediation work.

### Release history

The last previously published Git tag is `v1.10.0`. Earlier changelog entries labelled `1.11.0` and `2.0.0` described parallel development milestones; neither had a published Git tag. Those changes are included here. The `2.1.0` release line preserves the repository's established `2.x` development numbering and distinguishes this combined release from the scan-remediation milestone. No retrospective stable releases have been created.

This release includes behavior changes from `v1.10.0`; it is not a patch-only update. Detailed historical notes remain available in the [changelog archive](CHANGELOG-ARCHIVE.md).

### Added

- **Cross-event and repeating-data validation**, enabled through a project setting that is off by default. References support named and relative events, explicit and relative instances, and checkbox values within qualified references.
- **Shared-key matching between independent repeating instruments**, including composite keys. Matching preserves case and leading zeros; missing or ambiguous matches are reported as unresolved.
- **Collection predicates and aggregates**: any/all, existence, row/populated/distinct counts, sum, minimum, maximum, and average. Decimal comparisons remain exact, and average comparisons avoid rounding.
- **Typed date and elapsed-time bindings**, with calendar validation, configured display formats for live inputs, and signed elapsed-time comparisons.
- **Within-record uniqueness** across the target instrument's designated events and repeating instances. Existing project, DAG, and event scopes retain their cross-record behavior.
- Shared reference resolution across all five action-tag rule kinds, conditions, branch selectors, browser feedback, post-save audits, and direct/durable scans.
- Configuration diagnostics, bounded evaluation, incomplete-audit notices, and scan invalidation when relevant rules or project metadata change.

### Changed

- **Text conditions are case-insensitive by default for ASCII A–Z**. This applies to `@UVASSERT`, `when` conditions, and branch selectors. Set `"caseSensitive":true` to retain exact comparisons for an individual rule. Numeric comparisons, non-ASCII case distinctions, and uniqueness matching are unchanged.
- Ordered legacy comparisons with blank operands no longer create a violation solely from string ordering. Assertions treat them as nonviolations; conditions and branch selectors remain inactive. Equality checks against `''` retain their existing meaning.
- Extended browser validation is advisory. Exact current-page operands remain live; authorized off-page values are snapshots. Missing rows, unavailable metadata, inaccessible data, and resource limits produce unresolved results rather than fabricated blank values.
- Dependent post-save audits re-evaluate affected host contexts across events and instances. The default synchronous limit is 500 host contexts; incomplete work is reported and can be reconciled by a scan.

### Fixed

- **`@UVCHOICES` dropdown and autocomplete filtering**: select elements take precedence over hidden mirrors; grouped options are filtered and restored in order; existing disabled states and selected values are preserved.
- Autocomplete suggestions follow the active choice branch, including cached responses. jQuery-triggered changes are observed, stale menus close after branch changes, and invalid state is exposed on the visible input.
- A selected choice that becomes unavailable remains visible and is flagged rather than cleared. Selecting an allowed value releases the choice-filter save block.
- Unresolved branch selectors no longer activate a fallback. Stale uniqueness responses are discarded when the active branch changes.
- Multiple validation modes on the same field retain separate messages and combine their accessibility and presentation state correctly.
- Scan findings, uniqueness data, and generations are scoped consistently to their projects. Rule attribution and persistent identities remain stable when rules are reordered.
- Scan DAG arguments use numeric group identities consistently. Authorization covers every instrument read by a scan, including referenced fields and composite keys.
- Scan migration, lease fencing, worker recovery, retry handling, maintenance scheduling, and database diagnostics have been strengthened. No-progress browser polling uses backoff, and duplicate-group writes are batched.
- Scan regression fixtures now reflect the current schema version, DAG interface, and maintenance wiring.

### Fixed after the 2026-09-20 adversarial review

Found by red-team review with reproducers; each has a regression test.

- **`@UVCHOICES` dropdowns no longer drop the on-screen answer from the save.** The kept stale option was set `disabled`, and a browser leaves a disabled selected option out of the form submission. Under `blockSave` "off", or after "Save anyway", REDCap did not receive the value shown. The option is now greyed and marked `data-uv-stale`, and stays enabled.
- **One saved value that is not valid UTF-8 no longer removes every rule from the page.** `json_encode` failed on the snapshot and the page was emitted with no validator at all. Only the rule that reads the value is shown as not checked.
- **Within-record uniqueness and aggregates scale with the record, not its square.** Each host context re-read every other entry, so one record stopped being checkable at about 220 repeat entries (budget exhausted) and a 400-entry record took 8 seconds to scan. Saved collections are now read once per record: 1,500 entries are checked completely in well under a second.
- **`@UVCHOICES` no longer re-evaluates once per choice code.** One selection in a 2,000-option dropdown ran the filter 2,002 times (2.5 s of blocked main thread; 10.8 s at 3,000 options in Chromium). It now runs once. Restoring a 20,000-option list under 50 branches fell from 42 s to about 1 s. The field's code list is sent once per rule, not once per branch (767 KB saved for 2,000 options under 50 tags).
- **Record-scope `@UVUNIQUE` no longer forces `caseSensitive` onto its `when` gate in the browser.** The server folded case and enforced the rule while the page compared exactly and never ran it.
- **A date not entered yet is a saved blank, not "unresolved".** Typed date and elapsed-time bindings, and a blank field compared with an average, reported an unresolved finding for every record still in progress. They now follow the same blank rules as every other comparison. Impossible dates stay unresolved.
- The checkbox message region no longer sits inside an option row the filter can hide; the save guard asks each choice filter for a fresh verdict at submit time; `@READONLY` radio and checkbox options never hold a save; visible radio and checkbox inputs receive `aria-invalid` and `aria-describedby`.
- A collection over `["event-name","baseline_arm_1"]` no longer counts each member twice when the hook delivers the event id as a string.
- A truncated post-save audit names every rule it did not reach. `events:"arm"` with an arm that collects nothing, and `elapsedFrom` with a collection selector, are configuration errors.
- A 4,000-digit value can no longer make one comparison cost seconds: exact addition is linear in the digit count, and a rational is no longer multiplied by one per set member.
- Condition text is parsed once per request, and project metadata is loaded once per request; a durable scan rebuilt both for every record. The parser lowercases names with an ASCII map, so a Turkish `LC_CTYPE` on PHP 7.4 cannot turn `[ID]` into another field.
- Late-rendered fields keep their documented 10-second binding window: only timer ticks count as retries.

Known limitation: on a multi-page survey, a `@UVCHOICES` (or any `when`) condition whose controlling field was answered on an earlier page cannot be read in the browser on the later page. Keep both fields on one page; the post-save audit and scans still check the saved answer.

### Documentation

- Expanded [action-tag examples](docs/action_tag_validation_examples.md) for single and pooled `alternates`, events, repeat selectors, key matching, aggregates, dates, and record-local uniqueness.
- Added a [complete six-region site annotation](docs/region-site-choices-example.txt) for dropdown and autocomplete filtering.
- The action-tag examples now show every configurable key, value, selector, aggregate, date type and unit, the Configure dialog setting by setting, and the tags the module refuses. `tests/docs_examples_php.php` parses all 346 documented tags in CI.
- Added the [event and instance reference guide](docs/EVENT-INSTANCE-REFERENCES.md), including activation, permissions, unresolved results, limits, and rollback.

### Upgrade guidance

1. **Review conditions that depend on letter case before upgrading.** For identifiers where `ab12` and `AB12` differ, add `"caseSensitive":true` to the relevant rule. Branches distinguished only by case can otherwise become conflicting branches.
2. **Review blank-dependent conditions.** Ordered comparisons involving empty answers no longer activate a rule merely because an empty string sorts before a populated value. Use explicit blank checks where required.
3. **Review durable scan storage migration before enabling it.** The included schema-version-2 migration changes finding identities and project/generation attribution. It removes incompatible version-1 findings and retires affected runs; generate replacement findings with a new scan. It does not change REDCap record data. See [installation guidance](docs/INSTALL.md).
4. **Enable extended references deliberately.** Keep the project feature setting off until its rules, event mappings, repeat configuration, and access behavior have been checked in a development project. Disabling it preserves authored rules but marks extended rules unconfigured; it does not reverse the broader scan-storage migration.
5. **Save and reload after changing matching keys or off-page data.** Browser snapshots are not refreshed by a new endpoint. Run scans after deletions and imports or API writes that do not invoke the relevant save hook.

### Verification and known limitations

- Local testing covers PHP 7.4, 8.1, 8.3, and 8.4; all 22 JavaScript suites; and MySQL 5.7/8.0 plus MariaDB 10.5/10.11 integration tests. The cross-event database matrix completed 3,456 checks.
- Dropdown regressions include 96 checks and the six-region alphanumeric site configuration. Chromium verification exercised native dropdowns and real jQuery UI autocomplete, including stale selections, branch changes, and delayed initialization.
- **Live REDCap acceptance remains pending.** Local fixtures do not certify the behavior of a particular installation, its permissions, API/import hooks, or deletion hooks. REDCap 13.7 remains the compatibility target, not a newly verified deployment claim.
- Survey and restricted-user payloads withhold protected source data. Rules requiring unavailable live comparisons defer rather than disclose that data.
- Browser enforcement cannot prevent API/import writes or concurrent edits. Audits detect saved violations; scans provide reconciliation.
- PHP 8.4 reports an existing implicit-nullability deprecation in scan exception code. The tested suites pass, but the notice remains to be resolved.

## 1.10.0 — Multiple ID formats per field

Published as [`v1.10.0`](https://github.com/NghaReformer/redcap-universal-validator/tree/v1.10.0).

- Added `alternates` to accept several ID formats within one single-value or pooled field, each with its own pattern and check-character algorithm.
- Added per-format labels, normalization settings, and pooled candidate lengths.
- Strengthened pooled ambiguity checks, cross-runtime regex validation, and parsing work limits.
- Improved feedback for mixed-format single and pooled values.

See the [archived detailed notes](CHANGELOG-ARCHIVE.md#1100---one-field-several-id-formats) for migration details and implementation history.

## Earlier versions

Release and development notes preceding `v1.10.0` are preserved in [CHANGELOG-ARCHIVE.md](CHANGELOG-ARCHIVE.md). Published tags are listed in the [GitHub tag history](https://github.com/NghaReformer/redcap-universal-validator/tags).
