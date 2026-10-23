# Tests — dual-runtime parity

Both engines are checked against fixtures so the client and server can never
disagree with each other or with the ID generator. If either drifts, CI fails.

## Run

```bash
node tests/parity_js.cjs        # JS engine  vs check_fixture.json
php  tests/parity_php.php        # PHP port   vs check_fixture.json (needs mbstring + ctype)
node tests/pooled_js.cjs        # JS pooled parser vs pooled_fixture.json
php  tests/pooled_php.php        # PHP pooled parser vs pooled_fixture.json
node tests/risky_js.cjs         # JS risky-pattern heuristic vs risky_patterns.json
php  tests/risky_php.php         # PHP risky-pattern heuristic + server behavior
php  tests/annotation_php.php    # @UVALIDATE parser + shared rule validator (checkFragment)
php  tests/hook_php.php          # redcap_save_record audit path against a strict framework mock
php  tests/registry_php.php      # mode registry (php/modes.json): frozen maps, coverage, bad file
node tests/window_js.cjs         # @UVWINDOW verdict vs window_fixture.json
php  tests/window_php.php         # @UVWINDOW verdict, PHP twin, same fixture
node tests/window_dom_js.cjs     # @UVWINDOW browser rule (messages, clock, snapshot, guard)
php  tests/window_module_php.php  # @UVWINDOW hooks, page fold, audit, scan, clock and time zone
php  tests/value_stamps_php.php   # @UVWINDOW save days read from a mock of REDCap's log
php  tests/exists_php.php         # @UVEXISTS grammar, hooks, endpoint, audit and scan index
php  tests/exists_cross_php.php   # @UVEXISTS in another project: agreement, rights there, probe log, budgets
node tests/exists_dom_js.cjs      # @UVEXISTS browser rule (asks on change, could-not-check, cache)
php  tests/exists_return_php.php  # @UVEXISTS "return": grammar, setup checks, endpoint values, save write, rights
node tests/exists_return_dom_js.cjs  # @UVEXISTS "return": fields filled, emptied or left on the page
php  tests/exists_where_php.php   # @UVEXISTS "where": grammar, setup checks, entry tests, confirm read, audit, scan
node tests/exists_where_dom_js.cjs   # @UVEXISTS "where": the filtered line, whereMessage and languages, surveys
php  tests/exists_table_php.php   # @UVEXISTS data-table reads, groups, rights on returned fields, read-only pages, "in" on the same form
php  tests/repeat_metadata_php.php   # what repeats, read from \Project or the static method; "return"/"where" on REDCap 17
php  tests/lookup_php.php         # @UVEXISTS/@UVUNIQUE comparison key vs lookup_fixture.json
node tests/lookup_js.cjs          # the same comparison key, JS twin, same fixture
node tests/unique_dom_js.cjs      # @UVUNIQUE browser rule (transport, fail-open, cache, "also" message)
php  tests/unique_also_scan_php.php  # @UVUNIQUE lookups in the durable scan: settled, re-read by form, never findings
node tests/range_js.cjs          # @UVRANGE verdict vs range_fixture.json
php  tests/range_php.php          # @UVRANGE verdict and grammar, PHP twin, same fixture
node tests/range_dom_js.cjs      # @UVRANGE browser rule (note on leave, per-level block, calc)
node tests/silent_change_dom_js.cjs  # values written without an event (calc, slider, Today button); save dialog names labels
node tests/mlm_dom_js.cjs        # Multi-Language Management: hidden dropdown choices get translated, dialog labels read live, survey rows
node tests/mlm_messages_dom_js.cjs  # rule messages in the MLM language on screen; string messages unchanged, a switch asks nothing
php  tests/message_i18n_php.php  # "message" as a language map: grammar, refusals, string messages unchanged, branches, page shape
php  tests/range_module_php.php   # @UVRANGE eligible fields, audit, scan and report detail
node tests/delta_js.cjs          # @UVDELTA verdict vs delta_fixture.json
php  tests/delta_php.php          # @UVDELTA verdict and grammar, PHP twin, same fixture
node tests/delta_dom_js.cjs      # @UVDELTA browser rule (live vs blocks, saved vs and calc never block)
php  tests/delta_module_php.php   # @UVDELTA hooks, page fold, audit across events and instances, scan
php  tests/delta_live_twin_php.php # @UVDELTA live run sheet: every answer, from the module
node tests/delta_live_twin_js.cjs  # @UVDELTA live run sheet: every note, from the engine
php  tests/writeonce_php.php      # @UVWRITEONCE verdict vs writeonce_fixture.json, and the log reader
node tests/writeonce_dom_js.cjs   # @UVWRITEONCE page lock (read-only, nothing posted disabled, a change undone)
php  tests/writeonce_module_php.php # @UVWRITEONCE hooks, roles, grammar, page, audit, scan
php  tests/writeonce_live_twin_php.php # @UVWRITEONCE live run sheet: every answer but I4, from the module
node tests/writeonce_live_twin_js.cjs  # @UVWRITEONCE live run sheet: every lock and note, from the engine
php  tests/justify_php.php        # @UVJUSTIFY reason given vs justify_fixture.json, grammar, registry flags
node tests/justify_js.cjs         # @UVJUSTIFY reason given, browser twin, same fixture
node tests/justify_dom_js.cjs     # @UVJUSTIFY page (a reason releases the modes it stands for, the alert names the reason field, the note)
php  tests/justify_module_php.php # @UVJUSTIFY hooks, applyJustify, page config, audit, legacy and durable scan
php  tests/justify_live_twin_php.php  # @UVJUSTIFY live run sheet: every answer, from the module
node tests/justify_live_twin_js.cjs   # @UVJUSTIFY live run sheet: every note, hold and dialog line, from the engine
node tests/growth_js.cjs         # growth references: fixture, 2,880 WHO points, R pnorm/qnorm, 720 mpmath output points, two parity hashes
php  tests/growth_php.php         # growth references, PHP twin: same cases and goldens, table SHA-256, extra folder, limits, port constants
php  tests/growth_outputs_php.php # @UVRANGE "return" catalog: outputs, key grammar and wording, engine copy, bad file
node tests/growth_dom_js.cjs     # @UVRANGE growth rule in the browser (z-score notes, inputs, cap)
php  tests/growth_module_php.php  # @UVRANGE growth grammar, dictionary hook, page tables, audit, scan
node tests/growth_return_dom_js.cjs  # @UVRANGE "return": z-score, percentile, bounds and reference values written into fields on the page
php  tests/growth_return_module_php.php  # @UVRANGE "return": the save writes them once; grammar, hook, branches, repeats; the scan reports stale ones
php  tests/growth_curve_php.php   # @UVRANGE "show": chart curves within 0.5 of the exact ones, payload, span, table rows
php  tests/growth_chart_module_php.php  # @UVRANGE "show": grammar, refusals, staff notes, page curves and tables, the pageCharts cap; --write refreshes growth_chart_fixture.json
node tests/growth_chart_dom_js.cjs  # @UVRANGE "show": the chart and the table on the page, parity with the server, MLM
php  tests/growth_chart_gaps_module_php.php  # @UVRANGE "show" on the live fixture uvrange_chart_gaps_fields.csv (steps K18 to K33); --write refreshes growth_chart_gaps_fixture.json
node tests/growth_chart_gaps_dom_js.cjs  # @UVRANGE "show" texts of steps K18 to K33: language maps, comma decimals, z-score chart, branches, saved inputs, repeating form
php  tests/convert_php.php        # data/references/convert.php: WHO and CDC shapes, sex codes, SHA-256, refusals
node tests/missing_codes_dom_js.cjs  # Missing Data Codes on the page, every mode
php  tests/missing_codes_php.php     # Missing Data Codes in the audit, scan, endpoints and page config
node tests/gen_mode_registry.cjs # rewrite the mode and growth-outputs blocks in js/engine.js (--check: fail if stale)
node tests/config_notice_js.cjs  # page-level config-error notice
node tests/dispatch_notice_js.cjs # dispatcher config-error routing
node tests/a11y_dom_js.cjs      # field DOM contract (a11y wiring, debounce, survey, readonly)
node tests/save_guard_enter_dom_js.cjs  # save guard in front of REDCap's dataEntrySubmit (Enter key, scripts)
node tests/value_parity_dom_js.cjs      # browser half of value_parity_fixture.json (trim, non-ASCII, regex dialect, length)
php  tests/value_parity_php.php         # server half of the same rows, plus browserTrim / jsLength / dialectSplits
php  tests/required_pages_php.php       # @UVREQUIRED: branching logic in the audit and scan, multi-page survey conditions
php  tests/unique_scope_php.php         # @UVUNIQUE: "dag" scope, "with" placement, throttle answer, new records, the data-table read; runs again without the table
php  tests/survey_shape_php.php         # survey page config: no condition text, snapshot names or reasons; only this page's configuration errors
php  tests/condition_kinds_php.php      # "assert" and "when": literals written as REDCap stores them, compared fields of one kind (AST-4)
php  tests/rule_scope_php.php          # a live lookup builds only its field's rules, as the full build has them (PRF-3)
```

