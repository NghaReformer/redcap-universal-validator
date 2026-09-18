# Dropdown choice filtering review — 2026-09-17

The reported workflow is a single-answer `site` dropdown controlled by `region`, with six conditional `@UVCHOICES` annotations and alphanumeric site codes. The same configuration was reported to work as radio buttons. The pasted annotation also contained a duplicated tag block inside the region 6 JSON string; `docs/region-site-choices-example.txt` supplies the clean six-branch version.

## Changes

- Prefer the actual select when a hidden input has the same field name, for both message attachment and reading conditions.
- Manage options inside optgroups, retain their parents/order, preserve originally disabled options, and restore the exact selected value after DOM changes.
- Receive native and jQuery-triggered field changes without registering both listener paths on each element. Observe autocomplete selection/change events after the underlying code is updated.
- Filter autocomplete response items against the active choices, including cached suggestions; close an old open menu after a branch change. Preserve the current value/text and expose invalid state on the visible autocomplete input.
- Keep the message outside the select/autocomplete companion pair and tolerate delayed widget initialization and option population.
- Document single-ID and pooled `alternates`, explicit events/instances, key matching, collections, typed dates, record-local uniqueness, and the complete region/site example.

## Verification

- All 22 JavaScript suites pass. `choices_dom_js.cjs` has 96 checks, including the user's six complete code lists.
- The expanded dropdown suite fails 12 checks against the previously committed engine, demonstrating that it detects the repaired gaps.
- PHP: choice parsing/branching 45 checks, annotations 290 checks, and save/render hooks 348 checks pass. All 28 action tags in the new worked sections parse and pass configuration validation with extended references enabled.
- Chromium with real jQuery 3.7.1 and jQuery UI 1.14.1: native grouped dropdown filtering; stale selection retained and save blocked; valid selection releases save; autocomplete region 1 offers 28 sites and region 2 offers 19; stale choices are absent from fresh suggestions; delayed widget initialization offers all six region 6 sites. This fixture exercises both native and jQuery-only changes.
- JavaScript syntax and whitespace checks pass.

## Verification boundary

These are local browser fixtures and module tests, not a live REDCap project. The supplied screenshots show the Online Designer, not the rendered data-entry DOM; the site's REDCap version was not supplied. The exact live failure therefore has not been reproduced on that installation. Confirm the clean annotation on its data-entry and survey pages after installing the updated module, with autocomplete both enabled and disabled. No saved project data, choice codes, or production installation was modified.
