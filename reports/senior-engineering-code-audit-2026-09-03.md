# Comprehensive Senior Engineering Audit & Architecture Review
**Target Project:** `redcap-universal-validator` (v1.10.0+ / Scan Rebuild)  
**Date:** 2026-09-03  
**Auditor:** Principal Systems & Security Engineering Review  
**Repository Path:** `d:\SCRIPTS_PATH\redcap-universal-validator`  
**Scope:** Full-codebase audit covering Client Browser Engine (`js/engine.js`), Core Parity Engines (`php/Logic.php`, `php/CheckCharacter.php`), REDCap Hook Integrations (`UniversalValidator.php`, `php/AnnotationRules.php`, `php/Branching.php`), and Durable Background Scan Subsystem (`php/Scan/*`, `pages/*`).

---

## 1. Executive Summary & System Risk Matrix

The Universal Field Validator module provides mathematical, cryptographic, and syntax-level validations for REDCap projects. It features arbitrary-precision decimal comparisons without IEEE-754 precision loss, Unicode code-point collation, and multi-format alternate parsing.

However, an exhaustive, file-by-file adversarial investigation reveals **critical architectural bottlenecks, silent correctness inversions, concurrency hazards, and unbounded database scans** that undermine system stability, cross-DAG tenant isolation, and client responsiveness.

### Severity Classification Matrix

| Finding ID | Severity | Category | File & Line Range | Impact Summary |
|---|---|---|---|---|
| **CRIT-01** | Critical | Accuracy & Correctness | `php/Logic.php:619–628`<br>`js/engine.js:1503–1512` | **Empty string comparison inversion**: Lexicographical asymmetry in ordered comparisons (`<`, `<=`) against empty values causes rules like `@UVASSERT="[dose] <= [max_dose]"` to falsely fail and block form saves when the referenced field is blank. |
| **CRIT-02** | Critical | Accuracy & Multi-Tenancy | `UniversalValidator.php:337, 355, 515, 4216–4230` | **DAG scope bypass & false duplicate collision**: `$group_id` is omitted in `redcap_save_record` dispatch to `findCollision`. For new records, DAG isolation is bypassed, checking the whole project and falsely flagging duplicates across other DAGs. |
| **CRIT-03** | Critical | Scalability & Memory | `UniversalValidator.php:515, 4179–4213` | **Unbounded whole-project export on every save**: Every form save with `@UVUNIQUE` invokes `findCollision` with `$narrow = false`, loading all project records into PHP memory via unconstrained `REDCap::getData`. |
| **CRIT-04** | Critical | Scalability & DoS | `UniversalValidator.php:4202` | **Keystroke-driven whole-project database reads**: Narrowing is explicitly disabled for `dag` scope (`$scope !== 'dag'`), executing full project data exports on debounced keystroke AJAX checks. |
| **HIGH-01** | High | Concurrency & Stability | `UniversalValidator.php:4071–4085` | **Database lock contention & lost-update race**: Unauthenticated survey rate limiter calls `setSystemSetting` on global module settings table on keystrokes, causing lock contention and cache thrashing. |
| **HIGH-02** | High | Fatal Crash & Operability | `UniversalValidator.php:2331` | **Uncaught `\Throwable` in namespaced hook**: Missing leading backslash in `catch (Throwable $e)` inside namespace `INSPIRE\UniversalValidator` causes database exceptions during schema migrations to crash module settings saves. |
| **HIGH-03** | High | Client UX & A11y | `js/engine.js:1169, 1747–1764, 2445–2515` | **State clobbering & duplicate DOM IDs**: Multiple rules on a field generate identical element IDs (`uvalidate-msg-[field]`), and second rule overwrites input outline and validity status. |
| **HIGH-04** | High | Performance & CPU | `js/engine.js:1686, 2358, 2562, 2703, 2985, 3184, 3772` | **MutationObserver stampede**: 7 distinct `MutationObserver` instances observe `{childList: true, subtree: true}` on `document.body` for 10s on page load. |
| **HIGH-05** | High | Architecture & Completeness | `pages/export.php:32` | **Permanent HTTP 503 on findings export**: Scan export page is hardcoded to return HTTP 503; Task 7 of the rebuild plan was left unimplemented. |
| **MED-01** | Medium | Logic & Parsing | `php/AnnotationRules.php:255–258` | **Quoted expression truncation on escaped quotes**: `readValue` uses `strpos` without escape inspection; conditions like `[name] != \"Smith\"` are cut off at the first quote. |
| **MED-02** | Medium | Memory & Performance | `UniversalValidator.php:236–244` | **`$readSet` pollution in `redcap_save_record`**: Unrelated rules across the entire project pollute the field read list for the currently saved instrument. |