## `check_fixture.json` — the algorithm + runtime-path contract

Generated by `playground/gen_check_fixture.py` in the `qrcode_generation` repo.
The parity tests exercise all three sections it carries, so the whole runtime
path is verified, not only the primitive:

- `compute` — the raw check character `{name, payload, check}` (574 rows, 15
  algorithms: damm, verhoeff, luhn, none, the seven ISO/IEC 7064 variants, and the
  four weighted-modulus schemes gs1_mod10, aba_mod10, mrz_mod10, weighted_mod11).
- `normalize` — Unicode dash folding, case folding, delimiter strip, `keep_only`.
  This is what guards client/server agreement on multibyte and Unicode-digit
  input (the PHP side is mb-safe: `mb_strtoupper`, code-point splitting, `\p{Nd}`).
- `scheme_ops` — `append` and `validate` over the full pipeline (normalize →
  source → compute → compare), i.e. what the module runs at save time.

918 rows total (the `scheme_ops` section covers every weighted scheme through
its real `digits_only` config and a `weighted_mod11` `X` check tail, locking
the peel-check-then-extract-source order across runtimes). To refresh after an
engine change, regenerate it in `qrcode_generation` and copy it here — see
[`../js/README.md`](../js/README.md).

`algorithm_coverage_js.cjs` / `algorithm_coverage_php.php` are a separate
completeness guard: they assert the algorithm-name set is identical across the
fixture, the JS registry, the PHP engine, the `AnnotationRules::ALGORITHMS`
allow-list, and the `config.json` dropdown, so a half-wired algorithm (added to
some surfaces but not others) fails CI rather than shipping.

## `pooled_fixture.json` — the pooled-parser contract

The pooled-field splitter is subtle (an optimal segmentation into verified
members + junk), so its behavior is frozen: `gen_pooled_fixture.cjs` drives the
**verified browser parser** over representative configs/inputs and records the
output. `pooled_js.cjs` and `pooled_php.php` recompute those cases; the PHP port
must reproduce the browser output byte-for-byte. Regenerate after a legitimate
parser change:

```bash
node tests/gen_pooled_fixture.cjs   # rewrites pooled_fixture.json; commit the diff
```

## `risky_patterns.json` — the ReDoS-gate contract

`riskyPattern` exists in both runtimes (`INSPIREUniversalValidator.riskyPattern`
in JS, `CheckCharacter::riskyPattern` in PHP) and must agree, or a catastrophic
`idPattern` the browser blocks could still run on the server. `risky_js.cjs` and
`risky_php.php` assert both classify the shared `risky` / `safe` list
identically. The gate has two stages. Stage one rejects nested quantifiers
(bounded and unbounded) and any repetition of a group that can match ambiguously
— alternation, optionals, or inner quantifiers, so `(a|aa)+`, `(a?)+` and
`((a)|(aa))+` are all refused — with inner groups collapsed layer by layer so
nesting cannot hide the shape. Stage two rejects the *polynomial* class stage
one misses: two or more unbounded quantifiers (`*`, `+`, `{n,}`) over overlapping
character classes with no mandatory separator between them, so `.*.*`,
`[0-9]*[0-9]*` and `[A-Z]+[A-Z0-9]+` are refused while the genuinely-linear
`[A-Z]+[0-9]+` and `.*x.*` pass. It also follows a chain of such quantifiers
joined by characters the quantifier before each can match, and refuses three or
more (`.*-.*-.*`, VAL-4), while `[A-Z]+-[A-Z]+-[A-Z]+` passes. Before either
stage, each escape is read as an atom of the same reach (`\d` as `[0-9]`, `\S`
as any character), so `[\w.]+_` keeps its `_`. Both stages sit behind a 512-code-point cap on
the pattern source, and a pattern the gate flags is never compiled, so it can
never run. It deliberately over-rejects (a repeated alternation is refused even
with disjoint branches; a collapsed group is treated as overlapping everything)
and it is still a heuristic, not a proof: a *bounded* backtracker like
`A{1,40}A{1,40}A{1,40}9` passes it — its work is capped by the pattern, not the
input length, so it is not an input-scaling ReDoS — which is why `risky_php.php`
also proves the server bails to "unconfigurable" on a PCRE backtrack-limit
failure instead of logging a false verdict. `risky_js.cjs` additionally runs
every `safe` pattern against adversarial inputs under a time budget, so "safe"
is measured, not assumed.

## `numeric_fixture.json` — the exact-decimal comparison contract

Both runtimes compare two `NUM_RE`-shaped operands as decimal **strings**
(`Logic::decCmp` in PHP, `QRID_whenDecCmp` in JS) — no float, no bcmath, no
BigInt. They used to cast to IEEE-754 first, which merged distinct values:
`9007199254740992` and `9007199254740993` compared equal, so the documented
`@UVASSERT="[id]=[id_confirm]"` recipe accepted two different identifiers, and
long fractions lost their tail the same way.

```bash
php  tests/numeric_php.php    # PHP comparator vs numeric_fixture.json
node tests/numeric_js.cjs     # JS  comparator vs numeric_fixture.json
```

71 cases, each declaring one `cmp` of `-1`/`0`/`1`; the six verdicts (`=`, `<>`,
`<`, `>`, `<=`, `>=`) are derived from it and run in all four operand shapes
(field/field, field/literal, literal/field, literal/literal), so every operator
is pinned per case — 1704 verdicts a side. Covered: the 2^53 neighbourhood and
its negatives, 20- and 400-digit integers differing only in the last digit,
17- and 200-place fractional tails, and the equivalences that must survive
(`02`=`2`, `2.50`=`2.5`, `.5`=`0.5`, `-0`=`0`, `5.`=`5`, `+5`=`5`), plus
mixed signs and ordering (`-5` > `-10`, `9` < `10`, `1.5` > `1.45`).

Three properties make the pair hard to break quietly:

- **Cross-runtime digest.** Each test sha256-hashes every verdict it produced
  and compares it to `digest` in the fixture. PHP and JS hash the same list in
  the same order, so the two cannot drift case-for-case even if someone edits a
  single expectation.
- **String-path proof.** Values `NUM_RE` rejects (`1e3`, `0x1F`, `1,000`,
  `NaN`, `Infinity`, a vertical tab that the shared `" \t\r\n"` trim charlist
  leaves in place) must fall through to byte comparison unchanged. Each test
  re-derives those cases from its own byte/code-point comparison rather than
  from the engine, which is what proves the fall-through happened. The PHP side
  additionally asserts the declared path against `Logic::NUM_RE` directly; the
  JS regex is module-private, so that check has no twin.
- **Live float sentinels.** The 16 cases flagged `floatTrap` are the ones where
  the old cast still gives a *different* answer; each test recomputes the float
  verdict and fails if it now agrees, so a sentinel cannot rot into a case that
  would pass either way.

## `php/modes.json` — the mode registry contract

Each action tag configures one validation mode, and `php/modes.json` lists
them: the tag, its rule types, the field types it accepts, its parser and
validator, the keys a rule carries, and the scan labels.
`php/ModeRegistry.php` serves the file to the server, and
`gen_mode_registry.cjs` writes the parts the browser needs into `js/engine.js`.

- `registry_php.php` holds copies of the hand-kept lists the registry replaced
  (tag map, branch keys, client keys, field-type gates, refusal wording) and
  fails if the registry no longer reproduces them.
- It also fails when a mode is missing from a place that stays hand-written:
  `config.json` action-tags, `site/pages.py`, `php/messages/catalog.json`, the
  engine's public namespace, or `mode_samples.json`.
- `mode_samples.json` holds one working sample per mode. `annotation_php.php`
  and `deferral_dom_js.cjs` loop over it, so a new mode is covered by their
  every-mode checks without editing them.

## `window_fixture.json` — the date-window contract

`window_php.php` runs every case through `TemporalLogic::windowVerdict` and
`window_js.cjs` through `QRID_windowVerdict`; both must return the same verdict
and the same earliest and latest bounds. Each case gives the rule, the value and
its display format, the `from` date and its format, and the clock. Cases cover
D-M-Y, M-D-Y and Y-M-D input, leap days and year ends, open and negative bounds,
every unit, a blank value or `from` date, half-typed and impossible dates, a
date against a datetime, and `notFuture` exactly at today, at the next minute
and one second ahead of now. The clock is an input, so no case depends on the
day the tests run. `window_js.cjs` also checks `QRID_clockNow`: the page clock
moves forward with `Date.now()`, never backwards, and is null without a valid
server clock. The fixture's `calendar` cases lock the month, week and period
arithmetic, its `spread` cases a window counted from the span of "now", and its
`labels` cases the name of a period, which the page shows and the scan report
repeats.

- `window_dom_js.cjs` boots the browser rule on a stub page: the message names
  the bounds in the field's format, the survey message leaves out the `from`
  field, a snapshot `from` date never blocks, and the save-time recheck of a
  page left open past the server's midnight uses the new day.
