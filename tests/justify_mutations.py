#!/usr/bin/env python3
"""Mutations of @UVJUSTIFY, applied only to a disposable copy.

Each mutation breaks one guarantee the suites claim to hold. The named suite must then fail;
a mutation it survives is a hole in the tests. The anchors are exact text and must occur once.

Usage: PHP_BIN=php python tests/justify_mutations.py

The mutations of the SQL (suite tests/mysql/run.php) run only when UV_DB_HOST
and the other UV_DB_* variables name a MySQL or MariaDB server; without one they
are reported as skipped, never as killed.
"""
import os
from pathlib import Path
import shutil
import subprocess
import sys
import tempfile

ROOT = Path(__file__).resolve().parent.parent
PHP = os.environ.get('PHP_BIN', 'php')
NODE = os.environ.get('NODE_BIN', 'node')
UV = 'UniversalValidator.php'
SCANRUNS = 'php/ScanRuns.php'
# the legacy one-request scan, a test facade since QUA-3
FACADE = 'tests/support/scan_facade.php'
LOGIC = 'php/Logic.php'
RULES = 'php/AnnotationRules.php'
MODEREG = 'php/ModeRegistry.php'
MODES = 'php/modes.json'
ENGINE = 'js/engine.js'
SCANJS = 'js/scan.js'
SCHEMA = 'php/Scan/Schema.php'
ARRAYSTORE = 'tests/support/ArrayScanStore.php'
SQLSTORE = 'php/Scan/SqlScanStore.php'
MAPPER = 'php/Scan/FindingRowMapper.php'
ROLLUP = 'php/Scan/RollupBuilder.php'
PROMOTION = 'php/Scan/ScanPromotion.php'
SERVICE = 'php/Scan/ScanService.php'
COLUMNS = 'php/ScanColumns.php'
DIMENSIONS = 'php/ScanDimensions.php'
PAGEVIEW = 'php/ScanPageView.php'
VERDICT = 'tests/justify_php.php'
TWIN = 'tests/justify_js.cjs'
DOM = 'tests/justify_dom_js.cjs'
MODULE = 'tests/justify_module_php.php'
LIVE_JS = 'tests/justify_live_twin_js.cjs'
REGISTRY = 'tests/registry_php.php'
MISSING = 'tests/missing_codes_php.php'
MLM = 'tests/mlm_messages_dom_js.cjs'
SCHEMA_T = 'tests/scan_schema_php.php'
STORE_T = 'tests/scan_store_php.php'
SERVICE_T = 'tests/scan_service_php.php'
WORKER_T = 'tests/scan_worker_php.php'
REPORT_T = 'tests/scan_report_php.php'
PAGE_T = 'tests/scan_page_php.php'
SCANCLIENT_T = 'tests/scan_client_js.cjs'
# The SQL itself: run only when UV_DB_HOST names a server (tests/mysql/run.php, cases "justified" and "perf").
MYSQL = 'tests/mysql/run.php'

# QRID_reasonGiven's trim, told apart from QRID_isMissingCode's, which reads the same
REASON_SET = ('    var set = (codes && typeof codes === "object") ? codes : QRID_MISSING_CODES;\n'
              '    var s = String(value), i = 0, j = s.length;\n')
# applyJustify's split, told apart from markReturnClashes', which reads the same
JUSTIFY_SPLIT = ("' . $why]);\n            }\n            if (count($keep) === count($fields)) continue;\n"
                 "            if ($keep) {\n")

