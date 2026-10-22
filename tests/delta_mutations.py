#!/usr/bin/env python3
"""Mutations of @UVDELTA, applied only to a disposable copy.

Each mutation breaks one guarantee the suites claim to hold. The named suite must then fail;
a mutation it survives is a hole in the tests. The anchors are exact text and must occur once.

Usage: PHP_BIN=php python tests/delta_mutations.py
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
ENGINE = 'js/engine.js'
VERDICT_PHP = 'tests/delta_php.php'
VERDICT_JS = 'tests/delta_js.cjs'
DOM = 'tests/delta_dom_js.cjs'
MODULE = 'tests/delta_module_php.php'
REGISTRY = 'tests/registry_php.php'
TEMPORAL = 'php/TemporalRules.php'
MODES = 'php/modes.json'
MODEREG = 'php/ModeRegistry.php'
REQUIRED_DOM = 'tests/required_dom_js.cjs'
CHOICES_DOM = 'tests/choices_dom_js.cjs'
GROWTH_DOM = 'tests/growth_dom_js.cjs'
GROWTH_MODULE = 'tests/growth_module_php.php'
TEMPORAL_INT = 'tests/temporal_integration_php.php'

# (name, file, original, replacement, suite)
MUTATIONS = [
    # ---- the verdict, PHP ----
    ('an earlier 0 judged by maxPct', LOGIC,
     "if ($pct !== null && self::decCmp($base, '0') === 0) {",
     'if (false) {', VERDICT_PHP),
    ('direction ignored', LOGIC,
     "if ($sign === 0 || ($sign > 0 && $dir === 'down') || ($sign < 0 && $dir === 'up')) {",
     'if ($sign === 0) {', VERDICT_PHP),
    ('"max" exclusive', LOGIC,
     "if ($max !== null && self::decCmp($size, $max) > 0)",
     "if ($max !== null && self::decCmp($size, $max) >= 0)", VERDICT_PHP),
    ('the percent compared as floats', LOGIC,
     "if (self::decCmp($lhs, $rhs) > 0) return ['verdict' => $way, 'reason' => 'maxPct'",
     "if ((float) $lhs > (float) $rhs) return ['verdict' => $way, 'reason' => 'maxPct'", VERDICT_PHP),
    ('the percent of the new value, not the earlier one', LOGIC,
     "$rhs = ExactDecimal::multiply($pct, $base);",
     "$rhs = ExactDecimal::multiply($pct, ltrim($v, '+-'));", VERDICT_PHP),
    ('a percent too long to work out passes', LOGIC,
     "if ($lhs === null || $rhs === null) return ['verdict' => 'unknown', 'reason' => 'too-long', 'change' => $change];",
     "if ($lhs === null || $rhs === null) return ['verdict' => 'ok', 'reason' => null, 'change' => $change];", VERDICT_PHP),
    ('the earlier value read with a point always', LOGIC,
     "$e = self::deltaNumber($earlier, !empty($spec['earlierComma']));",
     '$e = self::deltaNumber($earlier, false);', VERDICT_PHP),
    # ---- the verdict, browser ----
    ('JS: an earlier 0 judged by maxPct', ENGINE,
     'if(pct !== null && QRID_whenDecCmp(base, "0") === 0){',
     'if(false){', VERDICT_JS),
    ('JS: "max" exclusive', ENGINE,
     'if(max !== null && QRID_whenDecCmp(size, max) > 0)',
     'if(max !== null && QRID_whenDecCmp(size, max) >= 0)', VERDICT_JS),
    ('JS: the percent compared as floats', ENGINE,
     'if(QRID_whenDecCmp(lhs, rhs) > 0) return { verdict: way, reason: "maxPct"',
     'if(Number(lhs) > Number(rhs)) return { verdict: way, reason: "maxPct"', VERDICT_JS),
    ('JS: a change too long to add passes', ENGINE,
     'if(change === null) return { verdict: "unknown", reason: "too-long", change: null };',
     'if(change === null) return { verdict: "ok", reason: null, change: null };', VERDICT_JS),
    # ---- the grammar ----
    ('a shorthand in capitals not read', RULES,
     '$kw = strtolower($vs);',
     '$kw = $vs;', VERDICT_PHP),
    ('a limit loses the digits it was typed with', RULES,
     '$b = self::rangeBound($cfg[$k], $typed);',
     '$b = self::rangeBound($cfg[$k], null);', VERDICT_PHP),
    ('a shorthand accepted with references off', RULES,
     "            if (empty($opts['qualified'])) {\n                $errors[] = '\"vs\": \"' . $frag['deltaVs']",
     "            if (false) {\n                $errors[] = '\"vs\": \"' . $frag['deltaVs']", VERDICT_PHP),
    ('a negative limit accepted', RULES,
     "} elseif (Logic::numCompare($v, '0') < 0) {\n                $errors[] = '\"' . $label . '\" must be 0 or more",
     "} elseif (false) {\n                $errors[] = '\"' . $label . '\" must be 0 or more", VERDICT_PHP),
    ('the branch group forgets the direction', RULES,
     "return strtolower(preg_replace('/\\s+/', '', (string) $vs)) . ' ' . $direction;",
     "return strtolower(preg_replace('/\\s+/', '', (string) $vs));", VERDICT_PHP),
    ('no branch groups: two tags on one field conflict', 'php/modes.json',
     '"branchGroup": {"key": "deltaGroup", "name": "vs and direction", "surveyOpaque": true},',
     '', MODULE),
    # ---- the hooks ----
    ('a "vs" naming the field itself accepted', UV,
     "if ($op !== null && $op[0] === 'ref' && $op[1] === $name) {",
     'if (false) {', MODULE),
    ('a shorthand not written out for the field', UV,
     '$frag[\'deltaVs\'] = sprintf(AnnotationRules::DELTA_SHORTHANDS[$short], $name);',
     '$frag[\'deltaVs\'] = $frag[\'deltaVs\'];', MODULE),
    ('the "vs" field\'s comma not marked', UV,
     "if (substr($validation, -strlen('_comma_decimal')) === '_comma_decimal') $frag['deltaVsComma'] = true;",
     '', MODULE),
    ('an event shorthand that names an event where the form repeats accepted', UV,
     "if ($b['bucket'] !== null) $repeating[(string) $t['event']] = $label($t['event']);",
     '', MODULE),
    ('an instance shorthand at an event where the form does not repeat accepted', UV,
     "if ($b['bucket'] === null) $flat[] = $label($id);",
     '', MODULE),
    ('an event shorthand with no earlier event in any arm accepted', UV,
     'if ($pairs === 0) {',
     'if (false) {', MODULE),
    ('a written-out reference to this same entry accepted', UV,
     "if ($op[0] === 'qref' && $f === $name && self::deltaNamesItself($shape, $op, $form)) {",
     'if (false) {', MODULE),
    ('the one event a form is on not read as this entry', UV,
     "if (in_array($form, $e['forms'], true)) $hosts[] = $id;",
     "if (false) $hosts[] = $id;", MODULE),
    ('the hook\'s branch group forgets the direction (heart-rate recipe)', UV,
     "$frag['deltaGroup'] = AnnotationRules::deltaGroup($frag['deltaVs'], isset($frag['deltaDirection']) ? $frag['deltaDirection'] : 'both');",
     "$frag['deltaGroup'] = AnnotationRules::deltaGroup($frag['deltaVs'], 'both');", MODULE),
    ('the first-event and first-instance names swapped', UV,
     "'first-event' => 'the first event', 'first-instance' => 'the first instance'];",
     "'first-event' => 'the first instance', 'first-instance' => 'the first event'];", MODULE),
    ('"too long to compare" said as something else', UV,
     ": 'the value and its \"vs\" value are too long to compare'));",
     ": 'the value could not be read'));", MODULE),
    ('the audit judges with no budget', UV,
     '$charge = function ($units) use ($budget) { return $budget->take($units); };',
     '$charge = null;', MODULE),
    ('a deferral only this viewer meets not marked as checked on save', TEMPORAL,
     "if($browser&&$problems&&array_values(array_unique($problems))===['unauthorized'])$compiled['deferredChecked']=true;",
     '', MODULE),
    ('a "vs" field that holds no numbers accepted', UV,
     "if (!in_array($ftype, ['calc', 'slider'], true) && !($ftype === 'text' && is_string($re) && preg_match('~' . $re . '~', $validation))) {",
     'if (false) {', MODULE),
    # ---- the audit ----
    ('an earlier Missing Data Code judged', UV,
     "if (self::isMissingCode($earlier, $this->missingDataCodes($project_id))) $earlier = '';",
     '', MODULE),
    ('a value that is not a number passes silently', UV,
     "            if ($r['verdict'] === 'unknown') {\n                $why = $r['reason'] === 'not-a-number'",
     "            if ($r['verdict'] === 'unknown') continue;\n            if (false) {\n                $why = $r['reason'] === 'not-a-number'", MODULE),
    ('a finding logs no "vs"', UV,
     "if (isset($v['vs'])) $extra['vs'] = (string) $v['vs'];",
     '', MODULE),
    ('an unresolved plain "vs" read as blank', UV,
     "            if ($state !== 'ok') {\n                $out['unconfigurable'][] = ['fields' => $rule['fields'], 'why' => 'the \"vs\" value ' . self::resolutionProblem($state, $op[1])];\n                return $out;\n            }\n",
     '', MODULE),
    ('an unresolved event/instance "vs" read as blank', UV,
     "            if (!is_string($earlier)) {\n                $why = 'the \"vs\" value ' . $vs . ' could not be resolved';",
     "            if (!is_string($earlier)) $earlier = '';\n            if (false) {\n                $why = 'the \"vs\" value ' . $vs . ' could not be resolved';", MODULE),
    # ---- the verdict: exact numbers, and the budget ----
    ('an exponent clamped to 10^70 again', LOGIC,
     "$v = self::deltaNumber($value, !empty($spec['decimalComma']));",
     "$v = self::normalizeNumber($value, !empty($spec['decimalComma']));", VERDICT_PHP),
    ('JS: an exponent clamped to 10^70 again', ENGINE,
     'var v = QRID_deltaNumber(value, !!spec.decimalComma), e = QRID_deltaNumber(earlier, !!spec.earlierComma);',
     'var v = QRID_rangeNumber(value, !!spec.decimalComma), e = QRID_rangeNumber(earlier, !!spec.earlierComma);', VERDICT_JS),
    ('the sum not paid for', LOGIC,
     "if (!self::spendOn($charge, ExactDecimal::sumCost([$v, $minus]))) return",
     "if (false) return", VERDICT_PHP),
    ('the percent products not paid for', LOGIC,
     "if (!self::spendOn($charge, ExactDecimal::multiplyCost($size, '100') + ExactDecimal::multiplyCost($pct, $base))) {",
     'if (false) {', VERDICT_PHP),
    ('JS: the trim retried from every inner space again', ENGINE,
     '''function QRID_whenTrim(v){
  var s = String(v), i = 0, j = s.length;
  while(i < j && QRID_whenSpace(s.charCodeAt(i))) i++;
  while(j > i && QRID_whenSpace(s.charCodeAt(j - 1))) j--;
  return (i === 0 && j === s.length) ? s : s.slice(i, j);
}''',
     'function QRID_whenTrim(v){ return String(v).replace(/^[ \\t\\r\\n]+|[ \\t\\r\\n]+$/g, ""); }', VERDICT_JS),
    ('"maxPct": 0 accepted', RULES,
     "} elseif ($k === 'deltaMaxPct' && Logic::numCompare($v, '0') === 0) {",
     '} elseif (false) {', VERDICT_PHP),
    # ---- the registry ----
    ('a survey told which field a delta group compares with', 'php/ModeRegistry.php',
     "$key = $survey ? self::surveyOpaqueGroup(isset($r['type']) ? $r['type'] : '') : null;",
     '$key = null;', REGISTRY),
    ('a survey page carries the "vs" and its name', MODES,
     '"staffOnlyKeys": ["deltaVs", "deltaVsLabel"],',
     '', REGISTRY),
    # ---- the page ----
    ('JS: "maxPct": 0 accepted', ENGINE,
     'if(!configError && pct !== null && QRID_whenDecCmp(pct, "0") === 0){',
     'if(false){', DOM),
    ('a rounded percent shown at the limit it broke', ENGINE,
     'if(broke && V.spec.maxPct !== null && QRID_whenDecCmp(String(one), V.spec.maxPct) <= 0){',
     'if(false){', DOM),
    ('a percent that rounds to 0 said as 0%', ENGINE,
     'if(one === 0) return " (under " + shown("0.1", comma) + "%)";',
     '', DOM),
    ('a percent of a million or more shown', ENGINE,
     'if(!isFinite(p) || p >= 1e6) return "";',
     'if(!isFinite(p)) return "";', DOM),
    ('the earlier value said as typed', ENGINE,
     'var earlier = tidy(QRID_deltaNumber(r.earlier, V.spec.earlierComma));',
     'var earlier = r.earlier;', DOM),
    ('a gate judges every keystroke', ENGINE,
     'if(!text || typeof a.addEventListener !== "function" || typeof field !== "string" || a.name !== field){ check(); return; }',
     '{ check(); return; }', DOM),
    ('a value written by a script never judged (no self-watch)', ENGINE,
     '''    if(selfWatch) selfWatch.onChange(function(){ if(!typing()) check(); });
    /* A gate or a watched field heard a keystroke: judge once the person
       leaves the box they are typing in (QRID_afterTyping). */
    var later = QRID_afterTyping(check);
    for(var gi = 0; gi < VS.all.length; gi++){
      if(VS.all[gi].gate) VS.all[gi].gate.onChange(later);
      for(var wi = 0; wi < VS.all[gi].watch.length; wi++) VS.all[gi].watch[wi].onChange(later);
    }
    check();
    return true;''',
     '''    /* A gate or a watched field heard a keystroke: judge once the person
       leaves the box they are typing in (QRID_afterTyping). */
    var later = QRID_afterTyping(check);
    for(var gi = 0; gi < VS.all.length; gi++){
      if(VS.all[gi].gate) VS.all[gi].gate.onChange(later);
      for(var wi = 0; wi < VS.all[gi].watch.length; wi++) VS.all[gi].watch[wi].onChange(later);
    }
    check();
    return true;''', DOM),
    ('the registry answers true for a value it cannot judge', ENGINE,
     'if(v !== "ok") open = true;',
     '', DOM),
    ('two modes on one field: a failing one ignored by the registry', ENGINE,
     'if(r === false) return false;',
     'if(r === false) continue;', DOM),
    ('a deferral the save checks said to be unchecked', ENGINE,
     'v.checkedOnSave = v.checkedOnSave === true || input.deferredChecked === true || cfg.deferredChecked === true;',
     'v.checkedOnSave = v.checkedOnSave === true;', DOM),

    ('a calc and an event/instance rule block', ENGINE,
     'var advisory = cfg.extendedAdvisory === true || cfg.deltaComputed === true || DEFERRED;',
     'var advisory = DEFERRED;', DOM),
    ('a survey told what the value is compared with', ENGINE,
     '(QRID_IS_SURVEY || !V.label ? "" : " (" + V.label + ")")',
     '(!V.label ? "" : " (" + V.label + ")")', DOM),
    ('a withheld earlier value read as blank', ENGINE,
     'if(op[0] === "withheld") return null;\n    var e = op[0] === "ref"',
     'if(op[0] === "withheld") return "";\n    var e = op[0] === "ref"', DOM),
    ('an earlier Missing Data Code judged on the page', ENGINE,
     'return QRID_isMissingCode(e) ? "" : e;',
     'return e;', DOM),
    ('changing the "vs" field re-checks nothing', ENGINE,
     'var WATCH = (vsOp && vsOp[0] === "ref") ? QRID_WHEN.gateFor(null, ["cmp", "=", vsOp, ["lit", ""]]) : null;',
     'var WATCH = null;', DOM),
    ('an earlier value that is not a number says nothing', ENGINE,
     'if(QRID_IS_SURVEY || r.reason === "not-a-number") return { kind: "none" };',
     'return { kind: "none" };', DOM),
    ('the stricter block of two comparisons ignored', ENGINE,
     '        if(one.kind === "bad") worst = "bad";\n        if(one.block && BLOCK_RANK[one.block] > BLOCK_RANK[block]) block = one.block;',
     '        if(one.kind === "bad") worst = "bad";\n        if(one.block && block === "off") block = one.block;', DOM),
    # ---- the second review's fixes ----
    ("a save keeps the last record's budget", UV,
     '        // not inherit what this one spent.\n        $this->temporalBudget = null;',
     '        // not inherit what this one spent.', MODULE),
    ('a long plain number charged before it is refused', LOGIC,
     'if (preg_match(self::NUM_RE, $v)) return strlen($v) > self::MAX_PLAIN ? false : $v;',
     'if (preg_match(self::NUM_RE, $v)) return $v;', VERDICT_PHP),
    ('JS: a long plain number read', ENGINE,
     'if(QRID_WHEN_NUM_RE.test(v)) return v.length > QRID_MAX_PLAIN ? false : v;',
     'if(QRID_WHEN_NUM_RE.test(v)) return v;', VERDICT_JS),
    ('[first-event-name] on a form at one event is another entry', UV,
     "if (!isset($r['state']) || $r['state'] !== 'ok' || (string) $r['event'] !== (string) $h) return false;",
     "if (!isset($r['state']) || $r['state'] !== 'ok' || ($event !== null && $event !== 'event-name' && $event !== $shape->event($h)['name'])) return false;",
     MODULE),
    ('[current-instance] on a repeating form not read as this entry', UV,
     "if ($instance !== null && $instance !== 'current-instance') return false;",
     "if ($instance !== null) return false;", MODULE),
    # ---- the third review's fixes ----
    ('an instance of a form that does not repeat accepted', UV,
     "        if ($flat) return ['kind' => 'flat',", "        if (false) return ['kind' => 'flat',", MODULE),
    ('an instance of a form that repeats refused', UV,
     "            } elseif ($b['bucket'] === null) {\n                $add($flat, $r['event']);",
     "            } elseif (true) {\n                $add($flat, $r['event']);", MODULE),
    ('the one event of a classic project named in the refusal', UV,
     "        $many = count($shape->events()) > 1;\n        $names = [];", "        $many = true;\n        $names = [];", MODULE),
    ('a relative instance from a form that does not repeat accepted', UV,
     "        if ($own) return ['kind' => 'own',", "        if (false) return ['kind' => 'own',", MODULE),
    ('a form that repeats read as held once for a relative instance', UV,
     "'next-instance'], true) && $src['bucket'] === null) {\n                $add($own, $id);",
     "'next-instance'], true)) {\n                $add($own, $id);", MODULE),
    ('only the first event where the form is held once named', UV,
     "                $add($own, $id);", "                if (!$own) $add($own, $id);", MODULE),
    ('an event that does not hold the "vs" form accepted', UV,
     "{ $add($missing, $named ? $r['event'] : $id); continue; }", '{ continue; }', MODULE),
    ('a repeating "vs" with no instance accepted', UV,
     "        if ($amb) return ['kind' => 'ambiguous',", "        if (false) return ['kind' => 'ambiguous',", MODULE),
    ("the field's own repeating row read as naming no instance", UV,
     "if ($b['bucket'] !== null && !$ownRow) $add($amb, $r['event']);", "if ($b['bucket'] !== null) $add($amb, $r['event']);", MODULE),
    ("another event's row of the same form read as the field's own", UV,
     "$ownRow = (string) $r['event'] === (string) $id && $src['bucket'] === $b['bucket'];", "$ownRow = $src['bucket'] === $b['bucket'];", MODULE),
    ('the event token of a reference ignored', UV,
     "            $r = $shape->eventId($token, $id, $vsForm);", "            $r = $shape->eventId(null, $id, $vsForm);", MODULE),
    ('a relative event that never finds one accepted', UV,
     "        if ($relative && $absent === $hosts) return", "        if (false) return", MODULE),
    ('the suggested reference loses its event', UV,
     "                return ['ref' => ($t !== null ? '[' . $t . ']' : '') . '[' . $f . ']'", "                return ['ref' => '[' . $f . ']'", MODULE),
    ('the suggestion does not say it needs references on', UV,
     "$c['ref'] . '\"' . ($c['qualified'] && !$refsOn ? self::DELTA_NEEDS_REFS : '') . '.';", "$c['ref'] . '\"' . '.';", MODULE),
    # ---- the sixth review's fixes ----
    ('a suggestion offered without being checked', UV,
     "                if (self::deltaReferenceProblem($shape, $cop, $form, $vsForm) !== null) continue;\n", '', MODULE),
    ("an event of one arm suggested to a field entered in two", UV,
     'if (count($arms) === 1) foreach', 'if (true) foreach', MODULE),
    ('the instance written left out of the quote', UV,
     "$written = ($token !== null ? '[' . $token . ']' : '') . '[' . $f . ']' . ($sel !== null ? '[' . $sel . ']' : '');",
     "$written = ($token !== null ? '[' . $token . ']' : '') . '[' . $f . ']';", MODULE),
    ('a form designated at no event called missing where the field is entered', UV,
     "        if (!$held) return ['kind' => 'nowhere', 'events' => []];\n", '', MODULE),
    ('this same entry suggested to a field compared with itself', UV,
     "if ($f === $name && ($cop[0] === 'ref' || self::deltaNamesItself($shape, $cop, $form))) continue;", 'if (false) continue;', MODULE),
    ('a suggestion tried only with the instance as written', UV,
     "foreach ($f === $name ? [null, 'previous-instance', 'last-instance'] : [null, 'last-instance', 'previous-instance'] as $s)", 'foreach ([] as $s)', MODULE),
    ('an event named outright offered first to a field compared with its own form', UV,
     '$tokens = array_merge($tokens, $vsForm === $form', '$tokens = array_merge($tokens, false', MODULE),
    ('the event as written dropped when only the instance is wrong', UV,
     '        $tokens = $keepEvent && $inArm ? [$token] : [];', '        $tokens = [];', MODULE),
    # ---- the seventh review's fixes ----
    ('an event named outright as written kept across two arms', UV,
     '        $tokens = $keepEvent && $inArm ? [$token] : [];', '        $tokens = $keepEvent ? [$token] : [];', MODULE),
    ('an event named outright as written dropped in one arm', UV,
     '|| (count($arms) === 1 && isset($armOf[$token]) && isset($arms[$armOf[$token]]));', '|| false;', MODULE),
    ('a form designated at no event offered a reference no event checked', UV,
     "        if (!$arms) return null;    // $form at no event: no event can check a candidate\n", '', MODULE),
    ('a project of one event tries nothing', UV,
     "            $tokens[] = null;\n", '', MODULE),
    ('the last instance offered first for the field itself', UV,
     "foreach ($f === $name ? [null, 'previous-instance', 'last-instance'] : [null, 'last-instance', 'previous-instance'] as $s)",
     "foreach ([null, 'last-instance', 'previous-instance'] as $s)", MODULE),
    ('the next event never tried', UV,
     ", ['next-event-name']);", ', []);', MODULE),
    ('the refusal says no other reference exists', UV,
     """' — and none of the references to "[' . $f . ']" this check tried ('""", """' — and no other reference to "[' . $f . ']" was found ('""", MODULE),
    ("the field hook's self refusal suggests nothing", UV,
     ". $this->deltaSelfAdvice($op, $name, $meta, $pid) . '.'", ". '.'", MODULE),
    ("the field hook's self refusal suggests a shorthand the events refuse", UV,
     'if (self::deltaShorthandProblem($shape, $short, $form, $name) === null && self::deltaShorthandUsable($shape, $short, $form)) {',
     'if (self::deltaShorthandUsable($shape, $short, $form)) {', MODULE),
    ("the field hook's self refusal suggests a shorthand that finds nothing", UV,
     'if (self::deltaShorthandProblem($shape, $short, $form, $name) === null && self::deltaShorthandUsable($shape, $short, $form)) {',
     'if (self::deltaShorthandProblem($shape, $short, $form, $name) === null) {', MODULE),
    ("the field hook's self refusal forgets references are needed", UV,
     "                return ', as \"vs\":\"' . $short . '\"' . $needs;", "                return ', as \"vs\":\"' . $short . '\"';", MODULE),
    ('a field compared with this same entry told no other entry', UV,
     '                $c = self::deltaSuggestion($shape, $op, $f, $form, $vsForm, $name, false);', '                $c = null;', MODULE),
    ("a branch's own test ignores a configuration error", ENGINE,
     'test: function(v, isFinal){ return configError ? null : judge(V, v, isFinal); } }; }),', 'test: function(v, isFinal){ return judge(V, v, isFinal); } }; }),', DOM),
    ("an ID check's test ignores a configuration error", ENGINE,
     'test: function(v, isFinal){ return configError ? null : judge(V0, given(arguments), isFinal); } });', 'test: function(v, isFinal){ return judge(V0, given(arguments), isFinal); } });', DOM),
    ('test() judges a part-typed ID as still being typed', ENGINE,
     '    return V.verdict(v, isFinal !== false);', '    return V.verdict(v, !!isFinal);', DOM),
    ('the encoding stub tells a survey why', UV,
     '$rules[$i] = $survey ? ModeRegistry::withoutSurveyTexts($stub) : $stub;', '$rules[$i] = $stub;', TEMPORAL_INT),
    ('test(v) asks the modes that read the page', ENGINE,
     'if(given && e.takesValue !== true) continue;', 'if(false) continue;', DOM),
    ('a pooled list in a shared entry read as passing', ENGINE,
     'if(typeof e.test !== "function"){ open = true; continue; }', 'if(typeof e.test !== "function") continue;', DOM),
    ("test() hands an ID check no value", ENGINE,
     'function given(args){ return args.length > 0 ? args[0] : QRID_WHEN.readRef(f, null); }', 'function given(args){ return args[0]; }', DOM),
    ("an ID check not marked as taking a value", ENGINE,
     'QRID_registerValidator(f, { type: "single", takesValue: true,', 'QRID_registerValidator(f, { type: "single",', DOM),
    ("a branched ID check not marked as taking a value", ENGINE,
     'QRID_registerValidator(f, { type: "single", branch: true, takesValue: true,', 'QRID_registerValidator(f, { type: "single", branch: true,', DOM),
    ('a branched ID check answers with no branch active', ENGINE,
     'return (configError || act.length !== 1) ? null : judge(act[0], given(arguments), isFinal);',
     'return configError ? null : judge(act[0] || VS.all[0], given(arguments), isFinal);', DOM),
    ('a blank ID field judged', ENGINE,
     '    if(!v || QRID_isMissingCode(v)) return null;\n    if(v.length > QRID_MAX_SINGLE_LEN)', '    if(QRID_isMissingCode(v)) return null;\n    if(v.length > QRID_MAX_SINGLE_LEN)', DOM),
    ('a value too long for an ID field judged by its format', ENGINE,
     '    if(v.length > QRID_MAX_SINGLE_LEN) return { ok: false };\n', '', DOM),
    ('the slow trim back in the Missing Data Code check', ENGINE,
     '    var s = String(value), i = 0, j = s.length;\n    function ws(c){ return c === 32 || c === 9 || c === 13 || c === 10 || c === 0 || c === 11; }\n'
     '    while(i < j && ws(s.charCodeAt(i))) i++;\n    while(j > i && ws(s.charCodeAt(j - 1))) j--;\n    var t = s.slice(i, j);',
     '    var t = String(value).replace(/^[ \\t\\r\\n\\0\\x0B]+|[ \\t\\r\\n\\0\\x0B]+$/g, "");', DOM),
    ('a survey told why the growth table cap deferred a rule', UV,
     "        if ($context === 'survey') {\n            foreach ($config['rules'] as $i => $r) {\n                if (is_array($r)) $config['rules'][$i] = ModeRegistry::withoutSurveyTexts($r);",
     "        if (false) {\n            foreach ($config['rules'] as $i => $r) {\n                if (is_array($r)) $config['rules'][$i] = ModeRegistry::withoutSurveyTexts($r);", GROWTH_MODULE),
    ('a survey told a @UVDELTA configuration error word for word', MODEREG,
     "'configError' => self::surveyShape()['configErrorText'],",
     "'configError' => $r['configError'],", REGISTRY),
    ('a survey sent the deferral texts', MODEREG,
     "array_merge(self::serverKeys(), self::staffOnlyKeys(), self::surveyShape()['drop'])",
     'array_merge(self::serverKeys(), self::staffOnlyKeys())', REGISTRY),
    ('the shared entry drops the value given to test(v)', ENGINE,
     'r = given ? e.test.apply(e, args) :',
     'r = given ? e.test() :', DOM),
    ("the shared entry misreads an ID check's {ok}", ENGINE,
     '        if(r && typeof r === "object" && typeof r.ok === "boolean") r = r.ok;\n', '', DOM),
    ("the shared entry keeps one mode's keys", ENGINE,
     'for(k in byMode[t]) if(Object.prototype.hasOwnProperty.call(byMode[t], k) && !Object.prototype.hasOwnProperty.call(out, k)) out[k] = byMode[t][k];',
     'for(k in byMode[t]) if(Object.prototype.hasOwnProperty.call(byMode[t], k) && !out.type) out[k] = byMode[t][k];', DOM),
    ('a note held while typing in a box the rule does not read', ENGINE,
     '|| typeof field !== "string" || a.name !== field){ check(); return; }', '){ check(); return; }', DOM),
    ('the changed field not handed to its watchers', ENGINE,
     '      try { cbs[i](f); } catch(e){}', '      try { cbs[i](); } catch(e){}', DOM),
    ('the percent limit broken but read at the limit', ENGINE,
     '    if(!broke && V.spec.maxPct !== null){', '    if(false){', DOM),
    ("the earlier value written with the field's decimal mark", ENGINE,
     'var from = shown(earlier, V.spec.earlierComma) + unit', 'var from = shown(earlier, V.spec.decimalComma) + unit', DOM),
    ('@UVASSERT: a deferral this viewer meets said to go unchecked', ENGINE,
     'QRID_renderDeferralNotice(msg, input, V.deferredWhy, "c", V.checkedOnSave === true);',
     'QRID_renderDeferralNotice(msg, input, V.deferredWhy, "c", false);', DOM),
    ('@UVWINDOW: a deferral this viewer meets said to go unchecked', ENGINE,
     'QRID_renderDeferralNotice(msg, input, V.deferredWhy, "w", V.checkedOnSave === true);',
     'QRID_renderDeferralNotice(msg, input, V.deferredWhy, "w", false);', DOM),
    ('@UVREQUIRED: a deferral this viewer meets said to go unchecked', ENGINE,
     'QRID_renderDeferralNotice(msg, input, V.deferredWhy, "r", V.checkedOnSave === true);',
     'QRID_renderDeferralNotice(msg, input, V.deferredWhy, "r", false);', DOM),
    ('@UVEXISTS: a deferral this viewer meets said to go unchecked', ENGINE,
     'QRID_renderDeferralNotice(msg, input, V.deferredWhy, "x", V.checkedOnSave === true);',
     'QRID_renderDeferralNotice(msg, input, V.deferredWhy, "x", false);', DOM),
    ('@UVRANGE: a growth input judged per keystroke', ENGINE,
     'VS.all[gi].watch[wi].onChange(later);\n    }\n    check();\n    opening = false;',
     'VS.all[gi].watch[wi].onChange(function(){ check(); });\n    }\n    check();\n    opening = false;', GROWTH_DOM),
    ('the slow trim back in @UVREQUIRED', ENGINE,
     'var blank = QRID_whenTrim(String(QRID_WHEN.readRef(fieldName, null))) === "";',
     'var blank = String(QRID_WHEN.readRef(fieldName, null)).replace(/^[ \\t\\r\\n]+|[ \\t\\r\\n]+$/g, "") === "";', REQUIRED_DOM),
    ('the slow trim back in @UVCHOICES', ENGINE,
     '        var v = QRID_whenTrim(String(QRID_WHEN.readRef(f, null)));',
     '        var v = String(QRID_WHEN.readRef(f, null)).replace(/^[ \\t\\r\\n]+|[ \\t\\r\\n]+$/g, "");', CHOICES_DOM),
]


def run(suite, cwd):
    cmd = [NODE, suite] if suite.endswith('.cjs') else [PHP, suite]
    return subprocess.run(cmd, cwd=cwd, capture_output=True, text=True, encoding='utf-8', errors='replace')


def main():
    for suite in sorted({m[-1] for m in MUTATIONS}):
        res = run(suite, ROOT)
        if res.returncode != 0:
            sys.exit(f'{suite} fails before any mutation:\n{res.stdout[-2000:]}{res.stderr[-2000:]}')
    survived = []
    with tempfile.TemporaryDirectory(prefix='uv-delta-mutations-') as directory:
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
            if result.returncode == 0:
                survived.append(name)
                print(f'SURVIVED: {name} ({suite})')
            else:
                print(f'killed: {name}')
    if survived:
        sys.exit(f'delta_mutations: {len(survived)} of {len(MUTATIONS)} mutations survived')
    print(f'delta_mutations: {len(MUTATIONS)} mutations, every one killed')


if __name__ == '__main__':
    main()