- `window_module_php.php` covers the server side: field and dictionary checks,
  the four outcomes of the page fold for a `from` date (live, snapshot,
  withheld, unresolved), `config.clock`, the audit reasons, re-auditing on a
  save of the `from` form, scan labels and detail lines, a clock read by each
  part of a durable scan, and the parts judged against today: the dictionary
  checks for `today`, `now` and fixed dates, the week start, `windowSaved`, and
  the audit and the scan judging each value against the day it was saved.
- `value_stamps_php.php` reads a mock of REDCap's log through
  `php/ValueStamps.php`: the `data_values` format with repeating instances,
  checkbox and ambiguous lines, values that span lines, the newest row winning,
  paging and its cap, the record ID compared as bytes, the time zones, and the
  records held in memory. `tests/mysql/cases/stamps.php` runs the same reader
  against a real server, with the durable finding's `as_of` column.

## `lookup_php.php` and `lookup_js.cjs` — how lookups compare values

Both drive one fixture, `lookup_fixture.json`, through `Logic::lookupKey` and
its browser twin `QRID_lookupKey`: the trim set (NUL, vertical tab and U+00A0
included, the no-break space kept inside a value), the A-Z fold and nothing
beyond it (`É`, `ß`, the Kelvin sign), numbers in one written form (`007`,
`7.0`, `+7.50`, `-0`, `1e3`, a decimal comma) and the values that stay text
(`1,234,5`, `NaN`, `0x1A`, an exponent too large to write out). They also pin
`sameFlags`, the parser of the record-scope `@UVUNIQUE` operator
`same:FOLD:MARK`. No Unicode normalisation is applied, because the `intl`
extension it needs is not on every server and the two runtimes must agree.

## `exists_php.php` and `exists_dom_js.cjs` — the lookup contract

`exists_php.php` mocks `REDCap::getData` so that it honours `records`, `events`
and a simple `filterLogic`, and can be told to misbehave: a filtered read that
wrongly comes back empty, a full read that throws, every read throwing, or a read
that returns every event. It covers the grammar and dictionary refusals, that
the lookup target never reaches the page config, the `exists-check` endpoint
(found, not found and could not check; a narrowed miss confirmed by the full
read; trimmed comparison of stored values, letter case ignored unless the rule
is caseSensitive, numbers by value in a field that holds them, a refused pair
of decimal marks; a record ID in another case found by one read of every ID,
never the record being edited; the caller's group and the exact spelling
preferred among matches; dates typed as shown;
`match` fields on and off the page; events, scopes and branches; the form-rights
gate and the per-session throttle, both shared with `unique-check`; the survey
opt-in, its Identifier refusal and its whole-field read budget; the DAG-masked
record; a record outside the caller's group; a group caller whose read may not
see other groups; `when` values sent from the page), the audit, the scan index
read once per rule in chunks of records (with a test chunk of one record, and a
memory cap that stops it after its first chunk), the group-confined scan, and
`@UVUNIQUE` comparing the same way in its scan, audit and endpoint.

`exists_dom_js.cjs` drives the browser rule with fake timers and a stub
transport: typing clears the answer and asks nothing, change and blur ask once,
Save waits for an answer still on its way, *could not check* never blocks and
is not asked again by Save, a cached *not found* 30 seconds old or more is
asked again by Save and stands when that second answer fails or times out,
unknown answers are not cached, a late reply for an
older value is dropped, the message follows an autocomplete widget, surveys are
opt-in and never see a reason.

`@UVUNIQUE` `"also"` (the value is looked for in other fields of the other
records too) is covered in seven suites. `annotation_php.php` pins the grammar:
a list of at most 5 field names, none twice, none also in `with`, and not under
`"scope":"record"`. `branching_php.php` refuses rules on one field that list
different `also` fields, compared as sets. `hook_php.php` runs a registration
form with a scanned and a typed ID: the endpoint (the field the value was found
in, the record being checked left out, `with` matched in the same entry, numbers
and letter case, the survey answer, DAG masking, form rights on the `also`
fields, the narrowed read that ORs the fields and the full read that confirms a
miss), the refusals in both channels, the page config without the `also` list,
the post-save audit, the scan (an `also` value is evidence, never a finding),
and the durable plan's letter-case set and lookup specs. A second block there
holds the scan to the endpoint where the two used to part: branches that differ
in letter case or in `with`, a record whose `when` is false, `"caseSensitive"`,
`dag` and `event` scope (the `getData` stub now honours `events`), decimal
marks, the survey opt-in with an `also` field off the survey's instrument, both
survey refusals at the request for a dictionary edited after the rules were
read, and the lookup flags `durableEvaluateRecord` writes. `hosting_php.php`
runs the repeat layout: an `also` value on a base form beside a repeating `with`
value (endpoint, scan, and where the durable lookup is stored), and the
placements refused because they can never match. `unique_also_scan_php.php`
drives `UniqueFinalizer` with a recording database: a group of lookups only is
settled at discovery, emit reads checked values only, verify passes each
location's form, and the re-read finds instance 1 of a repeating instrument in
`repeat_instances`. `temporal_integration_php.php` checks that saving an `also`
field's form does not re-audit an extended rule. `missing_codes_php.php` checks
that a code saved in an `also` field matches nothing. The SQL (the `lookup`
column, discovery and emit) is in `tests/mysql/cases/uniqueness.php`.

The live check and the save audit first ask the data table which records hold
the value (`Scan\FieldValues`). `unique_scope_php.php` answers that read from
its `REDCap::$data` and runs every check twice: with the table, and in a second
process (`--no-table`) without it, where the export answers instead.
`tests/mysql/cases/field_values.php` runs the reader on a real server over a
table with REDCap's own indexes and asserts each statement's plan.

`exists_table_php.php` covers the @UVEXISTS reads of the data table. A lookup
asks the table which records hold the value and reads only those through
getData; its mock answers the table from `REDCap::$data` and can confine
getData to one Data Access Group, as REDCap does for a user in one. Each
lookup is asked again with the table unreadable, and the export must give the
same answer. It also covers a returned value kept out of a form the person who
saved may only read, the `formReadOnly` flag of a read-only or locked form, the
value the page sends for an `in` field on the tagged field's own form, and the
scan index built from the table. The browser half of the last two is in
`exists_return_dom_js.cjs`.

`exists_cross_php.php` covers `"project"`, a lookup in another project. Its
mocks keep settings, dictionaries, data, rights and `\Project` per project id,
and `REDCap::getUserRights()` is a trap: it answers with the rights of the
project of the request, including the form of the other project, and no check
may read it as rights there. It covers the grammar, the order of the annotation
checks (server switch, alias, the other project's agreement with one message
for every reason and no read of that project before it passes, then the field
checks there), the endpoint under both agreements (rights there, expiry,
administrators, confinement to a group there, Identifiers, surveys), the
record never echoed, an empty read never "not found", the probe log in the
other project, the budgets (one count per kind of caller there, a session
window, one "throttled" line a minute), the audit (including a saver in a group
there), the scan judged branch by branch, a stored scan refused to a reader the
other project would not answer, a deleted project, a field named `record`, and
the Configure dialog checks of the alias and agreement rows.

`exists_return_php.php` covers `"return"`, the fields a lookup copies from the
entry it found. Its mocks join the two above and keep settings, dictionaries,
data, rights, repeating forms and the forms of each event per project id,
`REDCap::getData` honouring records, events and `filterLogic`, and
`REDCap::saveData` recording each write (repeat instances too). It covers the
grammar, every setup refusal and its wording (fields there on a repeating
form or missing from an event the entry can be found in, with the events
`"scope":"event"` leaves out and the record ID that every event holds,
fields here of a kind that cannot hold the value, number places that differ,
`@RICHTEXT` and ontology targets, an Identifier into a field that is not
one, a repeating event with `"in":"record"`), the page config (the fields
filled, never where the values come from), the endpoint (values as the page
shows them, entries that agree or disagree and how many, one record holding
the value in two events, values that are not valid UTF-8, an entry only in
another group, the form rights of the searched, `match` and copied fields,
the full read a rule with `"return"` always makes, kept per user, a
signed-in caller on a survey page), the save (stored values, only what
differs, one lookup per save, the read kept for the request and refreshed by
each save, an entry changed away or gone, survey saves and savers without
rights writing nothing, a survey save not carried into the next save, a
save with no signed-in user asking for no values, the
`exists-return-no-save` switch asking for none either), the scan that never
asks for values, and another project (the agreement's list of fields it may
copy, the "answer" mode, the probe log, refusals that would describe that
project, a user in a group there, the saver's rights to a `match` field
here, a save with no signed-in user, the Configure dialog checks).
`exists_return_dom_js.cjs` drives the page fill: each kind of field, the
quiet fill as the page opens, the answers that fill, empty or leave the
fields, branches, the save held while a lookup is on its way (on a
`@READONLY` field too, unless the branch that applies blocks and fills
nothing), late and stale answers, a choice the page does not offer, and
found answers asked again after 30 seconds.