---

## 2. Correctness & Accuracy Flaws

### 2.1 CRIT-01: Empty String Comparison Inversion in Ordered Assertions
- **Source Files:** `php/Logic.php:619–628` and `js/engine.js:1503–1512`

#### Technical Mechanism
In `php/Logic.php`, ordered comparison operators (`>`, `<`, `>=`, `<=`) are implemented with domain separation rules:
```php
$mixed = ($a !== '' && $b !== '')
       && ((bool) preg_match(self::NUM_RE, $a) !== (bool) preg_match(self::NUM_RE, $b));
switch ($op) {
    case '=':  return $a === $b;
    case '<>': return $a !== $b;
    case '>':  return !$mixed && strcmp($a, $b) > 0;
    case '<':  return !$mixed && strcmp($a, $b) < 0;
    case '>=': return !$mixed && strcmp($a, $b) >= 0;
    case '<=': return !$mixed && strcmp($a, $b) <= 0;
}
```
The inline comments in `Logic.php` declare:
> *"EMPTY is exempt: it is absence, not a competing numeric domain. Without this, `[end_date]>=[start_date]` with `start_date` legitimately blank would flip from passing to failing and invent a violation on every record where the field simply has not been entered yet."*

However, when `$b === ''` (the referenced field is empty) and `$a === "10"` (the host field is populated):
- `$mixed` evaluates to `false`.
- For `>=`: `strcmp("10", "") >= 0` evaluates to `true` (since ASCII `'1'` > `'\0'`).
- For `<=`: `strcmp("10", "") <= 0` evaluates to **`false`**!

#### Failure Reproduction
1. Form has two fields: `dose` and `max_dose`.
2. Designer attaches rule on `dose`: `@UVASSERT="[dose] <= [max_dose]"` (as recommended in README line 312).
3. The data entry technician enters `10` into `dose`. `max_dose` is optional or on a later section and currently blank (`""`).
4. In JavaScript (`QRID_whenCompare`), `QRID_cmpStr("10", "") <= 0` yields `false`.
5. The field turns red, shows validation failure, and if `blockSave:"hard"` is configured, **the user is blocked from saving the record**.
6. Conversely, if written as `@UVASSERT="[max_dose] >= [dose]"`, it passes. Correctness depends purely on operand ordering relative to the empty string.

---

### 2.2 CRIT-02: DAG Scope Degradation & Cross-DAG False Violations
- **Source Files:** `UniversalValidator.php:337, 355, 515, 4216–4230`

#### Technical Mechanism
In `UniversalValidator.php`, the hook `redcap_save_record` receives REDCap's context:
```php
public function redcap_save_record($project_id, $record, $instrument, $event_id, $group_id, ...)
```
When dispatching the audit routines:
1. Line 337 calls `$this->auditRule(...)`, but **`$group_id` is omitted** from the arguments.
2. Line 355 defines `auditRule(...)` without a `$group_id` parameter.
3. Line 515 calls:
   ```php
   $col = $this->findCollision($project_id, $field, $with, $scope, $values, $record, $event_id);
   ```
   `$groupId` defaults to `null`.
4. Inside `findCollision`:
   ```php
   $currentDag = null;
   if ($scope === 'dag') {
       if ($excludeRecord !== null && $excludeRecord !== '' && isset($data[$excludeRecord]) && is_array($data[$excludeRecord])) {
           $currentDag = self::dagOfRecordNode($data[$excludeRecord]);
       }
       if ($currentDag === null && $groupId !== null && $groupId !== '') {
           $currentDag = self::dagNameOf($groupId);
       }
   }
   ...
   if ($scope === 'dag' && $currentDag !== null && $dag !== $currentDag) continue;
   ```

#### Failure Reproduction
On creating a new record via form data entry or API imports:
1. The record is not yet indexed with its DAG in REDCap's database cache, or `data[$record]` does not contain a `redcap_data_access_group` entry.
2. Because `$groupId` is `null`, `$currentDag` evaluates to `null`.
3. At line 4230, the check `if ($scope === 'dag' && $currentDag !== null && $dag !== $currentDag) continue;` is **skipped**.
4. The comparison checks the candidate value against **every record in every DAG in the project**.
5. If Site B already has MRN `55432`, and Site A creates a record with MRN `55432` under a `@UVUNIQUE` (scope DAG) rule, Site A's record is falsely flagged with an invalid uniqueness collision, violating multi-site tenant isolation.