# (name, file, original, replacement, suite)
MUTATIONS = [
    # ---- whether a reason was given (Logic::reasonGiven and its browser twin) ----
    ('the codes read without trimming the reason', LOGIC,
     '        return !isset($codes[trim((string) $value)]);',
     '        return !isset($codes[(string) $value]);', VERDICT),
    ('a Missing Data Code taken for a reason', LOGIC,
     '        return !isset($codes[trim((string) $value)]);',
     '        return true;', VERDICT),
    ('a blank reason given', LOGIC,
     '        if (self::isBlank($value)) return false;\n        return !isset($codes',
     '        return !isset($codes', VERDICT),
    ('a reason of invisible characters only given, as trim() reads it (J2-2)', LOGIC,
     '        if (self::isBlank($value)) return false;\n        return !isset($codes',
     "        if (trim((string) $value) === '') return false;\n        return !isset($codes", VERDICT),
    ('a checkbox map, null or a boolean taken for a reason', LOGIC,
     '        if (!is_string($value) && !is_int($value) && !is_float($value)) return false;\n        if (self::isBlank($value)) return false;',
     "        if (self::isBlank(is_array($value) ? 'x' : $value)) return false;", VERDICT),
    ('a Missing Data Code taken for a reason by the audit', LOGIC,
     '        return !isset($codes[trim((string) $value)]);',
     '        return true;', MISSING),
    ('a reason of invisible characters only given by the audit (J2-2)', LOGIC,
     '        if (self::isBlank($value)) return false;\n        return !isset($codes',
     "        if (trim((string) $value) === '') return false;\n        return !isset($codes", MODULE),
    ('the browser trims no NUL or vertical tab before it reads the codes', ENGINE,
     REASON_SET + '    function ws(c){ return c === 32 || c === 9 || c === 13 || c === 10 || c === 0 || c === 11; }',
     REASON_SET + '    function ws(c){ return c === 32 || c === 9 || c === 13 || c === 10; }', TWIN),
    ('the browser trims a no-break space PHP keeps before it reads the codes', ENGINE,
     REASON_SET + '    function ws(c){ return c === 32 || c === 9 || c === 13 || c === 10 || c === 0 || c === 11; }',
     REASON_SET + '    function ws(c){ return c === 32 || c === 9 || c === 13 || c === 10 || c === 0 || c === 11 || c === 160; }', TWIN),
    ('the browser takes a code for a reason', ENGINE,
     '    return !(Object.prototype.hasOwnProperty.call(set, t) && set[t] === true);',
     '    return true;', TWIN),
    ('the browser takes a number for no reason', ENGINE,
     '    if(typeof value !== "string" && typeof value !== "number") return false;\n    if(QRID_isBlank(value)) return false;',
     '    if(typeof value !== "string") return false;\n    if(QRID_isBlank(value)) return false;', TWIN),
    ('the browser takes a blank value for a reason', ENGINE,
     '    if(QRID_isBlank(value)) return false;\n    var set = (codes',
     '    var set = (codes', TWIN),
    ('the browser takes invisible characters for a reason, as trim() reads them (J2-2)', ENGINE,
     '    if(QRID_isBlank(value)) return false;\n    var set = (codes',
     '    if(!/[^ \\t\\r\\n\\0\\x0B]/.test(String(value))) return false;\n    var set = (codes', TWIN),
    ('the page takes invisible characters for a reason (J2-2)', ENGINE,
     '    if(QRID_isBlank(value)) return false;\n    var set = (codes',
     '    if(!/[^ \\t\\r\\n\\0\\x0B]/.test(String(value))) return false;\n    var set = (codes', DOM),
    ("the page's Missing Data Codes not read", ENGINE,
     '    var set = (codes && typeof codes === "object") ? codes : QRID_MISSING_CODES;',
     '    var set = (codes && typeof codes === "object") ? codes : {};', DOM),

    # ---- the grammar ----
    ('"when" taken by @UVJUSTIFY', RULES,
     "foreach (['when' => '\"when\"', 'caseSensitive' => '\"caseSensitive\"', 'blockSave' => '\"blockSave\"', 'references' => '\"references\"'] as $k => $label) {",
     "foreach (['caseSensitive' => '\"caseSensitive\"', 'blockSave' => '\"blockSave\"', 'references' => '\"references\"'] as $k => $label) {", VERDICT),
    ('a reason that names an event refused for its shape only', RULES,
     "            } elseif (preg_match('/^\\[[^\\[\\]]+\\](\\[[^\\[\\]]+\\])+$/D', $ref)) {",
     '            } elseif (false) {', VERDICT),
    ('a reason without brackets accepted', RULES,
     "            if (preg_match('/^\\[([a-z][a-z0-9_]*)\\]$/D', $ref, $m)) {\n                $out['justifyReason'] = $m[1];",
     "            if (preg_match('/^\\[?([a-z][a-z0-9_]*)\\]?$/D', $ref, $m)) {\n                $out['justifyReason'] = $m[1];", VERDICT),
    ('checkJustify accepts a fragment with no reason', RULES,
     "            $errors[] = self::TAG_JUSTIFY . ' needs \"reason\", the field that holds the reason — e.g. \"reason\":\"[weight_reason]\".';\n",
     '', VERDICT),
    ('checkJustify accepts "when", "caseSensitive" and "blockSave"', RULES,
     '            if (array_key_exists($k, $frag)) $errors[] = self::justifyTakesNo($label);',
     '            if (false) $errors[] = self::justifyTakesNo($label);', VERDICT),

    # ---- the registry ----
    ('a mode with no "justify" flag accepted', MODEREG,
     "            if (!array_key_exists('justify', $m) || !is_bool($m['justify'])) {",
     '            if (false) {', REGISTRY),
    ('the reason field left out of the fields a rule reads', MODEREG,
     '            foreach (self::justifyFields($r) as $w) $out[$w] = true;',
     '', VERDICT),
    ('the reason field not read by default', MODEREG,
     "array $kinds = ['cond', 'operand', 'fieldList', 'reason']",
     "array $kinds = ['cond', 'operand', 'fieldList']", MODULE),
    ('a reason stands for a duplicate', MODES,
     '      "evaluator": "findingsUnique",\n      "missingCodes": "skip",\n      "justify": false,',
     '      "evaluator": "findingsUnique",\n      "missingCodes": "skip",\n      "justify": true,', MODULE),
    ('a reason does not stand for @UVDELTA', MODES,
     '      "evaluator": "findingsDelta",\n      "missingCodes": "skip",\n      "justify": true,',
     '      "evaluator": "findingsDelta",\n      "missingCodes": "skip",\n      "justify": false,', VERDICT),

    # ---- pairing a reason with the rules it stands for (applyJustify) ----
    ('applyJustify never run', UV,
     '        $resolved = self::applyJustify(self::markReturnClashes(Branching::resolve($out)));',
     '        $resolved = self::markReturnClashes(Branching::resolve($out));', MODULE),
    ('a refused rule paired with a reason', UV,
     "            if ($by && empty($r['configError'])) $rules[$i]['justifyBy'] = $by;",
     "            if ($by) $rules[$i]['justifyBy'] = $by;", MODULE),
    ('a refused rule not counted as one a reason stands for', UV,
     "            if (!is_array($r) || !ModeRegistry::justifies(ModeRegistry::modeOfType(isset($r['type']) ? $r['type'] : ''))) continue;\n            $by = [];",
     "            if (!is_array($r) || !empty($r['configError']) || !ModeRegistry::justifies(ModeRegistry::modeOfType(isset($r['type']) ? $r['type'] : ''))) continue;\n            $by = [];", MODULE),
    ('a field no rule a reason stands for checks kept', UV,
     '                if (isset($covered[(string) $f])) { $keep[] = $f; continue; }',
     '                { $keep[] = $f; continue; }', MODULE),
    ('a split rule keeps every field', UV,
     JUSTIFY_SPLIT + "                $rules[$i]['fields'] = $keep;\n",
     JUSTIFY_SPLIT, MODULE),
    ('the reason cannot be the field itself: not checked', UV,
     "        if (isset($frag['justifyReason']) && $frag['justifyReason'] === $name) {",
     '        if (false) {', MODULE),
    ('a reason field that is not a field of the project accepted', UV,
     "        if (!isset($dd[$reason])) return $err(",
     '        if (false) return $err(', MODULE),
    ('a reason field on another form accepted', UV,
     '        if ($form !== $there) {',
     '        if (false) {', MODULE),
    ('a reason field of another type accepted', UV,
     '        if (!in_array($type, self::JUSTIFY_REASON_TYPES, true)) {',
     '        if (false) {', MODULE),
    ('the record ID field accepted as a reason field', UV,
     '        if ($reason === $this->recordIdFieldOf($pid)) {',
     '        if (false) {', MODULE),
    ('a reason field REDCap fills in accepted', UV,
     "        if (preg_match('/@(' . $filled . ')(?![\\w-])/i', $ann, $m)) {",
     '        if (false) {', MODULE),
    ('a tag that fills a field in left off the list', UV,
     "        'NOW-SERVER', 'NOW-UTC', 'NOW', ",
     "        'NOW-UTC', 'NOW', ", MODULE),
    ('@LANGUAGE-CURRENT-FORM and -SURVEY left off the list (J2-4)', UV,
     "        'LANGUAGE-CURRENT-FORM', 'LANGUAGE-CURRENT-SURVEY'];",
     '        ];', MODULE),
    ('a read-only reason field accepted', UV,
     "        if (preg_match('/@(READONLY(?:-[A-Z]+)?)(?![\\w-])/i', $ann, $m)) {",
     '        if (false) {', MODULE),
    ('a reason field with bare @HIDDEN accepted (J2-4)', UV,
     "        if (preg_match('/@HIDDEN(?![\\w-])/i', $ann)) {",
     '        if (false) {', MODULE),
    ('a reason field with @HIDDEN-SURVEY, -FORM, -APP or -PDF refused (J2-4)', UV,
     "        if (preg_match('/@HIDDEN(?![\\w-])/i', $ann)) {",
     "        if (preg_match('/@HIDDEN(?!\\w)/i', $ann)) {", MODULE),
    ('a reason field that carries @UVJUSTIFY itself accepted', UV,
     "        if (preg_match('/' . preg_quote(AnnotationRules::TAG_JUSTIFY, '/') . '(?![\\w-])/i', $ann)) {",
     '        if (false) {', MODULE),
    ('a write-once reason field accepted (J2-4)', UV,
     "        if (preg_match('/' . preg_quote(AnnotationRules::TAG_WRITEONCE, '/') . '(?![\\w-])/i', $ann)) {",
     '        if (false) {', MODULE),
    # each refusal in any letter case, as REDCap reads its own tags (J2-7)
    ('the filled-tag refusal reads upper case only', UV,
     "        if (preg_match('/@(' . $filled . ')(?![\\w-])/i', $ann, $m)) {",
     "        if (preg_match('/@(' . $filled . ')(?![\\w-])/', $ann, $m)) {", MODULE),
    ('the read-only refusal reads upper case only', UV,
     "        if (preg_match('/@(READONLY(?:-[A-Z]+)?)(?![\\w-])/i', $ann, $m)) {",
     "        if (preg_match('/@(READONLY(?:-[A-Z]+)?)(?![\\w-])/', $ann, $m)) {", MODULE),
    ('the @HIDDEN refusal reads upper case only', UV,
     "        if (preg_match('/@HIDDEN(?![\\w-])/i', $ann)) {",
     "        if (preg_match('/@HIDDEN(?![\\w-])/', $ann)) {", MODULE),
    ('the @UVJUSTIFY refusal reads upper case only', UV,
     "        if (preg_match('/' . preg_quote(AnnotationRules::TAG_JUSTIFY, '/') . '(?![\\w-])/i', $ann)) {",
     "        if (preg_match('/' . preg_quote(AnnotationRules::TAG_JUSTIFY, '/') . '(?![\\w-])/', $ann)) {", MODULE),
    ('the @UVWRITEONCE refusal reads upper case only', UV,
     "        if (preg_match('/' . preg_quote(AnnotationRules::TAG_WRITEONCE, '/') . '(?![\\w-])/i', $ann)) {",
     "        if (preg_match('/' . preg_quote(AnnotationRules::TAG_WRITEONCE, '/') . '(?![\\w-])/', $ann)) {", MODULE),
    # a tag name inside another tag's value is text (J2-5)
    ("the reason-field refusals read a tag name inside another tag's value", UV,
     "        $ann = AnnotationRules::maskValues(isset($dd[$reason]['field_annotation']) ? (string) $dd[$reason]['field_annotation'] : '');",
     "        $ann = isset($dd[$reason]['field_annotation']) ? (string) $dd[$reason]['field_annotation'] : '';", MODULE),
    ('maskValues blanks the tag names with their values', RULES,
     "            $out .= substr($ann, $at, $sp[2] - $at) . str_repeat(' ', $sp[1] - $sp[2]);",
     "            $out .= substr($ann, $at, $sp[0] - $at) . str_repeat(' ', $sp[1] - $sp[0]);", MODULE),
    ('maskValues drops the text after the last value', RULES,
     '        return $out . substr($ann, $at);\n',
     '        return $out;\n', MODULE),
    # a reason field another rule returns a value to (J2-3)
    ('a reason field another rule returns a value to accepted', UV,
     '            if (!isset($writer[$reason])) continue;',
     '            continue;', MODULE),
    ('a returning rule refused itself still refuses the reason field', UV,
     "        foreach ($rules as $r) {\n            if (!is_array($r) || !empty($r['configError'])) continue;\n            foreach (ModeRegistry::returnFields($r) as $f) {\n                if (!isset($writer[$f]))",
     "        foreach ($rules as $r) {\n            if (!is_array($r)) continue;\n            foreach (ModeRegistry::returnFields($r) as $f) {\n                if (!isset($writer[$f]))", MODULE),
    ('a justify rule on several fields refused on its first field only', UV,
     '            foreach ($mine as $x) $refused[] = $x;\n',
     '', MODULE),
    # VAL-15 logs the refused rules of the saved form only
    ('a refused @UVJUSTIFY on another form logged on this form\'s save', UV,
     '                if (is_string($f) && isset($onForm[$f])) $own[] = $f;',
     '                if (is_string($f)) $own[] = $f;', MODULE),
    ('a reason field makes a locked field hold the save', UV,
     "            foreach (array_map('strval', ModeRegistry::refFields($r, ['cond', 'operand', 'fieldList'])) as $x) {",
     "            foreach (array_map('strval', ModeRegistry::refFields($r)) as $x) {", MODULE),

    # ---- the audit (markJustified) ----
    ('a finding marked justified whatever the reason field holds', UV,
     '            if (!Logic::reasonGiven($values[$reason], $codes)) continue;\n',
     '', MODULE),
    # markJustified asks nothing of the reason field's resolution: no input could reach
    # such a test (see its docblock), so it was removed in the second review (J2-7).
    ('Missing Data Codes that could not be read: a reason still stands', UV,
     "                if (isset($unread['the missing data codes']) || isset($unread['the project\\'s design'])) return $out;\n",
     '', MODULE),
    ('no finding ever marked justified', UV,
     "            $out['invalid'][$k]['justified'] = true;\n",
     '', MODULE),
    ('a branched rule never marked justified', UV,
     '            return $this->markJustified($out, $rule, $values, $project_id);',
     '            return $out;', MODULE),
    ('a justified finding logged as an open one', UV,
     "$extra, $justified ? 'uvalidate-justified' : 'invalid-id-saved');",
     "$extra, 'invalid-id-saved');", MODULE),
    ('the audit entry does not name the reason field', UV,
     "            if ($justified) $extra['justified_by'] = (string) $v['justifiedBy'];\n",
     '', MODULE),

    # ---- the scans ----
    ('the legacy count of findings saved with a reason never reset between scans (J2-7)', FACADE,
     '        $this->scanJustified = 0;\n',
     '', MODULE),
    ('the legacy scan counts no finding saved with a reason', SCANRUNS,
     "                        if (!empty($v['justified']) && isset($v['justifiedBy'])) $this->scanJustified++;\n",
     '', MODULE),
    ('the legacy scan counts justified findings as violations', FACADE,
     "        $result['stats']['violations'] = max(0, $sink->count() - $this->scanJustified);",
     "        $result['stats']['violations'] = $sink->count();", MODULE),
    ('the durable row never carries the flag', SCANRUNS,
     "                'justified' => empty($v['justified']) ? 0 : 1,",
     "                'justified' => 0,", MODULE),
    ('the array store drops the flag', ARRAYSTORE,
     "            'justified'         => empty($f['justified']) ? 0 : 1,",
     "            'justified'         => 0,", MYSQL),
    ('the SQL store drops the flag', SQLSTORE,
     "            'justified'         => empty($f['justified']) ? 0 : 1,",
     "            'justified'         => 0,", MYSQL),
    ('a stored row read back as open', MAPPER,
     "            'justified'     => (string) $stored['justified'] === '1',",
     "            'justified'     => false,", REPORT_T),
    ('the rollup files every finding as open', ROLLUP,
     "            self::K_STANDING => (string) $r[5] === '1' ? self::STANDING_JUSTIFIED : self::STANDING_OPEN,",
     '            self::K_STANDING => self::STANDING_OPEN,', MYSQL),
    ('the rollup page left to the optimizer', ROLLUP,
     " FORCE INDEX (ix_page_v2)",
     '', MYSQL),
    ("a finished run's summary read twice on each poll", SERVICE,
     "'findings'  => ScanPromotion::findingsOf($store, $run, $aggregates),",
     "'findings'  => ScanPromotion::findingsOf($store, $run),", MYSQL),
    ('justified findings counted as violations in the tally', PROMOTION,
     "        return ['violations' => $active - $justified, 'justified' => $justified,",
     "        return ['violations' => $active, 'justified' => $justified,", WORKER_T),
    ('the "Saved with a reason" column always blank', COLUMNS,
     "                'render' => function (array $f) { return empty($f['justified']) ? '' : 'Yes'; },",
     "                'render' => function (array $f) { return ''; },", MODULE),
    ('the "Saved with a reason" column never shown', DIMENSIONS,
     "            if ($d->rules[$i + 1]['justifyBy']) $d->justifies = true;\n",
     '', MODULE),
    ('the scan page does not say how many were saved with a reason', PAGEVIEW,
     "        if (is_int($j) && $j > 0) $text .= ', and ' . $j . ' saved with a reason';",
     '', PAGE_T),
    ('the scan client does not say how many were saved with a reason', SCANJS,
     "        if (typeof j === 'number' && j > 0 && j === Math.floor(j)) text += ', and ' + j + ' saved with a reason';",
     '', SCANCLIENT_T),
    ('schema 7 stores old findings as justified', SCHEMA,
     "' ADD COLUMN justified TINYINT UNSIGNED NOT NULL DEFAULT 0',",
     "' ADD COLUMN justified TINYINT UNSIGNED NOT NULL DEFAULT 1',", SCHEMA_T),
    ('schema 7 recorded without the column', SCHEMA,
     "            if ($v === 7) {\n                foreach ([['finding', 'the finding table', 'justified'],",
     "            if (false) {\n                foreach ([['finding', 'the finding table', 'justified'],", SCHEMA_T),
    ('schema 7 adds no run counter of the rows saved with a reason', SCHEMA,
     ",\n                ['sql' => 'ALTER TABLE ' . $r . ' ADD COLUMN detail_justified BIGINT UNSIGNED NOT NULL DEFAULT 0',\n"
     "                 'skipIf' => ['column', $r, 'detail_justified']]];",
     '];', SCHEMA_T),
    ('a run read back without its counter of the rows saved with a reason', 'php/Scan/ScanStore.php',
     "        // so a run still working counts them apart from its open findings.\n        'detail_justified',\n",
     '', MYSQL),
    ('the array store does not count the rows saved with a reason', ARRAYSTORE,
     "        $this->runs[$runId]['detail_justified'] += count(array_filter($keep, function ($f) { return !empty($f['justified']); }));\n",
     '', STORE_T),
    ('the SQL store does not count the rows saved with a reason', SQLSTORE,
     "                     $justified, self::now()];",
     "                     0, self::now()];", MYSQL),
    ('a working run counts its rows saved with a reason as findings', PROMOTION,
     "        $rows = max(0, $rows - (isset($run['detail_justified']) ? max(0, (int) $run['detail_justified']) : 0));\n",
     '', SERVICE_T),
    ('a working run says nothing of its rows saved with a reason', PROMOTION,
     "        if ($terminal === null) return $rows;\n        $summary = self::tally($aggregates !== null ? $aggregates : $store->aggregates((int) $run['run_id']))['justified'];",
     "        if ($terminal === null) return 0;\n        $summary = self::tally($aggregates !== null ? $aggregates : $store->aggregates((int) $run['run_id']))['justified'];", SERVICE_T),
    ('a run that ended without a verdict drops the rows its counter holds', PROMOTION,
     '        if (in_array($terminal, ScanOutcome::NO_VERDICT, true)) return max($rows, $summary);',
     '        if (in_array($terminal, ScanOutcome::NO_VERDICT, true)) return $summary;', SERVICE_T),
    ('the largest axis taken without the standing one', PROMOTION,
     '        foreach (RollupBuilder::KINDS as $kind) $active = max($active, $of($kind));',
     '        foreach (array_diff(RollupBuilder::KINDS, [RollupBuilder::K_STANDING]) as $kind) $active = max($active, $of($kind));', WORKER_T),
    ('the audit reads no reason field it was not already reading', 'php/TemporalRules.php',
     '        $out=array_merge($out,ModeRegistry::justifyFields($rule));\n',
     '', MODULE),

    # ---- the page ----
    ('a partial survey save lets through every field paired with a reason, reason or not (J2-7)', ENGINE,
     '      if(partial && QRID_PARTIAL_SAVE.types.indexOf(el.__qridType) >= 0) return false;\n      return QRID_itemHolds(el) && !QRID_justified(el);',
     '      if(partial && (QRID_PARTIAL_SAVE.types.indexOf(el.__qridType) >= 0 || QRID_justifiable(el))) return false;\n'
     '      return QRID_itemHolds(el) && !QRID_justified(el);', DOM),
    ('a partial survey save holds the modes that wait for a complete response', ENGINE,
     '      if(partial && QRID_PARTIAL_SAVE.types.indexOf(el.__qridType) >= 0) return false;\n      return QRID_itemHolds(el) && !QRID_justified(el);',
     '      return QRID_itemHolds(el) && !QRID_justified(el);', DOM),
    ('a given reason releases nothing', ENGINE,
     '      return QRID_itemHolds(el) && !QRID_justified(el);',
     '      return QRID_itemHolds(el);', DOM),
    ('a rule that only warns holds the save', ENGINE,
     '  if(!el.__qridInvalid || el.__qridBlockMode === "off") return false;',
     '  if(!el.__qridInvalid) return false;', DOM),
    ('a field the person cannot change or see holds the save and gets the note', ENGINE,
     '  return !(anchor && (QRID_fieldLocked(anchor, el.__qridFieldName) || QRID_fieldHidden(anchor)));',
     '  return true;', DOM),
    ('a reason releases a mode it does not stand for', ENGINE,
     '  return !!UV_JUSTIFY[el.__qridFieldName] && QRID_JUSTIFY_OF_MODE[el.__qridMode] === true;',
     '  return !!UV_JUSTIFY[el.__qridFieldName];', DOM),
    ('the save message does not name the reason field', ENGINE,
     '      return QRID_justifiable(el)\n',
     '      return false\n', DOM),
    ('a hidden reason row gives a reason', ENGINE,
     '    if(anc && QRID_fieldHidden(anc.el)) return false;\n',
     '', DOM),
    ("an embedded reason judged by its own row, which REDCap hides", ENGINE,
     '    var anc = QRID_fieldAnchor(reason);\n    if(anc && QRID_fieldHidden(anc.el)) return false;',
     '    var row = document.getElementById(reason + "-tr");\n    if(row && row.style && row.style.display === "none") return false;', DOM),
    ("an embedded reason judged by its own row, on the live sheet's page (C8)", ENGINE,
     '    var anc = QRID_fieldAnchor(reason);\n    if(anc && QRID_fieldHidden(anc.el)) return false;',
     '    var row = document.getElementById(reason + "-tr");\n    if(row && row.style && row.style.display === "none") return false;', LIVE_JS),
    ('a checkbox field found by none of its options', ENGINE,
     '  if(el) return { el: el, checkbox: false };\n  if(!document.getElementsByName) return null;',
     '  if(el) return { el: el, checkbox: false };\n  return null;', DOM),
    ("a checkbox judged by the row of the option it is anchored on", ENGINE,
     '  if(el && anchor.__qridAnchorOf && !((el.tagName || "").toLowerCase() === "tr" && /-tr$/.test(el.id || ""))) el = el.parentNode;\n',
     '', DOM),
    ("a checkbox's change of verdict not passed to its note", ENGINE,
     '  var jf = input.__qridFieldName || input.__qridAnchorOf || input.name;',
     '  var jf = input.__qridFieldName || input.name;', DOM),
    ("a checkbox's note left in the row of its first option", ENGINE,
     '    optionRow.removeChild(msg);\n    optionBox.appendChild(msg);\n',
     '', DOM),
    ('the note not moved after an autocomplete dropdown', ENGINE,
     '      QRID_followWidget(anchor, msg, fieldName);\n      var holds = false;',
     '      var holds = false;', DOM),
    ('a label holding "$&" or "$$" read as a replacement pattern', ENGINE,
     'NOTE.replace("{reason}", function(){ return label(); })',
     'NOTE.replace("{reason}", label())', DOM),
    ('the note not refreshed when the verdict changes', ENGINE,
     '  if(typeof jf === "string" && UV_JUSTIFY[jf]) UV_JUSTIFY[jf].later();',
     '', DOM),
    ('a reason changed with no event not followed', ENGINE,
     '    if(watch) watch.onChange(later);',
     '', DOM),
    ('the note stays once a reason is given', ENGINE,
     '      if(!holds || given()){ msg.style.display = "none"; msg.innerHTML = ""; return; }',
     '      if(!holds){ msg.style.display = "none"; msg.innerHTML = ""; return; }', DOM),
    ('the note asks for a reason the save guard would not ask for', ENGINE,
     '           && QRID_guardSpeaks(el) && QRID_itemHolds(el)){ holds = true; break; }',
     '           && QRID_guardSpeaks(el) && el.__qridInvalid){ holds = true; break; }', DOM),
    ('the note not redrawn when branching shows or hides a row', ENGINE,
     '    QRID_onBranching(later);\n    later();\n    return true;',
     '    later();\n    return true;', DOM),
    ('the note asks for a reason a duplicate cannot take', ENGINE,
     '        if(el.__qridFieldName === fieldName && QRID_JUSTIFY_OF_MODE[el.__qridMode] === true\n',
     '        if(el.__qridFieldName === fieldName\n', DOM),
    ("the note ignores the rule's own words and is not escaped", ENGINE,
     '      msg.innerHTML = QRID_ruleHtml(V, "message") || QRID_escapeHtml(NOTE.replace("{reason}", function(){ return label(); }));',
     '      msg.innerHTML = NOTE.replace("{reason}", label());', DOM),
    ('the modes a reason stands for never read', ENGINE,
     '  for(var jm in JUSTIFY_OF_MODE) if(own(JUSTIFY_OF_MODE, jm) && JUSTIFY_OF_MODE[jm] === true) QRID_JUSTIFY_OF_MODE[jm] = true;',
     '', DOM),
    ('a field paired with a reason field not on the page', ENGINE,
     '    if(!found || !source) return false;',
     '    if(!found) return false;\n    source = source || { el: {} };', DOM),
    ('a tag with no reason field releases the save', ENGINE,
     '  if(!configError && !/^[a-z][a-z0-9_]*$/.test(reason)) configError = "@UVJUSTIFY needs the field that holds the reason.";',
     '', DOM),
    ("the note's languages not read", ENGINE,
     '            i18n: (QRID_CONFIG.i18n && typeof QRID_CONFIG.i18n === "object") ? QRID_CONFIG.i18n : null };\n  var NOTE = "Fix this value',
     '            i18n: null };\n  var NOTE = "Fix this value', MLM),
]