`exists_where_php.php` covers `"where"`, the condition the entry found must
meet. Its `REDCap::getData` mock projects the fields asked for, honours
records, events and a row-level `filterLogic`, and gives every row of a
record the codes of each checkbox, as a REDCap export does. Project 149 is
longitudinal with a repeating form, so an entry can be an event row or a
repeat instance beside its event row. It covers the grammar (AND, OR, NOT,
parentheses, keywords in any case, event references, `[record]`, the field
cap, a stored field list that does not match its condition, `whereMessage`
and its languages), the dictionary checks (field kinds, date and time
literals, comma literals, checkbox codes, fields on another repeating form or
missing from an event, `"in":"record"` without `"event"`), the page config
(`whereMessage` only, never the condition, and on a survey not even
`whereMessage`), each entry's test (letter case,
blanks against `<` and `<>`, Missing Data Codes, `007` and `7`, dates,
decimal commas, a checkbox on the event row read from a repeat instance), the
read that confirms a narrowed one, `"in":"record"` with IDs in another
letter case, `return` from a passing entry only, the fourth answer for staff
and its plain *not found* on a survey, rights to the `where` forms, the audit
reason `filtered`, and the scan index keyed by the condition. It counts reads
too: on a survey a found, a filtered and an absent value each take one read of
the whole field, and on a record-ID lookup a filtered and an absent ID take as
many reads as each other, so the number of reads tells nothing.
`exists_cross_php.php` adds the same in another project: a `where` field the
agreement leaves out, an Identifier `where` field in the "answer" mode, rights
to a `where` form there, one refusal that names nothing there, the probe log
and the scan read.
`exists_where_dom_js.cjs` drives the page: the filtered line with
`whereMessage` (escaped) or the module's own line, never `message`; the save
held per `blockSave`; the cached answer; languages under Multi-Language
Management; the plain wording on a survey; and `return` fields emptied.

`repeat_metadata_php.php` covers where the module reads which forms and
events repeat. REDCap 17.0.6 has the map only as `\Project::getRepeatingFormsEvents()`,
with no static `\REDCap::getRepeatingFormsEvents`, and every other suite stubs
the static. The test runs itself once per REDCap shape in a child process,
since a stub class cannot lose a method: only the Project method, only the
static, a Project method that throws or answers a string (the static answers),
one that answers `false` (nothing repeats, the static is not asked), and
neither (`"return"` and `"where"` still refuse). Each shape runs on a
longitudinal and a classic project. It pins the page config of sound
`"return"` and `"where"` rules, the refusals that prove the map was read (a
field on the repeating form, an `"in":"record"` event that repeats as a
whole), `repeatingLayout`, `repeatingFormsFromMap`, `eventLayout` and the
scan's `repeatMetadata` probe.

`docs_examples_php.php` also tests the `@UVEXISTS` expect blocks of the
examples doc. Each line gives an entry (`with consent=1, status=3`) and says
`found` or `filtered`; the line runs through `AnnotationRules::existsWhereHolds`,
the test the lookup applies to each entry. These lines are PHP only and stay
out of `docs_expect_fixture.json`, since the browser never sees a `where`.

## `range_*` — the plausibility contract

`range_fixture.json` holds number cases that `range_php.php` runs through
`Logic::rangeVerdict` and `range_js.cjs` through `QRID_rangeVerdict`; both must
give the same level (ok, soft, hard, inert) and reason. Cases cover values on
and next to each limit, `17.50` against `17.5`, numbers past 2^53 and long
fractions, open limits, a rule with only one level, comma decimals on comma
fields and commas on point fields, exponents written out (`2.5E1` is 25,
`1.5E0001` is 15, and `1e1000` is past every limit), and text that is not a
number (`-`, `.`, `1,200`, `1e1e1`). `range_php.php` also
covers the grammar: limits kept exactly as typed, quoted or not (the JSON is
read a second time with every number quoted), exponents written out, the
64-character cap, and every refusal.

- `range_dom_js.cjs` boots the browser rule on a stub page: the note waits for
  the field to be left, typing clears it, each level blocks per `softBlock` and
  `hardBlock`, the save-time recheck catches a value never left, a calc and a
  read-only field never block, branches pick their limits from `when`.
- `range_module_php.php` covers the server side: which fields may carry the
  tag, comma-decimal fields, the notes' limit texts, the audit reasons, the
  scan, its labels and detail lines, and the detail line of a branched rule,
  taken from the branch that judged the value. It also covers the fallback
  branch, two true conditions (a rule problem, never a finding), the survey
  page's baked-in branch choice, and the branch on a branched `@UVUNIQUE`
  duplicate.

## `delta_*` — how far a number may change

`delta_fixture.json` holds cases that `delta_php.php` runs through
`Logic::deltaVerdict` and `delta_js.cjs` through `QRID_deltaVerdict`; both must
give the same verdict (ok, up, down, inert, unknown), reason and change, the
change written as an exact decimal. Cases cover changes on and next to each
limit, a percent of a negative earlier value, an earlier 0 (no percent),
direction, comma decimals on either side, exponents written out in full (1e80
against 1e100), numbers past 2^53 and long fractions where a double would say
"equal", spaces other than space, tab, CR and LF (not a number), and values
too long to compare (past `ExactDecimal::MAX_DIGITS` written out, or a product
too long for the percent). A long value is written `{prefix, repeat, times,
suffix}`. `delta_php.php` also covers the evaluation budget (long numbers pay
before the work, a refused payment is unknown, reason `limit`) and the grammar:
the shorthands, limits kept exactly as typed, the branch group, and every
refusal, `"maxPct": 0` among them. `delta_js.cjs` times a value with 100,000
inner spaces.

- `delta_dom_js.cjs` boots the browser rule on a stub page: a `vs` on the page
  is read as typed and blocks per `blockSave`, changing it re-checks; a
  snapshot, an event/instance rule (`extendedAdvisory`), a calc, a read-only
  field and a deferred rule never block; a Missing Data Code on either side,
  a numeric one such as `-99` included, checks nothing, in `check()` and in
  `test()`; a withheld earlier value and one that
  is not a number are amber notes for staff and nothing on a survey; the note's
  wording (percent, "more than" the limit it broke, "under 0.1%", the earlier
  value with its own decimals, comma, survey without the label, escaping); two
  tags on one field say one line each; a deferral only this viewer meets says
  the save checks it; the public registry composes @UVRANGE and @UVDELTA on
  one field, and an ID check (alone, branched, beside another mode, on a
  blank field, part-typed, with a configuration error) answers there as the
  page judges it, while a pooled list or @UVUNIQUE leaves it open; a value
  written by a script is judged without any event; typing
  in the `vs` field or a field the `when` reads is judged once the field is
  left.