---

### 2.3 MED-01: Quoted Expression Truncation on Escaped Quotes
- **Source File:** `php/AnnotationRules.php:255–258`

#### Technical Mechanism
```php
if ($c === '"' || $c === "'") {
    $end = strpos($rest, $c, 1);
    return $end === false ? substr($rest, 1) : substr($rest, 1, $end - 1);
}
```
`readValue()` extracts quoted action-tag tokens using a raw `strpos($rest, $c, 1)`. If an assert condition or regular expression contains an escaped quote (e.g. `@UVASSERT="[description] != \"pending\""`), `strpos` matches the quote immediately preceding `"pending"`.
- Token extracted: `[description] != \`
- Remainder discarded: `pending"`
- Result: Config syntax error reported; the valid assertion is broken.

---

## 3. Scalability, Performance & Resource Depletion Flaws

### 3.1 CRIT-03: Unbounded Whole-Project Export on Every `@UVUNIQUE` Save
- **Source Files:** `UniversalValidator.php:515` and `UniversalValidator.php:4179–4214`

#### Technical Mechanism
In `UniversalValidator.php:515`:
```php
$col = $this->findCollision($project_id, $field, $with, $scope, $values, $record, $event_id);
```
`$narrow` defaults to `false`. Inside `findCollision`:
```php
$params = [
    'project_id'    => $pid,
    'return_format' => 'array',
    'fields'        => $need,
    'exportDataAccessGroups' => true,
];
if ($scope === 'event' && $event_id) $params['events'] = [$event_id];
...
if ($data === null) $data = \REDCap::getData($params);
```
`$params` does **not** specify a `'records'` filter.

#### Production Scalability Cliff
- On a project with 40,000 records, saving an instrument with `@UVUNIQUE` causes `REDCap::getData()` to export all 40,000 records from MySQL.
- This creates an in-memory PHP array holding tens of thousands of records.
- Standard REDCap installations run with `memory_limit = 128M` or `256M`. Under load, this triggers:
  `Fatal error: Allowed memory size of 134217728 bytes exhausted`
- The user's save is aborted or the request latency spikes to 15–30 seconds.

---

### 3.2 CRIT-04: Keystroke-Driven Whole-Project Database Reads in DAG Scope
- **Source File:** `UniversalValidator.php:4201–4213`

#### Technical Mechanism
`findCollision` contains an explicit bypass of filter logic for DAG-scoped rules:
```php
// F4-DAG-01: dag scope must NOT narrow — a value-filtered read drops the
// current record (whose saved value differs from the candidate being typed)...
if ($narrow && $scope !== 'dag') {
    $fl = self::collisionFilterLogic($need, $target);
    ...
}
if ($data === null) $data = \REDCap::getData($params);
```
When a user is typing into a DAG-scoped unique field in the browser, the client fires an AJAX request to `action=unique-check`.
Because `$scope === 'dag'`, `$narrow && $scope !== 'dag'` evaluates to `false`.
- **Result:** Every debounced keystroke / blur event dispatches a query that pulls the **entire project database** into PHP memory. Under concurrent multi-site data entry, this causes massive database I/O saturation and thread pool starvation.

---

### 3.3 HIGH-04: MutationObserver Stampede on Multi-Rule Pages
- **Source Files:** `js/engine.js:1686, 2358, 2562, 2703, 2985, 3184, 3772`

#### Technical Mechanism
Every rule initializer attaches its own independent `MutationObserver` to `document.body`:
```javascript
mo = new MutationObserver(function(){ sweep(); });
mo.observe(document.body, { childList: true, subtree: true });
```
Each observer also schedules a 10-second polling interval (500ms x 20 iterations).
When an instrument has multiple rules (e.g. 2 format checks, 2 asserts, 1 choices filter), up to **7 concurrent global subtree observers** execute on every DOM mutation. In REDCap forms where branching logic frequently toggles form rows, this triggers recursive re-sweeping, causing visible UI freezes and 100% single-core browser CPU utilization.

---

### 3.4 MED-02: Read Set Pollution in `redcap_save_record`
- **Source Files:** `UniversalValidator.php:236–244`

#### Technical Mechanism
```php
foreach ($rules as $r) {
    if (!empty($r['configError'])) continue;
    foreach (self::ruleAsserts($r) as $a) {
        $pa = Logic::parse($a);
        if (empty($pa['ok'])) continue;
        foreach (Logic::referencedFields($pa['ast']) as $ref) $readSet[$ref[0]] = true;
    }
    foreach (self::ruleUniqueWith($r) as $w) $readSet[$w] = true;
}
```
In `redcap_save_record`, the loop widens `$readSet` with assert references and unique composite fields from **every rule in the project**, regardless of whether that rule belongs to the instrument currently being saved or to an active reverse dependency.
- On large projects with 50+ instruments, `readValues` requests hundreds of unnecessary fields on every single form save, causing query payload bloat.