def run(suite, cwd):
    """The suite's result, or None for the database suite when no server is named."""
    env = None
    if suite == MYSQL:
        if not os.environ.get('UV_DB_HOST'):
            return None
        cmd = [PHP, '-d', 'extension=mysqli', suite]
        env = dict(os.environ, UV_CASES='justified,perf')
    else:
        cmd = [NODE, suite] if suite.endswith('.cjs') else [PHP, suite]
    return subprocess.run(cmd, cwd=cwd, capture_output=True, text=True, encoding='utf-8', errors='replace', env=env)


def main():
    survived = []
    skipped = []
    with tempfile.TemporaryDirectory(prefix='uv-justify-mutations-') as directory:
        copy = Path(directory)
        # site/ and pages/ too: registry_php reads site/, scan_page_php renders pages/scan.php
        for folder in ['php', 'js', 'tests', 'data', 'docs', 'site', 'pages']:
            shutil.copytree(ROOT / folder, copy / folder)
        for filename in [UV, 'config.json']:
            shutil.copy2(ROOT / filename, copy / filename)
        # in the copy, so a suite the copy alone breaks stops the run
        for suite in sorted({m[-1] for m in MUTATIONS}):
            res = run(suite, copy)
            if res is not None and res.returncode != 0:
                sys.exit(f'{suite} fails in the copy before any mutation:\n{res.stdout[-2000:]}{res.stderr[-2000:]}')
        for name, filename, original, replacement, suite in MUTATIONS:
            path = copy / filename
            source = path.read_text(encoding='utf-8')
            if source.count(original) != 1:
                sys.exit(f'{name}: the anchor must occur once in {filename}, found {source.count(original)}')
            path.write_text(source.replace(original, replacement), encoding='utf-8', newline='')
            if filename.endswith('.php'):
                subprocess.run([PHP, '-l', str(path)], check=True, stdout=subprocess.DEVNULL)
            result = run(suite, copy)
            path.write_text(source, encoding='utf-8', newline='')
            if result is None:
                skipped.append(name)
                print(f'skipped (no UV_DB_HOST): {name}')
            elif result.returncode == 0:
                survived.append(name)
                print(f'SURVIVED: {name} ({suite})')
            else:
                print(f'killed: {name}')
    if survived:
        sys.exit(f'justify_mutations: {len(survived)} of {len(MUTATIONS)} mutations survived')
    ran = len(MUTATIONS) - len(skipped)
    print(f'justify_mutations: {ran} mutations, every one killed' + (f'; {len(skipped)} skipped (no database)' if skipped else ''))


if __name__ == '__main__':
    main()