- `delta_module_php.php` covers the server side with a `\Project` stub for
  events and repeats: the field and dictionary hooks and their refusals, the
  shorthand written out per field, the page fold (live, snapshot, withheld),
  the audit on a classic project (Missing Data Codes, `when`, two tags logged
  apart by `vs`), across events (previous, first and named event; the first
  visit and an event with nothing saved compare with nothing; saving an earlier
  visit re-checks the later one) and across instances, and the scan with its
  labels and detail lines. A `vs` that cannot be read (unresolved on the
  event/instance path) is a rule problem, and so is a spent evaluation budget.
  The shorthand layout is judged at every event that holds the form, arm by
  arm (a form that repeats at some visits only, a whole repeating event, one
  event in each of two arms). A reference is refused, with the events named,
  when it names the same entry, an event
  that does not hold its form (plain, with references on or off, or named),
  an instance of a form that does not repeat where it lands, a relative
  instance where the field's own form is held once (each such event named,
  and not where the event token finds nothing), no instance of a form that
  repeats (a named or relative event included, but not a form of the same
  whole repeating event), or a relative event that finds nothing from any
  event. A refusal ends with a reference to write instead only when that
  reference passes the same check; the suite writes each one back in place of
  the reference and expects it accepted, including across two arms (a
  relative event, never one arm's event, even when the reference named one),
  on a project of one event, and where nothing works (no suggestion, and the
  list of what was tried, this event included). For the field itself it
  suggests `[last-instance]` at another event and `[previous-instance]` only
  at its own, and the first visit's `[first-instance]` where only that visit
  repeats; a relative event as written is kept, an event of another arm as
  written is not (but is accepted written by hand), another form's field
  gets its last instance first, a token that lands on its own event from
  every event where the field is entered is left to the plain reference, and
  no suggestion repeats another tag's `vs` and direction under the same
  condition (review R8). For review R9 it checks that the same condition in
  other words (spacing, a leading space, quote style, parentheses, letter
  case, keyword case) holds a group, and letter case does not for a
  `caseSensitive` tag; that a token finding no event from an event of
  another arm is offered; that the shorthand refusal's example keeps its own
  event, with `[first-instance]` for `first-event`, or gives none; that the
  next event, the last event and a later visit as written are not kept; that
  for the field itself `[next-instance]`, `[current-instance]` at another
  event and an instance after the first at its own event are not offered;
  that two refused tags, in either order, and a tag next to one that failed
  to parse get suggestions accepted when written back together; that every
  path that suggests a reference passes over the groups other tags hold, in
  the tag's direction; and that another form's field gets the second event
  that holds it, named outright, when the first lands on the field's own
  event. For review R10 it checks that a conditional tag is not offered the
  group of another whose condition could be true at the same time (`>` beside
  `=`, `1` beside `'01'` or `1.0`, a space inside the quotes, `'Yes'` beside
  `'yes'` unless both tags are `caseSensitive`, `<>`, an `or`, a field at
  another event, one condition never true said twice), and is offered it
  beside one that can never be true with it (two values of one field, either
  way round, inside an `and`, a number beside a text), each written back and
  accepted, and that at run time each value picks one branch with no
  conflict; that a tag without a condition is the fallback beside a
  conditional one; that a tag that failed to parse is read with single or
  curly quotes and a trailing comma, keeping its `when`, direction,
  `caseSensitive` (only when true) and a shorthand in capitals, from its own
  value; that beside a tag whose `vs` cannot be read nothing is offered and
  each kind of refusal says to fix it first, and that each says when the
  other tags hold every reference that would work; that two tags written the
  same are told apart, each field reads its own tags, and the rules built
  again with references on are worked out again; and that a refused
  reference and a refused shorthand hand their suggestions on. The refusal of a plain `vs` naming the field
  itself suggests `previous-instance` on a classic project whose form
  repeats, `previous-event` across visits, `previous-instance` where the
  form repeats at the visits, a written reference when no shorthand fits
  (saying when it needs references on), and nothing where no other entry of
  the field exists or the form is designated at no event. A `vs` form designated at no event is refused as such. A plain
  `vs` a save cannot read (the field is not collected in this event, or the
  read failed) is a rule problem in the evaluator. One `vs` with two directions judges both ways (the heart-rate
  recipe), and a deferral only this viewer meets is marked and still checked
  on save.
- `delta_live_twin_php.php` runs the module over the live fixture on a copy of
  pid 149's events and checks every answer of
  `docs/testbed/DELTA_LIVE_ACCEPTANCE.md`: the configuration errors word for
  word, every log entry of each save in the sheet's order, and the scan with
  its detail lines. It keeps the page configs in `delta_live_pages.json`
  (`--write` after a change) for `delta_live_twin_js.cjs`, which replays the
  sheet's typing through the engine and checks each note, each save and the
  label the save dialog names. The pages B, C2 and D1 open with the values
  the sheet saved before them (`sheet_b1`, `sheet_c2`, `sheet_d1` and their
  `_values`), so the "save anyway?" dialogs of B2 and C3 are checked word for
  word, and section F checks each configuration error under its own field.
- `delta_mutations.py` applies 251 mutations to a copy of the code, one at a
  time, and fails if the named suite still passes on any of them. The copy
  holds every folder and file the named suites read (`site/` and `README.md`
  among them), and every suite must pass in it before the first mutation, so
  a suite that cannot run in the copy stops the run. Besides
  @UVDELTA's own code it breaks the page code @UVDELTA shares with other modes
  (the trim of @UVREQUIRED and @UVCHOICES, the wait for a blur, the deferral
  notes, the registry entry of a field with two modes), so those suites are
  named too.

## `writeonce_*` — values entered once

- `writeonce_php.php` holds `Logic::writeOnceVerdict` to
  `writeonce_fixture.json`: the lock set by the first save of a value (not a
  blank, not a Missing Data Code), a change or a clearing after it, a role that
  moves or releases the lock, "since", a quote the log escaped, a save the log
  does not spell out, a value it never shows saved, and roles that cannot be
  read, and the lock compared with the last save rather than the value now. It
  also drives `ValueHistory` over a mock of REDCap's log
  (`writeonce_fake_log.php`): one history per record and event (the mock
  applies the event filter and refuses SQL without it; with `$looseEvent` it
  returns every event's rows and the reader must pass over the others); rows
  newest first in pages; a whole-record `DELETE` ending the history in a
  project of one arm, while a `DELETE` of this event's data or of an arm, and
  any whole-record `DELETE` with several arms, make it unknown, and one of
  one form or one event of another event's data is passed over while another
  description there makes it unknown; an erase of the project's records
  starting every history after it (asked once per reader, a failed read making
  the history unknown, the project id compared as bytes, part of a record
  named like the project no erase, an erase placed after the record was made
  leaving its history unknown); the record's start (an INSERT with a trusted
  record id line, read at any event, even one naming another record id; a
  history without one unknown; an UPDATE that writes the record id, and a
  record id line typed into a notes value, change nothing; "recordXid" and
  "prev_record_id" lines not read), read from the row as written with the
  module's own options, the record id field counted as a Text field: a field
  moved since, a Notes value with a line ending in a quote and a comma, a
  locked field at another event, and lines of fields no one types, checkbox
  lines and an instance line before the record id line leave the start
  trusted, while a record id line after a Text, Notes or stray line, one that
  runs on, or one with no value is "startrow", and a newer untrusted row does
  not outrank an older start; a save with no event that names a kept
  field, on any instance; another record of the same name in a loose
  collation, also past the cap; the cap counted per event over that event's
  rows, deletes and rows with no event (each counted, another event's rows
  not), with a history of exactly the cap read in full and `MAX_ROWS_READ`
  rows of any kind; a whole-record `DELETE` in any letter case; log and erase
  ids that are not digits read as unknown; a
  failed read; save times turned into the project's clock; and `parseRow` (a
  notes value over several lines, 130,000 lines in under a second, checkbox
  lines, a field written twice, a stray line, an instance line that is not the
  first, and a row out of the data dictionary's order, read up to the first
  Text or Notes value before the break). `RoleBook::read` runs
  over a mock database: both names of each role, users by lowercase name, the
  project and today bound as parameters, and a REDCap with no unique role
  names, usernames stored with spaces trimmed, and role names kept as
  `Logic::roleKey` (letter case folded in full, so "STRASSE" matches "Straße";
  spaces folded; a no-break space at either end trimmed; a zero-width space,
  a byte order mark and a word joiner dropped; a name with combining accents
  matching its composed form where PHP's intl extension is loaded, a check the
  suite skips with a note otherwise). The SQL of both runs
  on a real server in `tests/mysql/cases/history.php`, with two erases, rows
  that are no erase (a record "730 " or "７３０", one form of a record "730",
  another object type, one that names an event), the record's start through
  both LIKE patterns, a `DELETE` of one event and one that names an arm.
- `writeonce_dom_js.cjs` boots the page lock on a stub page: a text box is
  read-only with the note under it, in the rule's own words when it has a
  message; radio buttons are disabled and the "reset" link hidden, and the
  enhanced radio buttons take no clicks; REDCap's Today and Now buttons are
  hidden; a dropdown is not disabled but takes no clicks, its autocomplete box
  is read-only even when it is built after the lock, and a saved code that is
  no longer a choice comes back as an option; the slider is disabled through
  jQuery UI, and the date picker is kept from opening with its button hidden
  while the date box stays enabled; a change made anyway (an event, a jQuery
  change, a script with no event) is undone and the note says so; a value that
  cannot be put back holds the save; another mode's item on a locked field
  never holds the save unless its rule reads another field (`lockReads`) and
  that field has changed since the page opened, in either order of binding
  and for every mode, while a field REDCap made read-only (`@READONLY`) or
  disabled still never holds it; a violation saved before the page opened and
  two locked fields that read each other never hold it, also with the engine
  loaded before the form, as REDCap prints it; the opening values taken after
  REDCap's start-up, on a stub with timers and a jQuery whose ready callbacks
  run in a timer after the DOMContentLoaded listeners, as jQuery 3 runs them:
  a value REDCap writes in a ready callback, in a timer started there, or in a
  DOMContentLoaded listener added before the engine is no change, while a
  later change still holds the save, and nothing is compared before the
  values are taken; a checkbox read option by
  option; a read field the page does not carry is never compared; the lock
  leaves an
  embedded field's enhanced choices, reset link and Today button alone; the
  date picker is set once; roles that could not be read lock the field and the
  note says so; saved values that could not be read lock nothing and staff see
  a note; a configuration error locks nothing; and every guard item names a
  mode the registry knows.