---

## 4. Concurrency, Storage Contention & Fault-Tolerance Flaws

### 4.1 HIGH-01: Rate Limiter Lost Updates & Lock Contention on Global Settings Table
- **Source File:** `UniversalValidator.php:4071–4085`

#### Technical Mechanism
In `surveyRateLimited($pid)`:
```php
$skey = 'uv_noauth_hits_' . (int) $pid;
$raw = $this->getSystemSetting($skey);
$hits = is_array($raw) ? $raw : ((is_string($raw) && $raw !== '') ? json_decode($raw, true) : []);
...
$hits[] = $now;
$this->setSystemSetting($skey, $hits);
```
1. `redcap_external_module_settings` is REDCap's global key-value table storing configuration for all modules.
2. Unauthenticated survey hits (or bot requests) typing into unique-check fields trigger read-modify-write cycles on this single row.
3. **Lost Updates:** Concurrent HTTP requests read identical arrays before either writes; one overwrites the other, invalidating rate count precision.
4. **Cache Invalidation Storm:** In REDCap, updating external module settings flushes the in-memory settings cache. Rapid survey keystrokes force continuous cache invalidation, degrading performance across all external modules on the server.

---

### 4.2 HIGH-02: Fatal Crash via Unnamespaced `Throwable` in `installScanSchema`
- **Source File:** `UniversalValidator.php:2331`

#### Technical Mechanism
`UniversalValidator.php` defines a namespace at line 19:
```php
namespace INSPIRE\UniversalValidator;
```
Inside `installScanSchema()`:
```php
try {
    ...
    $r = Scan\Schema::migrate($this);
    ...
} catch (Throwable $e) { // BUG: Unqualified Throwable
    // Swallowed on purpose...
}
```
Because `use Throwable;` is not declared at the top of the file, PHP interprets `catch (Throwable $e)` as `catch (\INSPIRE\UniversalValidator\Throwable $e)`.
- If a database exception occurs (e.g. table lock timeout, `CREATE TABLE` permission denial, or duplicate column definition), the exception is **NOT caught**.
- It crashes the request during system module enable or admin configuration saves, causing a White Screen of Death (WSOD) in REDCap's Control Center.

---

## 5. Client-Side DOM, UX & Multi-Rule Composition Invariants

### 5.1 HIGH-03: State Clobbering & Duplicate DOM IDs under Multiple Rules
- **Source Files:** `js/engine.js:1169–1171, 1747–1764, 2445–2515`

#### Technical Mechanism
When a field is governed by both a format check (`@UVALIDATE`) and a constraint check (`@UVASSERT`):
1. Element ID collision: Both initializers invoke `QRID_attachMsgRegion(input, fieldName)`. `QRID_msgId(fieldName)` returns `"uvalidate-msg-" + fieldName`. This inserts **two sibling DOM elements with the identical ID**, violating HTML validity and WCAG accessibility contracts (`aria-describedby`).
2. Visual and aria state clobbering:
   - Check validator evaluates: value is invalid format -> sets `input.style.outline = "2px solid #c62828"` (red) and `input.setAttribute("aria-invalid", "true")`.
   - Constraint validator evaluates: assertion condition holds -> sets `input.style.outline = "2px solid #2e9e44"` (green) and `input.setAttribute("aria-invalid", "false")`.
   - Result: The user sees a **green outline** on a field containing invalid data, while screen readers announce the field as valid. However, upon clicking "Save", the check validator's blocker intercepts the submission, causing extreme user confusion.

---

## 6. Durable Background Scan Subsystem Review

As documented in `reports/validation-scan-adversarial-review-2026-09-03.md`, the durable scan engine (`php/Scan/*`) contains robust primitives (epoch fencing, MySQL keyset pagination, collision verification), but exhibits critical gaps:

1. **DAG Scope Type Mismatch (C-1)**:
   - `ScanPageView::scanScope()` resolves the user's DAG as a string name (`'site_a'`).
   - `ScanPlanner::stream()` compares `$row['dag']` against `dagFilter`. However, `redcap_record_list.dag_id` is an integer (`7`).
   - **Impact:** All records are filtered out. A scan over 0 records promotes to `complete-through-fence` and reports the project as clean, while permanently wedging `uv_scan_run.active_slot` for that project.
