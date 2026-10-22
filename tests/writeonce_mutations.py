#!/usr/bin/env python3
"""Mutations of @UVWRITEONCE, applied only to a disposable copy.

Each mutation breaks one guarantee the suites claim to hold. The named suite must then fail;
a mutation it survives is a hole in the tests. The anchors are exact text and must occur once.

Usage: PHP_BIN=php python tests/writeonce_mutations.py

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
LOGIC = 'php/Logic.php'
RULES = 'php/AnnotationRules.php'
HISTORY = 'php/ValueHistory.php'
MODEREG = 'php/ModeRegistry.php'
ENGINE = 'js/engine.js'
VERDICT = 'tests/writeonce_php.php'
MODULE = 'tests/writeonce_module_php.php'
DOM = 'tests/writeonce_dom_js.cjs'
ANNOTATION = 'tests/annotation_php.php'
MODES = 'php/modes.json'
LIVE_PHP = 'tests/writeonce_live_twin_php.php'
LIVE_JS = 'tests/writeonce_live_twin_js.cjs'
ROLEBOOK = 'php/RoleBook.php'
BRANCHING = 'php/Branching.php'
REGISTRY = 'tests/registry_php.php'
EXISTS_RETURN = 'tests/exists_return_php.php'
# The SQL itself: run only when UV_DB_HOST names a server (tests/mysql/run.php, case "history").
MYSQL = 'tests/mysql/run.php'

# (name, file, original, replacement, suite)
MUTATIONS = [
    # ---- the verdict ----
    ('a change by anyone moves the lock', LOGIC,
     '            if ($role) {\n                $lock = $empty($v) ? null : $v;',
     '            if (true) {\n                $lock = $empty($v) ? null : $v;', VERDICT),
    ('roles that cannot be read read as "no role"', LOGIC,
     "            if ($role === null) return ['verdict' => 'unknown', 'why' => 'roles'];\n", '', VERDICT),
    ('a blank saved by a named role keeps the lock', LOGIC,
     '                $lock = $empty($v) ? null : $v;', '                $lock = $v;', VERDICT),
    ('a blank first save locks', LOGIC,
     '                if (!$empty($v)) {\n                    $lock = $v;\n                    $lockedAt = $saves[$i][\'at\'];',
     '                if (true) {\n                    $lock = $v;\n                    $lockedAt = $saves[$i][\'at\'];', VERDICT),
    ('a value the log never shows saved passes', LOGIC,
     "        if (!$same($last, $current)) return ['verdict' => 'unknown', 'why' => 'gap'];\n", '', VERDICT),
    ('a save the log does not spell out read as a value', LOGIC,
     "            if ($v === null) return ['verdict' => 'unknown', 'why' => 'ambiguous'];",
     "            if (false) return ['verdict' => 'unknown', 'why' => 'ambiguous'];", VERDICT),
    ('"since" counts the saves after the date as before it', LOGIC,
     "strcmp((string) $saves[$i]['ts'], (string) $sinceTs) < 0", "strcmp((string) $saves[$i]['ts'], (string) $sinceTs) > 0", VERDICT),
    ('"since" says the lock started at the last save before it', LOGIC,
     '                    $lockedAt = $sinceLabel;', '                    $lockedAt = $lastAt;', VERDICT),
    ('a cleared value called changed', LOGIC,
     "return ['verdict' => $empty($current) ? 'cleared' : 'changed',", "return ['verdict' => 'changed',", VERDICT),
    ('a quote the log escaped read as a change', LOGIC,
     "return $l === $v || strtr($l, [\"\\\\'\" => \"'\", '\\\\\\\\' => '\\\\']) === $v;", 'return $l === $v;', VERDICT),
    ('the lock compared with the value now, not with the last save', LOGIC,
     "if ($lock === null || $norm($lock) === $norm($last)) return ['verdict' => 'ok'];",
     "if ($lock === null || $same($lock, $current)) return ['verdict' => 'ok'];", VERDICT),
    ('the roles asked for a save of the locked value', LOGIC,
     '            if ($norm($v) === $norm($lock)) continue;   // both as the log writes them\n', '', VERDICT),
    # ---- the log reader ----
    ('a whole-record DELETE does not end the history', HISTORY,
     "return !$this->multiArm && self::wholeRecordDelete($row) ? 'read' : 'delete';", "return 'delete';", VERDICT),
    ('any DELETE ends the history', HISTORY,
     "return !$this->multiArm && self::wholeRecordDelete($row) ? 'read' : 'delete';", "return 'read';", VERDICT),
    ('a whole-record DELETE ends the history where the record has several arms', HISTORY,
     "return !$this->multiArm && self::wholeRecordDelete($row) ? 'read' : 'delete';",
     "return self::wholeRecordDelete($row) ? 'read' : 'delete';", VERDICT),
    ('a deletion of part of the record read as the whole record', HISTORY,
     "return (bool) preg_match('/^delete record(?: \\(api\\))?$/iD', $d);", "return (bool) preg_match('/^delete/i', $d);", VERDICT),
    ('a row with no event that names a kept field passed over', HISTORY,
     "if (isset($this->fields[$f])) return 'noevent';", "if (false) return 'noevent';", VERDICT),
    ("another event's rows read when the server returns them", HISTORY,
     '        if ($event !== $eventId) return null;\n', '', VERDICT),
    ('the event filter left out of the SQL', HISTORY,
     "             . ' AND (event = \\'DELETE\\' OR event_id = ? OR event_id IS NULL)';", "             . '';", VERDICT),
    ('a history of exactly the cap called capped', HISTORY,
     "                if (is_array($next) && !$next) return 'read';\n", '', VERDICT),
    ('a whole-record DELETE just past the cap not taken as the start', HISTORY,
     "&& isset($next[0][6]) && strtoupper((string) $next[0][6]) === 'DELETE' && !$this->multiArm && self::wholeRecordDelete($next[0])) {",
     '&& false) {', VERDICT),
    ('a value over several lines cut at its first line', HISTORY,
     'if ($last >= 0 && !$blocks[$last][2] && !preg_match("/\',$/D", $blocks[$last][1])) {', 'if (false) {', VERDICT),
    ('a line of no known shape read past', HISTORY,
     '        if ($stray || $broken) {', '        if ($broken) {', VERDICT),
    ('a row out of the dictionary order read as values', HISTORY,
     '        if ($stray || $broken) {', '        if ($stray) {', VERDICT),
    ("a checkbox's own lines read as out of order", HISTORY,
     'if ($p < $place || ($p === $place && !($b[2] && $prev === $b[0]))) $broken = true;',
     'if ($p < $place || $p === $place) $broken = true;', VERDICT),
    ('a field written twice in one row read as a value', HISTORY,
     '$out[$instance][$b[0]] = (isset($out[$instance]) && array_key_exists($b[0], $out[$instance])) ? null : $v;',
     '$out[$instance][$b[0]] = $v;', VERDICT),
    ('another record of the same name in the collation read', HISTORY,
     'if (!isset($row[4]) || (string) $row[4] !== $record) return null;', 'if (!isset($row[4])) return null;', VERDICT),
    ('a log past the cap read in part', HISTORY,
     '            if ($rows >= self::MAX_ROWS_PER_RECORD) {', '            if (false) {', VERDICT),
    ('a record with no rows called read', HISTORY,
     "return $any ? 'read' : 'none';", "return 'read';", VERDICT),
    ('a failed read called "no rows"', HISTORY,
     "            } catch (\\Throwable $e) {\n                $this->saves[$key] = [];\n                return 'unknown';",
     "            } catch (\\Throwable $e) {\n                $this->saves[$key] = [];\n                return 'none';", VERDICT),
    ('a field not named when the reader was made read as no saves', HISTORY,
     "        if (!isset($this->fields[$field])) return ['state' => 'unknown', 'saves' => [], 'why' => 'unnamed'];\n", '', VERDICT),
    ('saves newest first', HISTORY,
     'for ($i = count($rows) - 1; $i >= 0; $i--) {', 'for ($i = 0; $i < count($rows); $i++) {', VERDICT),
    ('every record held in memory', HISTORY,
     '        if (count($this->kept) <= self::RECORDS_KEPT) return;', '        return;', VERDICT),
    ('save times left in the log zone', HISTORY,
     "return $d->setTimezone($this->clockZone)->format('Y-m-d H:i:s');", "return $d->format('Y-m-d H:i:s');", VERDICT),
    # ---- the page hook ----
    ('roles that cannot be read lock a rule that names roles', UV,
     'if ($holds === true) continue;', 'if ($holds !== false) continue;', MODULE),
    ('roles that cannot be read not said on the page', UV,
     "                if ($holds === null) $r['writeonceRolesUnread'] = true;\n", '', MODULE),
    ('the user name compared with letter case', UV,
     "        $u = strtolower(trim((string) $user));\n        if ($u === '' || !isset($book['users'][$u])) return false;",
     "        $u = (string) $user;\n        if ($u === '' || !isset($book['users'][$u])) return false;", MODULE),
    ('a survey respondent given the roles of the login', UV,
     "            if ($roles && $context !== 'survey') {", '            if ($roles) {', MODULE),
    ('a Missing Data Code locked on the page', UV,
     "|| trim((string) $v, \" \\t\\r\\n\") === '' || self::isMissingCode($v, $codes)) continue;",
     "|| trim((string) $v, \" \\t\\r\\n\") === '') continue;", MODULE),
    ('a blank value locked on the page', UV,
     "|| trim((string) $v, \" \\t\\r\\n\") === '' || self::isMissingCode($v, $codes)) continue;",
     '|| self::isMissingCode($v, $codes)) continue;', MODULE),
    ('the roles sent to the page', MODEREG,
     "$drop = $survey ? array_merge(self::serverKeys(), self::staffOnlyKeys(), self::SURVEY_DROP) : self::serverKeys();",
     "$drop = $survey ? array_merge(self::staffOnlyKeys(), self::SURVEY_DROP) : [];", MODULE),
    # ---- the audit ----
    ('a log past the cap judged as no saves', UV,
     "            if ($h['state'] === 'capped' || $h['state'] === 'unknown') {", "            if ($h['state'] === 'unknown') {", MODULE),
    ('a "since" the reader cannot place ignored', UV,
     '            if ($since !== null && $sinceTs === null) {', '            if (false) {', MODULE),
    ('the lock times left out of the log', UV,
     "                if (isset($to['log'], $v[$fk]) && is_scalar($v[$fk])) $extra[$to['log']] = (string) $v[$fk];",
     '                if (false) $extra[$to[\'log\']] = (string) $v[$fk];', MODULE),
    ('the lock day left out of the scan', UV,
     "if (isset($to['scan'], $v[$fk]) && is_scalar($v[$fk]) && !isset($out[$to['scan']])) $out[$to['scan']] = (string) $v[$fk];",
     "if (false) $out[$to['scan']] = (string) $v[$fk];", MODULE),
    ('the lock time cut to the day', UV,
     "if (is_string($r['lockedAt'])) $finding['lockedAt'] = substr($r['lockedAt'], 0, 16);",
     "if (is_string($r['lockedAt'])) $finding['lockedAt'] = substr($r['lockedAt'], 0, 10);", MODULE),
    ('a log that cannot be read judged anyway', UV,
     "            if ($history === false) {\n                $out['unconfigurable'][] = ['fields' => [$field], 'why' => 'the project log could not be read — field not checked'];\n                continue;\n            }\n",
     '', MODULE),
    # ---- the hooks ----
    ('a lock on a returned field accepted', UV,
     "                if (!isset($owner[$f]) || $owner[$f] === $i || !empty($rules[$owner[$f]]['configError'])) { $keep[] = $f; continue; }",
     '                { $keep[] = $f; continue; }', MODULE),
    ('a lookup refused for another reason still refuses the lock', UV,
     "                if (!isset($owner[$f]) || $owner[$f] === $i || !empty($rules[$owner[$f]]['configError'])) { $keep[] = $f; continue; }",
     '                if (!isset($owner[$f]) || $owner[$f] === $i) { $keep[] = $f; continue; }', MODULE),
    ("one rule's refusals out of field order", UV,
     '                $rules[$i] = array_shift($mine);', '                $rules[$i] = array_pop($mine);', MODULE),
    ('the page locks before the "since" day', UV,
     "                if (strcmp($today, $r['writeonceSince']) < 0) continue;   // the lock starts later\n",
     '', MODULE),
    ('a refused role says "on field" twice', UV,
     "        return ['error' => (count($unknown) === 1",
     "        return ['error' => AnnotationRules::TAG_WRITEONCE . ' on \"' . $name . '\": ' . (count($unknown) === 1", MODULE),
    ('a code saved over a locked value left out before the verdict', MODES,
     '"evaluator": "findingsWriteonce",\n      "missingCodes": "answer",', '"evaluator": "findingsWriteonce",\n      "missingCodes": "skip",', MODULE),
    ('the import the page never sees left out of the scan', UV,
     "            $h = $history->saves($record, $event_id, $instance, $field);",
     "            $h = ['state' => 'read', 'saves' => []];", LIVE_PHP),
    ('a refused lock takes its whole group down', UV,
     '            if ($keep) {\n                $rules[$i][\'fields\'] = $keep;', '            if (false) {\n                $rules[$i][\'fields\'] = $keep;', MODULE),
    ('@SETVALUE locked', UV,
     "        if (preg_match('/@(SETVALUE|PREFILL)\\b/i', $ann, $m)) {", '        if (false) {', MODULE),
    ('@PREFILL locked', UV,
     "preg_match('/@(SETVALUE|PREFILL)\\b/i', $ann, $m)", "preg_match('/@(SETVALUE)\\b/i', $ann, $m)", MODULE),
    ('@SETVALUE refused only in capitals', UV,
     "preg_match('/@(SETVALUE|PREFILL)\\b/i', $ann, $m)", "preg_match('/@(SETVALUE|PREFILL)\\b/', $ann, $m)", MODULE),
    ('@RICHTEXT locked', UV,
     "        if (preg_match('/@RICHTEXT\\b/i', $ann)) {", '        if (false) {', MODULE),
    ('a BioPortal search locked', UV,
     "stripos($enum, 'BIOPORTAL:') === 0) {\n            return ['error' => AnnotationRules::TAG_WRITEONCE",
     "false) {\n            return ['error' => AnnotationRules::TAG_WRITEONCE", MODULE),
    ('a value returned to a @PREFILL field accepted', UV,
     "'/@(CALCTEXT|CALCDATE|SETVALUE|PREFILL)\\b/i'", "'/@(CALCTEXT|CALCDATE|SETVALUE)\\b/i'", EXISTS_RETURN),
    ("a dropdown's saved code not sent to the page", UV,
     "                if (isset($dd[$f]['field_type']) && $dd[$f]['field_type'] === 'dropdown') $r['writeonceSaved'][$f] = (string) $values[$f];\n",
     '', LIVE_PHP),
    ('a history the reader could not finish judged as no saves', UV,
     "            if ($h['state'] === 'capped' || $h['state'] === 'unknown') {", "            if ($h['state'] === 'capped') {", MODULE),
    ('why a history is unknown left out', UV,
     "$why = isset($h['why'], $unknown[$h['why']]) ? $unknown[$h['why']] : $unknown['read'];", "$why = $unknown['read'];", MODULE),
    ('a rule on a locked field that reads another field not marked', UV,
     "            if ($marks) $rules[$i]['lockReads'] = $marks;", '', MODULE),
    ('every rule on a locked field marked', UV,
     'if (isset($locked[(string) $f]) && array_diff($reads, [(string) $f])) $marks[] = (string) $f;',
     'if (isset($locked[(string) $f])) $marks[] = (string) $f;', MODULE),
    ('lockReads never worked out', UV,
     "        $config['rules'] = self::markLockReads($config['rules']);\n", '', MODULE),
    ('the reader not told the dictionary order', UV,
     "return ['order' => array_flip(array_map('strval', array_keys($dd))),", "return ['order' => [],", MODULE),
    ('a project of several arms read as one', UV,
     "'multiArm' => !$events || count($arms) !== 1 || isset($arms['?'])];", "'multiArm' => false];", MODULE),
    ('a project of one arm read as several', UV,
     "'multiArm' => !$events || count($arms) !== 1 || isset($arms['?'])];", "'multiArm' => true];", MODULE),
    # ---- the role book ----
    ('a REDCap with no unique role names not read', ROLEBOOK,
     "foreach ([', r.unique_role_name', ''] as $unique) {", "foreach ([', r.unique_role_name'] as $unique) {", VERDICT),
    ("the database's date used for today", ROLEBOOK,
     'u.expiration > ?)', 'u.expiration > CURDATE())', VERDICT),
    ('roles joined to rights by project, not by role', ROLEBOOK,
     "' JOIN redcap_user_roles r ON r.role_id = u.role_id'", "' JOIN redcap_user_roles r ON r.project_id = u.project_id'", MYSQL),
    ('rights that expire today still count', ROLEBOOK,
     'u.expiration > ?', 'u.expiration >= ?', MYSQL),
    ('a suspended account holds its role', ROLEBOOK,
     ' AND i.user_suspended_time IS NULL', '', MYSQL),
    ('@CALCTEXT locked', UV,
     "        if (preg_match('/@(CALCTEXT|CALCDATE)\\b/i', $ann, $m)) {",
     "        if (false && preg_match('/@(CALCTEXT|CALCDATE)\\b/i', $ann, $m)) {", MODULE),
    ('a role named in other letters refused', UV,
     "foreach ($roles as $role) if (!isset($book['roles'][strtolower(trim((string) $role))])) $unknown[] = (string) $role;",
     "foreach ($roles as $role) if (!isset($book['roles'][trim((string) $role)])) $unknown[] = (string) $role;", MODULE),
    ('roles that cannot be read refuse every role', UV,
     '        if ($book === null) return $frag;\n        $unknown = [];', "        if ($book === null) $book = ['roles' => []];\n        $unknown = [];", MODULE),
    ('a role held in other letters not matched', UV,
     "if (in_array(strtolower(trim((string) $role)), $book['users'][$u], true)) return true;",
     "if (in_array(trim((string) $role), $book['users'][$u], true)) return true;", MODULE),
    # ---- the grammar ----
    ('"caseSensitive" taken by a tag with no condition', RULES,
     "foreach (['when' => '\"when\"', 'caseSensitive' => '\"caseSensitive\"', 'blockSave' => '\"blockSave\"'] as $k => $label) {",
     "foreach (['when' => '\"when\"', 'blockSave' => '\"blockSave\"'] as $k => $label) {", ANNOTATION),
    ('a "since" that is not a day of the calendar taken', RULES,
     "&& checkdate((int) $m[2], (int) $m[3], (int) $m[1]);", ';', MODULE),
    ('more than 20 roles taken', RULES,
     '            } elseif (count($roles) > self::MAX_WRITEONCE_ROLES) {', '            } elseif (false) {', MODULE),
    ('a registry "when" that is not false taken', MODEREG,
     "            if (array_key_exists('when', $m) && $m['when'] !== false) {", '            if (false) {', REGISTRY),
    ('a registry lock of any shape taken', MODEREG,
     "                if (!is_array($lk) || array_keys($lk) !== ['returnWhy'] || !is_string($lk['returnWhy'])\n                        || strpos($lk['returnWhy'], '{field}') === false || strpos($lk['returnWhy'], '{by}') === false) {",
     '                if (false) {', REGISTRY),
    ('a finding key may take a key the module writes', MODEREG,
     '                        if (isset($to[$where]) && in_array($to[$where], $taken, true)) {', '                        if (false) {', REGISTRY),
    ('every mode read as a lock', MODEREG,
     "        return isset($m['lock']);", '        return true;', MODULE),
    ('a tag with no condition given twice not refused', BRANCHING,
     "        if (!ModeRegistry::takesWhen($mode)) return ['kind' => 'twice', 'rules' => $idxs, 'detail' => ModeRegistry::tag($mode), 'type' => $t];\n",
     '', MODULE),
    ('a mode with no condition read as taking one', MODEREG,
     "return !(is_array($m) && array_key_exists('when', $m) && $m['when'] === false);", 'return true;', ANNOTATION),
    # ---- the page ----
    ("another mode's item on a locked field holds the save", ENGINE,
     'return el.__qridLock === true || UV_LOCKED[el.__qridFieldName] !== true\n        || UV_LOCK_READS[el.__qridFieldName + "\\x1F" + el.__qridMode] === true;',
     'return true;', DOM),
    ('a rule on a locked field that reads another field never holds the save', ENGINE,
     'return el.__qridLock === true || UV_LOCKED[el.__qridFieldName] !== true\n        || UV_LOCK_READS[el.__qridFieldName + "\\x1F" + el.__qridMode] === true;',
     'return el.__qridLock === true || UV_LOCKED[el.__qridFieldName] !== true;', DOM),
    ('a lookup in flight on a locked field holds the save', ENGINE,
     'var pend = live.filter(', 'var pend = UV_guard.items.filter(', DOM),
    ('lockReads not read from the rule', ENGINE,
     'rule.lockReads.forEach(function(f){ if(typeof f === "string") UV_LOCK_READS[f + "\\x1F" + modeOfType(rule.type)] = true; });', '', DOM),
    ('a guard item that does not name its mode', ENGINE,
     '  input.__qridMode = mode;\n', '', DOM),
    ("the lock's own read-only taken for @READONLY", ENGINE,
     '  if(input.__qridLockedRO === true) return false;\n', '', DOM),
    ("the lock's own item counted as another mode's", ENGINE,
     'var GITEM = { __qridInvalid: false, __qridBlockMode: "off", __qridLock: true,',
     'var GITEM = { __qridInvalid: false, __qridBlockMode: "off",', DOM),
    ('a value that cannot be put back lets the save through', ENGINE,
     '      try { QRID_writeReturned(fieldName, saved, true, true); } catch(e){}\n      if(el.value !== saved) return false;\n',
     '      try { QRID_writeReturned(fieldName, saved, true, true); } catch(e){}\n', DOM),
    ('a text box left editable', ENGINE,
     '    } else if(!el.readOnly){\n      el.readOnly = true;\n      el.__qridLockedRO = true;\n    }', '    } else if(!el.readOnly){\n    }', DOM),
    ("the lock's read-only box not marked as the lock's", ENGINE,
     '      el.readOnly = true;\n      el.__qridLockedRO = true;\n', '      el.readOnly = true;\n', DOM),
    ('the radio buttons left enabled', ENGINE,
     '        if(!radios[i].disabled){ radios[i].disabled = true; radios[i].__qridLockedRO = true; }\n', '', DOM),
    ("the radios the lock disabled not marked as the lock's", ENGINE,
     'radios[i].disabled = true; radios[i].__qridLockedRO = true; }', 'radios[i].disabled = true; }', DOM),
    ('the enhanced radio buttons take clicks', ENGINE,
     'for(var ei = 0; ei < enh.length; ei++) enh[ei].style.pointerEvents = "none";', '', DOM),
    ('a dropdown disabled (REDCap would save it blank)', ENGINE,
     '      if(el.style) el.style.pointerEvents = "none";', '      el.disabled = true;', DOM),
    ("REDCap's radio reset link left on the page", ENGINE,
     'var WRITERS = /radioResetVal|setToday|setNow/;', 'var WRITERS = /setToday|setNow/;', DOM),
    ("REDCap's Today and Now buttons left on the page", ENGINE,
     'var WRITERS = /radioResetVal|setToday|setNow/;', 'var WRITERS = /radioResetVal/;', DOM),
    ('only links looked at, not buttons', ENGINE,
     'var kinds = ["a", "button"];', 'var kinds = ["a"];', DOM),
    ('a change no event announces left in place', ENGINE,
     '    if(selfWatch) selfWatch.onChange(function(){ restore(); });', '', DOM),
    ('the slider left enabled', ENGINE,
     'if(sl.length && sl.slider) sl.slider("disable");', '', DOM),
    ('the date picker left able to open', ENGINE,
     'dp.datepicker("option", "beforeShow", function(){ return false; });', '', DOM),
    ('the date picker disabled (the form would leave the field out)', ENGINE,
     'dp.datepicker("option", "beforeShow", function(){ return false; });', 'dp.datepicker("disable");', DOM),
    ("the date picker's button left", ENGINE,
     'if(dp.nextAll) dp.nextAll(".ui-datepicker-trigger").hide();', '', DOM),
    ('a dropdown whose code is no longer a choice saved blank', ENGINE,
     '    keepOption(el, saved);\n    SAVED[fieldName] = saved;', '    SAVED[fieldName] = saved;', DOM),
    ('the value the server read ignored', ENGINE,
     '    var saved = (QRID_CONFIG.writeonceSaved && typeof QRID_CONFIG.writeonceSaved[fieldName] === "string")\n      ? QRID_CONFIG.writeonceSaved[fieldName] : el.value;',
     '    var saved = el.value;', DOM),
    ('an autocomplete box built after the lock left editable', ENGINE,
     '      lockControls(fieldName, el);\n      if(el.value === saved) return true;', '      if(el.value === saved) return true;', DOM),
    ('roles that could not be read not said on the page', ENGINE,
     '(QRID_CONFIG.writeonceRolesUnread === true ? " " + QRID_escapeHtml(UNREAD) : "") +', '"" +', DOM),
    ('the rule message written into the page unescaped', ENGINE,
     '(QRID_ruleHtml(V, "message") || QRID_escapeHtml(NOTE))', '(V.message || QRID_escapeHtml(NOTE))', DOM),
    ('an undone change not said', ENGINE,
     '      undone = true;\n      show();', '      show();', DOM),
    ('a configuration error on a dropdown left for the page notice', ENGINE,
     'var input = QRID_findField(fieldName) || QRID_findAnchor(fieldName);', 'var input = QRID_findField(fieldName);', LIVE_JS),
    ("every configuration error called an ID check's", ENGINE,
     '"&#9888; Configuration error: "', '"&#9888; ID-check configuration error: "', LIVE_JS),
    ('test() answers true for a changed value', ENGINE,
     'return el ? el.value === SAVED[f] : null;', 'return el ? true : null;', DOM),
]


def run(suite, cwd):
    """The suite's result, or None for the database suite when no server is named."""
    env = None
    if suite == MYSQL:
        if not os.environ.get('UV_DB_HOST'):
            return None
        cmd = [PHP, '-d', 'extension=mysqli', suite]
        env = dict(os.environ, UV_CASES='history')
    else:
        cmd = [NODE, suite] if suite.endswith('.cjs') else [PHP, suite]
    return subprocess.run(cmd, cwd=cwd, capture_output=True, text=True, encoding='utf-8', errors='replace', env=env)


def main():
    for suite in sorted({m[-1] for m in MUTATIONS}):
        res = run(suite, ROOT)
        if res is not None and res.returncode != 0:
            sys.exit(f'{suite} fails before any mutation:\n{res.stdout[-2000:]}{res.stderr[-2000:]}')
    survived = []
    skipped = []
    with tempfile.TemporaryDirectory(prefix='uv-writeonce-mutations-') as directory:
        copy = Path(directory)
        for folder in ['php', 'js', 'tests', 'data', 'docs']:
            shutil.copytree(ROOT / folder, copy / folder)
        for filename in [UV, 'config.json']:
            shutil.copy2(ROOT / filename, copy / filename)
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
        sys.exit(f'writeonce_mutations: {len(survived)} of {len(MUTATIONS)} mutations survived')
    ran = len(MUTATIONS) - len(skipped)
    print(f'writeonce_mutations: {ran} mutations, every one killed' + (f'; {len(skipped)} skipped (no database)' if skipped else ''))


if __name__ == '__main__':
    main()