- `writeonce_module_php.php` covers the server side: calc and checkbox fields
  and `@CALCTEXT`, `@CALCDATE`, `@SETVALUE`, `@PREFILL` (in any letter case),
  `@DOWNLOAD-COUNT`, `@RICHTEXT` and ontology fields of any service refused; a field given the
  tag twice refused; each role checked against the project, by name or unique
  role name, in any letter case of any alphabet, and refused once by name; a lock on a field another rule
  returns to refused, split off its group with each refusal in field order,
  while a returning rule refused for another reason leaves the lock alone; the
  grammar; the page (only fields holding a value, never for a member of a
  role, the user name compared without letter case, a survey respondent holds
  none, roles that cannot be read lock every rule that names roles and say so,
  never on a new record, not before "since", roles and "since" kept off the
  page, `lockReads` naming only fields of the form that are not locked, a
  checkbox once per option, a calc, `@CALCTEXT` or `@CALCDATE` field replaced
  by the fields it is worked out from that may be changed there, through
  other calcs too (a checkbox option a calc reads named alone, a calc of
  another form naming nothing, a cycle of calcs giving the same answer in
  either rule order), `[event-name][f]` and the page's own event name read as
  this event while another event's field is left out, and a field with
  `@READONLY` or `@HIDDEN` (per page, every variant when the page is not
  known), `@NOW`, `@TODAY` (with `-SERVER` and `-UTC`), `@LATITUDE`,
  `@LONGITUDE`, `@SETVALUE` or `@DEFAULT` never named, saved
  values that cannot be read flagged, the saved code sent for dropdown and SQL
  fields); what the log reader is told of the project (the data dictionary's
  order, its Text and Notes fields, the record id field, one arm or several);
  the audit (`changed`, `cleared`, a role moving or releasing the lock, a code
  saved over a locked value, "since", the lock times kept whatever the log
  mode, and each rule problem, with the reason a history could not be read,
  a creating row out of order included);
  and the scan with its labels and detail line.
- `writeonce_live_twin_php.php` runs the module over the live fixture on a copy
  of pid 149's events with a copy of REDCap's log that gains the row of each
  save the sheet makes, and checks every answer of
  `docs/testbed/WRITEONCE_LIVE_ACCEPTANCE.md`: the configuration errors word
  for word (and the survey's general wording), which fields each page locks,
  each log entry of each save in order, the Data Import Tool change found by
  the scan and the next save, and the roles read as they are when the audit
  or scan runs. For section I, whose steps read what REDCap itself logs, it
  checks the module's answer for the log each step expects and for the other
  log the step tells the tester to record (I0, I0b, I1, I2, I3, I3b, I5, I5b,
  I6, I7, and the three wordings of H4). It does not cover I4, since the
  fixture holds no SQL field, nor what REDCap actually writes: that is what
  the live run checks. It writes the page configs to
  `writeonce_live_pages.json`.
- `writeonce_live_twin_js.cjs` boots `js/engine.js` with those configs on a
  page shaped as REDCap renders the fixture's field types (a date box with its
  picker, radio buttons with their mirror and "reset" link, a dropdown with its
  autocomplete box, a notes box, a slider), and checks each lock, each note,
  each undone change and each save. A refused tag on a dropdown shows its
  error under the field. B7, and a second run of B6, load the engine above
  the form while the page is still loading, as REDCap prints it, with
  jQuery's ready callbacks run in a timer.
- `writeonce_mutations.py` applies 236 mutations to a copy of the code, one at a
  time, and fails if the named suite still passes on any of them. Twelve mutate
  the SQL (three the role book's, six the erase search, three the search for
  the record's start) and are killed only by
  `tests/mysql/run.php`; they run when `UV_DB_HOST` and the other `UV_DB_*`
  variables name a server, and are reported as skipped otherwise. The one
  that drops the composed form of role names runs only where PHP loads the
  intl extension (the script passes `-d extension=intl` when PHP has it but
  has not loaded it), and is reported as skipped otherwise.

## `justify_*` — values saved with a reason

`justify_fixture.json` holds the cases of whether a reason field's value gives
a reason: blank, and blank as `@UVREQUIRED` reads it (a no-break space, a
zero-width space, a byte-order mark, an ideographic space or a form feed
alone, REQ-8), text after such a character, Missing Data Codes with and
without PHP's `trim()` set round them, numbers, a checkbox map, null and
booleans. `justify_php.php` runs them through
`Logic::reasonGiven` and `justify_js.cjs` through `QRID_reasonGiven`, so the
two runtimes cannot disagree. `justify_php.php` also pins the grammar (every
refusal word for word), the registry's `"justify"` flags (the ID checks,
`@UVASSERT`, `@UVREQUIRED`, `@UVCHOICES`, `@UVWINDOW`, `@UVRANGE`,
`@UVDELTA` and `@UVEXISTS`; never `@UVUNIQUE`, `@UVWRITEONCE` or itself), the
reason fields `ModeRegistry::refFields` reads, and the sentence a report adds
to a finding saved with a reason.

- `justify_dom_js.cjs` drives `QRIDJustifyInit` beside the factories of the
  rules a reason stands for: the save message adds "or give a reason in" with
  the reason field's label and a note asks for one; a reason releases the
  field for "hard" and "confirm" in either boot order, and clearing it brings
  the hold back; a Missing Data Code, a hidden reason row, a reason field
  embedded in a hidden row and a reason field not on the page give no
  reason, and one embedded in a row that shows does; a radio or dropdown
  reason counts, even
  changed with no event; a rule with blockSave "off", a lookup still in
  flight, a duplicate and a write-once lock are never lifted; configuration
  errors release nothing. A field made read-only after the engine bound it,
  or whose row branching hides, gets no note, and the note follows each
  branching redraw. On a checkbox a hidden code ticked holds the save with
  the reason offered, and a reason releases it; the note sits beside the
  options, and after an autocomplete dropdown's text box. A reason field
  label holding `$&` or `$$` reads as written in the note and the alert.
  Invisible characters alone give no reason. On a survey, Previous Page and
  Save & Return Later skip a blank `@UVREQUIRED` field but still hold a
  broken value until its own reason is given.
- `justify_module_php.php` covers the server side: the field and dictionary
  hooks, `applyJustify` (a field no covered rule checks is an error of its
  own, split from a rule written alike on several fields; two tags on one
  field refused; a reason field that is the record ID field, that REDCap
  fills in, that is read-only, that bare `@HIDDEN` hides, that carries
  `@UVJUSTIFY` itself or `@UVWRITEONCE`, or that another rule's `"return"`
  writes into refused word for word, in any letter case, and none of them
  releases a finding in the audit; `@HIDDEN-FORM`, `-SURVEY`, `-APP`, `-PDF`
  and a tag name inside another tag's value refuse nothing), the page config (no `justifyBy` reaches the page; a reason
  field never makes a locked field hold the save), the post-save audit
  (`uvalidate-justified` with `justified_by`, never the reason; a blank,
  spaces, invisible characters or a code leaves `invalid-id-saved`; the reason read at the same
  event and instance only, also for an `@UVDELTA` that compares with the
  previous event), and the legacy and durable scans (rows, counts, a second
  legacy scan on the same module counting afresh, the "Saved with a reason"
  column and sentence, the stored flag). Its `getData` stub
  returns only the fields a read asks for, so a reason field the audit
  forgot to ask for reads as blank.
- `missing_codes_php.php` adds a reason field holding a code (no reason) and
  `mlm_messages_dom_js.cjs` the note in the person's language.
- `justify_live_twin_php.php` runs the module over the live fixture on a copy
  of pid 149's events, with REDCap's data and log kept as each save, survey
  page and import of `docs/testbed/JUSTIFY_LIVE_ACCEPTANCE.md` leaves them. It
  checks every server answer of the sheet: the configuration errors word for
  word, the justify rules each page carries, every `uvalidate-justified` and
  `invalid-id-saved` entry (never the reason text), the lookup and duplicate
  answers, and both scans on pid 149 and a scratch project. It writes the page
  configs to `tests/justify_live_pages.json` with `--write`, and fails when
  that file differs from what the module builds now.
- `justify_live_twin_js.cjs` boots `js/engine.js` with those configs on a page
  shaped as REDCap renders the fixture's field types: text and notes boxes,
  radio buttons with their mirror and "reset" link, dropdowns and an SQL
  field, a checkbox, rows hidden by branching logic, a reason field embedded
  in another field's label (its own row hidden, its box in the row of that
  label), and one survey page per section. It checks each note, each hold, each line of the save alert and the
  "Save anyway?" dialog, each release once the reason is given, and each case
  that is no reason: blank, spaces, a Missing Data Code, a hidden row, a
  read-only field, and a reason on another survey page. A reason typed in
  the embedded box releases the field (C8).
- `justify_mutations.py` applies 114 mutations to a copy of the code, one at a
  time, and fails if the named suite still passes on any of them. Seven mutate
  the stores, the rollup and the status poll of the durable scan and are
  killed only by `tests/mysql/run.php` (cases `justified` and `perf`); they run
  when `UV_DB_HOST` and the other `UV_DB_*` variables name a server, and are
  reported as skipped otherwise.

## `growth_*` — growth references (z-scores)

`growth_fixture.json` holds cases for each step of a z-score: where a value
sits on the reference's axis (age in days or months, a date of birth and a
measurement date, length or height, decimal commas), the L, M and S row
(nearest row, row below, or between two rows), the z-score with WHO's
restricted adjustment beyond ±3, its two-decimal text, and the measurement
itself. `growth_php.php` runs them through `php/GrowthReference.php` and
`growth_js.cjs` through the `QRID_growth*` twins in `js/engine.js`. Both also
run the 2,880 points of `who_golden.json`, which `tools/who_golden.R` (in the
source repository, not the release package) computed with WHO's own R code (`anthro` and `anthroplus`; the file says which commits),
and 5,000 random points whose results must hash to the SHA-256 the fixture
pins, so the two runtimes agree point for point. `growth_php.php` also checks
every bundled table against its SHA-256 in `data/references/index.json`, a
folder of extra references (added, replacing by id, every refusal), the rows a
`valid` range needs (found with the lookup's own floating-point arithmetic,
for each entry on a shared file), and the z-score limits a reference can never
pass (`limitProblem`: the floor or ceiling of each row, checked against
`zRaw` at extreme measurements and between the rows of the linear tables).