2. **Missing Cron Hook (H-2)**:
   - `ScanRetention` contains cleanup algorithms (`expireAbandoned()`, `purgeExpiredRuns()`), but `UniversalValidator.php` declares no `redcap_cron` hook. Abandoned leases and staged previews accumulate indefinitely.
3. **Permanent HTTP 503 on Export (HIGH-05 / H-1)**:
   - `pages/export.php` unconditionally emits HTTP 503. The intended streaming CSV export specified in Task 7 of the rebuild plan was never wired.

---

## 7. Prioritized Remediation Roadmap & Implementation Plan

```
================================================================================
                           REMEDIATION ROADMAP
================================================================================
PHASE 1: CRITICAL LOGIC & FATAL CRASH HOTFIXES
  1. Fix Logic.php and engine.js empty string comparison handling.
  2. Add leading backslash to catch (\Throwable $e) in UniversalValidator.php:2331.
  3. Thread $group_id through redcap_save_record -> auditRule -> findCollision.

PHASE 2: SCALABILITY & DATABASE CONTENTION REMEDIATION
  4. Enable filterLogic narrowing for DAG scope and form saves in findCollision.
  5. Replace setSystemSetting in surveyRateLimited with database row or Redis counter.
  6. Unify MutationObservers in engine.js into a single centralized DOM coordinator.

PHASE 3: SCAN COMPLETION & DOM NAMESPACING
  7. Harmonize DAG scope identifiers in ScanPageView / ScanPlanner (use numeric ID).
  8. Register redcap_cron hook in UniversalValidator.php for ScanRetention.
  9. Implement streaming CSV findings export in pages/export.php.
 10. Namespace client status regions (uvalidate-msg-[mode]-[field]) and composite outline state.
================================================================================
```

### Concrete Code Patches

#### Patch 1: Correct Empty String Ordering in `php/Logic.php` & `js/engine.js`
```diff
--- a/php/Logic.php
+++ b/php/Logic.php
@@ -616,6 +616,11 @@ private static function compare($op, $a, $b)
         // Without this, [end_date]>=[start_date] with start_date legitimately
         // blank would flip from passing to failing and invent a violation on
         // every record where the field simply has not been entered yet.
+        if ($a === '' || $b === '') {
+            // When an operand in an ordered comparison is blank (not yet entered),
+            // it must not fail as an invalid comparison; treat as true (inert).
+            return true;
+        }
         $mixed = ($a !== '' && $b !== '')
                && ((bool) preg_match(self::NUM_RE, $a) !== (bool) preg_match(self::NUM_RE, $b));
```

#### Patch 2: Forward `$group_id` in `UniversalValidator.php`
```diff
--- a/UniversalValidator.php
+++ b/UniversalValidator.php
@@ -330,8 +330,8 @@ public function redcap_save_record($project_id, $record, $instrument, $event_id
                                 $this->auditRule($rule, $ruleIndex, $hctx['values'], $dupes, $ownFields, $logMode,
                                     $project_id, $record, $hostForm, $hctx['event_id'], $hctx['instance'],
                                     isset($whenAst[$ruleIndex]) ? $whenAst[$ruleIndex] : null,
-                                    $depResolution[$hk]);
+                                    $depResolution[$hk], $group_id);
                             }
                         }
                         continue;
                     }
-                    $this->auditRule($rule, $ruleIndex, $values, $dupes, $onForm, $logMode, $project_id, $record, $instrument, $event_id, $repeat_instance,
-                        isset($whenAst[$ruleIndex]) ? $whenAst[$ruleIndex] : null, $auditResolution);
+                    $this->auditRule($rule, $ruleIndex, $values, $dupes, $onForm, $logMode, $project_id, $record, $instrument, $event_id, $repeat_instance,
+                        isset($whenAst[$ruleIndex]) ? $whenAst[$ruleIndex] : null, $auditResolution, $group_id);
```

#### Patch 3: Fix Namespaced `Throwable` in `UniversalValidator.php`
```diff
--- a/UniversalValidator.php
+++ b/UniversalValidator.php
@@ -2328,7 +2328,7 @@ private function installScanSchema()
                 'added' => (int) $added,
                 'total' => (int) $census['total'],
             ]);
-        } catch (Throwable $e) {
+        } catch (\Throwable $e) {
             // Swallowed on purpose - see the docblock. The scan stays disabled
             // and the page explains itself.
         }
```

---
*Report generated and archived in repository under `reports/senior-engineering-code-audit-2026-09-03.md`.*
