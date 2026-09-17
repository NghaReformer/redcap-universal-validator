# Event and repeating-instance references

Enable **Enable event and instance references** in the project's module settings. It is off by default. Existing rules without extended references retain their existing behavior. Extended rules support all five validation modes, conditions and branches, post-save audits, and direct/durable scans.

Extended browser checks are advisory. Exact current-page operands remain live; other entries are snapshots taken when the page opens. Save and reload after changing a matching key or another entry. The module never changes saved data.

## Scalar references

```text
[baseline_arm_1][consent_date]
[weight][2]
[weight][previous-instance]
[followup_arm_1][weight][last-instance]
[previous-event-name][weight]
[baseline_arm_1][consent(1)]
```

Use unique event names, not display labels. Supported event selectors are `event-name`, `previous-event-name`, `next-event-name`, `first-event-name`, and `last-event-name`. Relative events stay in the current arm and consider only events designated for the referenced instrument. Named events can address another arm in the same record.

Instance selectors are positive integers, `current-instance`, `previous-instance`, `next-instance`, `first-instance`, and `last-instance`. Previous/next mean the current number minus/plus one: instance 3's previous instance is 2 even if 2 was deleted. First/last select existing minimum/maximum numbers. Relative instance selectors need a repeating source context. Instance selectors on non-repeating targets are invalid.

Without an instance selector, a reference into an independent repeat bucket is ambiguous. Use an explicit selector or shared-key match. A missing row is unresolved, not a blank answer. Existing all-blank repeat rows are retained using instrument completion markers.

## Named bindings and matching

Tag JSON accepts a `references` object. The settings dialog accepts the same object in **Extended reference bindings**. Expressions refer to its aliases with `{alias}`. Bindings are declarative; arbitrary code, SQL, functions, and unsupported REDCap smart variables are rejected.

```text
@UVASSERT={"assert":"[result]>={matched}","references":{"matched":{"field":"threshold","event":"collection_arm_1","match":{"specimen_id":"[result_specimen_id]"}}}}
```

The target instrument comes from the dictionary. Keys use exact string equality, preserving case and leading zeros. Multiple keys use conjunction. Blank keys, zero matches, and multiple matches are unresolved for scalar lookups. A match cannot also specify an instance. Match source operands are plain scalar field references. Changing a live source key invalidates the lookup until save/reload; there is no refresh endpoint.

## Collections and exact numbers

Direct predicates can use `[field][any-instance]` or `[field][all-instances]`. Only one collection operand is allowed per comparison.

```text
@UVASSERT={"assert":"[weight]<={mean_weight}","references":{"mean_weight":{"field":"weight","aggregate":"average"}}}
```

Bindings accept `aggregate`: `count`, `exists`, `populated-count`, `distinct-count`, `sum`, `minimum`, `maximum`, `average`, `any`, or `all`. `count` counts rows, including saved blank rows; `exists` returns `1` or `0`. Distinct count compares exact strings. Numeric operations ignore saved empty strings, reject populated nonnumeric values, and use exact decimal arithmetic. Average comparisons use a rational numerator/denominator, without rounding.

Current-page members remain live and appear once. Set `excludeCurrent: true` to omit the exact current entry. Select several events with `events: ["baseline_arm_1", "followup_arm_1"]`, or use `events: "arm", arm: 1` for all designated events in that arm. A binding accepts `event` or `events`, not both.

Empty counts return zero; empty `exists` returns zero. Other empty operations are unresolved. Each collection is bounded at 10,000 members; decimal arithmetic is bounded at 4,096 digits. A shared 100,000-unit evaluation budget also bounds work across host contexts in one record/page/audit, charging row lookups and value size. Exceeding a limit produces an unresolved result, never a partial aggregate. Reduce exceptionally large rule/collection workloads before rescanning a budget-exhausted record.

## Typed dates and elapsed time

Legacy expressions keep their existing comparison semantics. Use typed bindings for calendar validation:

```text
@UVASSERT={"assert":"{end}>={start}","references":{"end":{"field":"result_date","type":"date"},"start":{"field":"specimen_date","event":"collection_arm_1","instance":"first-instance","type":"date"}}}
```

Supported types: `date`, `datetime`, `datetime_seconds`. Saved values use canonical year-month-day formats; live values use the dictionary's configured display order. Invalid calendar values and mixed date/datetime comparisons are unresolved.

```text
@UVASSERT={"assert":"{elapsed}<=48","references":{"elapsed":{"field":"result_time","type":"datetime","elapsedFrom":"[collection_arm_1][collection_time][first-instance]","unit":"hours"}}}
```

Elapsed values are signed (`target - elapsedFrom`). Units are days, hours, minutes, or seconds. Date-only bindings allow calendar days. Datetimes use timezone-less wall-clock arithmetic, with no browser-local timezone or daylight-saving conversion. This is not a general REDCap `datediff()` evaluator.

## Within-record uniqueness

```text
@UVUNIQUE=record
@UVUNIQUE={"scope":"record","with":["specimen_type"]}
```

This compares entries on the target instrument across its designated events and instances in one record. It excludes only the exact current event/instrument/instance. Composite components use case-sensitive strings with the same ASCII trimming as ordinary uniqueness; leading zeros remain significant. Multiple target fields are evaluated independently. Findings retain the uniqueness mode and are not sent through the distinct-record duplicate finalizer.

Project/DAG/event uniqueness keeps its existing cross-record behavior. Record-local browser checking uses authorized snapshots and live current operands, without a new AJAX endpoint. Branches can select different uniqueness scopes. Cross-record branches with extended selectors require authenticated source-read access for their AJAX check; unavailable/survey lookups defer to saved-data auditing and scans.

## Permissions, unresolved results, and operations

Raw off-page values are included only when the current user may read their source instruments. Survey payloads do not include protected source values or collections. A wholly server-resolved Boolean may be sent; comparisons needing both protected data and live input are deferred. Deferred selectors never activate an `else` branch. Advisory feedback cannot prevent API/import writes or race conditions.

Relevant saves re-evaluate dependent host contexts, including another event on the same instrument. Unrelated instrument saves do not cause extended data reads. The default synchronous audit limit is 500 host contexts; configure **Maximum extended audit host contexts** to change it. Read failures or truncation emit an incomplete-audit notice directing the user to a scan.

Run scans after deletions and write paths that do not invoke the save hook. Hook coverage, repeating-event metadata representations, and rights behavior must be verified in the site's REDCap development project. No universal deletion-hook guarantee is made. Scans report unresolved rules separately and retain existing DAG/export restrictions.

Durable scans fingerprint the dialect, metadata, designation/repeat structure, dependencies and rule revisions. A changed configuration refuses further work on an old run; stop it and start a new scan. Disabling the feature preserves authored configuration and reports extended rules as unconfigured. It is the operational rollback; no stored record data is changed.

## Deployment acceptance

The local suite covers PHP 7.4/8.1/8.3/8.4, JavaScript parity/DOM behavior, and MySQL 5.7/8.0 plus MariaDB 10.5/10.11. This does not replace a live REDCap pilot. Before enabling in production, verify classic/longitudinal/multi-arm metadata, repeating events and forms, form/survey permissions, actual API/import hook behavior, deletion reconciliation, and rollback in an authorized development project. REDCap 13.7 remains a compatibility target, not a newly verified live-version claim.