The percentile and the measurement at a z-score or percentile rest on the
normal distribution function and its inverse, ported from R's `pnorm`
(Cody, ACM TOMS 715) and `qnorm` (Wichura, AS241) into both runtimes with
the same constants, cut-offs and branches.

- `growth_normal_golden.json` holds 4,825 `pnorm` and 4,033 `qnorm` points
  from R 4.6.1, made by `tools/normal_golden.R`. Both ports must match them
  to 1e-15 relative (the worst is about 5.5e-16). Below x = -37.5193 R 4.6
  returns subnormal numbers and the ports return 0, so results under 1e-300
  are compared by absolute difference. A constant mistyped in its last
  digits can stay inside that tolerance, so `growth_php.php` also checks
  that the two ports carry the same numbers, literal for literal, the
  cut-offs and branch limits included.
- `growth_outputs_golden.json` holds 720 output points, 240 each of the
  percentile, `valueAtZ` and `valueAtPercentile`, on rows of the 12 bundled
  WHO references, worked out at 50 digits with mpmath by
  `tools/growth_outputs_golden.py`. Points within 1e-9 of a rounding tie are
  left out; the fixture holds the ties.
- The fixture's `percentile`, `percentileText`, `valueAtText`, `output` and
  `outputOf` sections pin the rounding on 15 significant digits (decimal
  ties such as a median of 9.165 round up), the clamp one unit inside 0 and
  100, a value that rounds to 0 or passes 10^15 units, the measured field's
  scale, rows read between two rows, an L that interpolates to almost 0, a
  measurement no z-score holds, and the key grammar. `growth_php.php` and
  `growth_js.cjs` also pin `roundedUnits` on ties (exact binary ties round
  up, not to even), check that every output in `COMPUTED` gives a value,
  and that a probability that is not a number gives no percentile.
  `parityOutputs` pins the SHA-256 of 3,000 random points. For the same
  double both runtimes write the same text; their `exp`, `log` and `pow` can
  still differ in the last bit, which can move a value lying on a rounding
  edge by one unit, and the hash would show that among those 3,000 points.
- `growth_outputs_php.php` checks the catalog `php/growth_outputs.json`: its
  outputs, the wording of the refused keys, the engine's generated copy and
  its hand-written twins of `GrowthReference::COMPUTED` and the decimals
  limits, the refusals of a malformed file (an output no code computes
  among them), `problem()` reporting one without throwing, and that a
  catalog with fewer outputs or other parameter ranges changes what
  `GrowthOutputs::parse` accepts with no code change.

- `growth_dom_js.cjs` boots the browser rule: the note names the z-score and
  the reference, an input's change re-checks, grey notes say why a check did
  not run (staff only), saved inputs never block, withheld inputs are not
  guessed at, and a rule over the page's table cap says the save checks it.
- `growth_module_php.php` covers the module: the grammar and its refusals, the
  dictionary hook (reference, axis, input field kinds, sex codes, a limit out
  of reach), the page
  (folded inputs, tables in use, the cap), the audit and the scan.
- `growth_return_dom_js.cjs` and `growth_return_module_php.php` cover what a
  rule with `"return"` writes into fields, the percentile and the values at
  a z-score or percentile included. The values themselves are pinned by
  the `outputs`, `output`, `valueAt`, `bound` and `unitsText` sections of
  `growth_fixture.json`, through both twins; `growth_php.php` and
  `growth_js.cjs` also check on random rows that each bound is the edge the
  verdict draws (the bound passes, one unit beyond it does not). The DOM suite
  checks the fill on opening (no change event, so the form is not marked as
  changed), the refill after a judgement (an `input` and a `change` event), a
  blank input emptying the fields, `returnLimits`, `decimals` and each field's
  decimal mark, the fields of a branched rule, two references on one field
  each filling their own, `measureScale` with and without `unit`, a rule the
  page cannot judge leaving its fields alone, and REDCap's `calculate` and
  `doBranching` being told. The module suite checks that a save writes only
  differing fields, one `saveData` call per record context with the saved one
  first and a refused or failing context logged without stopping the others,
  the number spelled another way, clearing, a problem kept and logged once,
  the two phases (rules that return values are judged and written first, on
  the event/instance path too, so a rule that reads a returned field sees it),
  the fields a branch or a reference owns on the event/instance path, the
  project setting that turns the write off, the scan reading only, repeating
  forms, a form saved for an input on another form, an input in another event,
  `measureScale` and `unit`, an integer bound field, a rule that returns only
  values needing no limit, `percentileDecimals` and the places each target
  field must take (a place count the key cannot reach is not offered), one
  value under two spellings, a key that shapes nothing returned, 16 values
  and not 17, the documented WHO values, a catalog that cannot be read (the
  rule is refused, the page opens, the save runs and the form's other rules
  are still audited), the dictionary hook's
  refusals (chains and two references sharing a field included), and the live
  fixture `docs/testbed/uvrange_return_test_fields.csv` loaded as the data
  dictionary.
- `growth_curve_php.php` measures the claim `php/GrowthCurve.php` makes: on
  every bundled reference, both sexes and three sets of lines (beyond ±3 SD
  included), it decodes the curves the page gets, evaluates them at every
  table row (where a table read by `round` or `floor` is read, and where the
  page draws this visit's point) and, for a table read by linear
  interpolation, at a quarter, a half and three quarters of the way between
  rows, and divides the worst error in each window of the narrowest width by
  the y range the browser gives that window. The bound is 0.5 of the
  312-unit-tall plot; the run prints the worst it found. It also covers the
  payload size, `measureScale`, a curve with a gap, a fixed span (zoom 1,
  fewer points), curves too large to send or too ragged for the point cap,
  the span against the reference and the rows of a table of reference values
  (`showProblems`, per part), and that `QRID_CHART` in the engine has the
  same geometry.
- `growth_chart_module_php.php` covers `show` through the module: the
  grammar with its defaults and refusals (lists for objects, labels with
  line separators or bidirectional controls, a label given twice), the
  dictionary hook's notes for a span or a row count the reference does not
  allow, the page's curve sets (one sex for a saved sex, both for a typed
  one, none for a chart of z-scores or a sex saved blank), the tables of
  reference values shared in `growthCurveTables`, branches of one field, the
  `pageCharts` cap (0, past it, out of range) and its notes. It loads
  `docs/testbed/uvrange_chart_test_fields.csv` as the data dictionary and
  checks what section K of the live acceptance sheet expects. It also builds
  the pages `growth_chart_dom_js.cjs` draws, with the cells the server
  computes for five visits, and writes them to `growth_chart_fixture.json`
  with `--write` (the run fails when the file is stale).
- `growth_chart_dom_js.cjs` drives the real range factory with those pages
  through a DOM stub that knows `createElementNS`, attributes, `textContent`
  and a cell's width. It checks the helpers (ticks, the x unit and
  `chartUnit`, the window, cutting a curve, the y axis, the row the check
  reads), the container and its `<details>` on forms and surveys, the
  heading and legend, the SVG's role, title and description, that nothing in
  the container uses `innerHTML` or a `style` attribute, the curves as drawn
  against the exact ones, the marker moving alone and sitting at the row
  read, arrows on every edge, one-sided bands, the empty states, a rule the
  page withholds, a part that fails without hiding the others, a rule that
  only shows and has no limits, both tables
  (this visit's cells equal to the server's, in the field's decimal mark),
  the cap note, a branch that does not apply, and a language switch that
  relabels the labels, the unit and sex words in place. It prints the time
  to build a page of 4 charts and to move a marker.
- `growth_chart_gaps_module_php.php` loads
  `docs/testbed/uvrange_chart_gaps_fields.csv` as the data dictionary of a
  project laid out as pid 149 is (two events, one repeating instrument, the
  map of what repeats on the `\Project` object only, as on REDCap 17.0.6) and
  checks what steps K18 to K33 of the live acceptance sheet expect from the
  server: language maps for the chart and table labels, one sex's curves
  and reference values for a sex saved on another form (both for a typed
  one), a sex saved blank, two branches switching reference by age, a
  comma-decimal field, a chart of z-scores with percentile lines, a charted
  field on another event and on a repeating instrument, and an input read
  from another event with and without event and instance references. It
  works out the visits the steps type with `GrowthReference` and writes the
  pages, those visits and the reference rows once to
  `growth_chart_gaps_fixture.json` with `--write`.
- `growth_chart_gaps_dom_js.cjs` draws those pages with the real range
  factory, 327 pixels wide as REDCap 17 gives a field's column, and checks
  every text the steps expect against the server's numbers: the headings,
  axes, line names, tooltips, descriptions and tables in English and after a
  switch to French and back, the comma value in the tooltip, table and
  point, the closed z-score chart, the active branch's chart only, the notes
  that name saved fields, and the note for a sex saved blank.

To refresh `who_golden.json`, see the header of `tools/who_golden.R`; the
same seed gives the same file byte for byte.

`range_dom_js.cjs` and `growth_dom_js.cjs` start each page with
`page_defaults.json`, the page-wide defaults the server sends
(`UniversalValidator::defaults()`, which `range_module_php.php` compares it
with). The engine fills every key a rule lacks from them, `blockSave` "off"
among them, so a test page without them can pass a rule that a real page
would not block. `convert_php.php` runs `data/references/convert.php` on
WHO- and CDC-shaped tables, checks that the index entry it prints is accepted
as printed, and checks each refusal. It also runs each conversion and each
`--check` that Level 7 of the `@UVRANGE` guide prints on the made-up tables in
`data/references/examples/`: the guide must print the command verbatim, the
conversion must rebuild the committed table byte for byte, and the check must
print what the guide shows. `docs_examples_php.php` and `docs_examples_js.cjs`
read that folder as a folder of extra references, so the guide's rules on
`example-hfa`, `example-wfl` and `example-bwga` are tested like the WHO ones.

## `missing_codes_*` — Missing Data Codes

A field marked with one of the project's missing data codes holds the code.
Each mode says in `php/modes.json` (`missingCodes`) what that means to it:
`skip` treats the field as blank, `answer` (`@UVREQUIRED`) counts it as filled
in. `missing_codes_dom_js.cjs` checks every browser factory with a code, then
an ordinary bad value, then the code again, plus spaces around a code, case,
a `@UVWINDOW` "from" date and an `@UVEXISTS` `match` field holding a code.
`missing_codes_php.php` checks REDCap's "CODE, Label" lines, the registry, the
post-save audit with and without codes configured, the scan (two records
marked `UNK` are not duplicates), the `unique-check` and `exists-check`
endpoints, the page config (codes, never their labels), and a code in a
`@UVJUSTIFY` reason field, which gives no reason.

## `hook_php.php` — the audit-path contract

Mocks the External Modules framework and `REDCap::getData`/`getDataDictionary`.
The mocks are deliberately strict: settings reads return nothing unless a
resolvable project id reaches them, which models import/API contexts where
`getProjectId()` is null — the module must thread the hook's `$project_id`
explicitly or the suite fails. Covered: both configuration channels, all four
log-privacy modes on the success AND exception paths, keyed-HMAC hashing and
key persistence, event scoping (a value under another event is never audited),
instrument scoping, repeat instances, duplicate-field skips, per-rule
isolation, `uvalidate-unconfigurable` logging, non-ASCII values (checked, or
reported when the regex engines may disagree), the non-text field rejection,
and the save-time `validateSettings` gate.

## `value_parity_fixture.json` — the browser/server value contract

One value typed into an `@UVALIDATE` field must get the same answer from the
browser and from the save-time audit. Each row holds a rule, a value, what the
browser shows (`blank`, `valid`, `invalid`) and what the server says (`skip`,
`valid`, `not-checked` or the finding's reason). `value_parity_dom_js.cjs` runs
the rows through the real engine on a stub page; `value_parity_php.php` runs
them through `CheckCharacter` with the config `findingsCheck` builds, checks
that the two columns of every row agree, and locks `browserTrim`, `jsLength`
and `dialectSplits`. The rows cover edge whitespace (VAL-3), characters outside
printable ASCII (VAL-1), the regex constructs that JavaScript and PCRE read
differently (`.`, `\s`, `\S`, negated classes, astral characters) and the
512-unit length cap (VAL-12).

## `tests/mysql/` — the real-database suite

`tests/mysql/cases/justified.php` checks `uv_finding.justified` (@UVJUSTIFY,
schema version 6) on a real server. It covers the column on a fresh install
and after migrating a version-5 installation that holds findings (run again,
and run with the column added but the version row lost); the 1 and 0 the
store writes and `FindingRowMapper` reads back, in both stores; the keys
that leave the flag out of a finding's identity, so a finding saved again
with its reason given becomes a new version and the same identity twice in
one batch is refused; the rollup's open and justified counts, every page
read through `ix_page_v2`; and a run whose only findings are justified,
promoted clean.

## CI

`.github/workflows/parity.yml` runs the JS suite on Node 20 and the PHP suite
on PHP 7.4, 8.1, 8.3 and 8.4 (the declared minimum is exercised, not just
stated), `php -l`-lints the PHP, checks that `pooled_fixture.json`,
`when_fuzz.json` and the mode block in `js/engine.js` are regenerated, and
builds a release-shaped `universal_validator_vX.Y.Z.zip` to verify the package
layout (required files present, development-only trees excluded, namespace
matching). Green CI means the module's client and server engines agree with
each other and with the ID generator — it does NOT replace the live-REDCap
checklist in [`../docs/TESTING.md`](../docs/TESTING.md).

## Note on the PHP port

`php/CheckCharacter.php` is a direct transliteration of the Python engine and the
browser parser. Its lookup tables (Damm, Verhoeff), alphabet constants, and ISO
parameters have been diffed against the authoritative Python values; the pooled
splitter mirrors `js/engine.js`. Because a local PHP runtime is not required to
develop the module, `parity_php.php` and `pooled_php.php` are the authoritative
end-to-end checks for the server engine and run in CI on every push.

## Event and instance validation

Run `php tests/qualified_php.php`, `php tests/binding_php.php`, `php tests/temporal_value_php.php`, `php tests/temporal_logic_php.php`, and `php tests/temporal_integration_php.php`; then `node tests/qualified_js.cjs`, `node tests/temporal_logic_js.cjs`, and `node tests/deferral_dom_js.cjs`. Shared temporal fixtures cover exact rational comparisons, invalid dates, blank typed dates, Boolean unknowns and live members. `php tests/temporal_adversarial_php.php` is differential: it resolves every reference and binding shape from every host context of 60 seeded random records through both the shared (once-per-record) and the unshared resolver and requires identical results, then checks exact-decimal addition against the digit-string path on 20,000 pairs. Integration reads filter requested fields so omitted dependencies cannot pass by accident.

`php tests/docs_examples_php.php` parses every action tag printed in `docs/action_tag_validation_examples.md`, `docs/EVENT-INSTANCE-REFERENCES.md` and `docs/region-site-choices-example.txt` with the real annotation parser. Tags in a block fenced as `text invalid` must be refused; every other tag must parse without a configuration error. A `# refused: <text>` comment before a refused tag requires its error to contain that text. A block fenced as `text expect` lists `input => result` lines for the last `@UVALIDATE`, `@UVRANGE` or `@UVEXISTS`-with-`where` tag of the block before it; each line must produce the printed result. An `@UVALIDATE` or `@UVRANGE` line runs through the server verdict. An `@UVEXISTS` line gives the entry that holds the value (`SP-1 with consent=1, status=3 => filtered`) and runs only the `where` test the lookup makes on one entry (`AnnotationRules::existsWhereHolds`), with no lookup and no read; it is not written to the fixture. The same lines are written to `tests/docs_expect_fixture.json` (regenerate with `--write`; a stale fixture fails the test), and `node tests/docs_examples_js.cjs` replays them through the browser engine, which must accept or refuse each one-ID value and split each pooled value exactly as the server did.

Run the existing complete CI matrix and `tests/mysql/run.php` against its disposable private schemas. Local mocks do not establish REDCap metadata shapes or import/deletion hook behavior; follow the deployment acceptance section in `docs/EVENT-INSTANCE-REFERENCES.md` for the real-project pilot.

`PHP_BIN=php python3 tests/temporal_mutations.py` runs four defects against disposable copies, verifies they remain syntactically valid, and requires each to fail its regression suite. It never mutates the checkout. `python3 tests/exists_where_mutations.py` does the same for `@UVEXISTS` `where` and for reading a condition whole: 28 defects, such as no confirming read, the condition left out of the lookup memo or the scan index key, a blank side of `<` passing, the condition sent to the page, `whereMessage` sent to a survey page, a survey told the value is filtered or given the narrowed read, a record ID read without its `where` fields, the report explaining a filtered finding with `message`, a token mode read as a condition, `&&` or a next line that goes on read as a note, no length cap on a bare condition, the page trimming a condition with a regex, and branch conditions compared as text. Each must fail the suite named beside it. `tests/temporal_golden.json` fixes synthetic read parameters, payloads, audit/scan results and rule identities; update deliberately with `php tests/temporal_integration_php.php --update-golden` and review the diff. The mock HMAC key is fixed synthetic test data.

`choices_dom_js.cjs` also consumes `choices_region_fixture.json`, covering the six-region alphanumeric site lists, grouped native options, hidden mirrors, jQuery-only changes and cached autocomplete responses. Real-browser verification uses a local jQuery UI fixture; it does not certify a live REDCap deployment.
