<?php
/**
 * Branching — branched validation: several conditional rules on one field.
 *
 * Since 0.9.0 a field may be claimed by MORE THAN ONE rule, provided the
 * sharing is gated: every sharing rule carries a "when" condition, except at
 * most ONE rule without a condition, which becomes the ELSE branch. This
 * class rewrites such sharing into explicit per-field BRANCH rules at
 * config-build time, so the client engine, the server audit, and the
 * saved-value snapshot all consume ONE structure and can never disagree
 * about what sharing means.
 *
 * This docblock is the NORMATIVE branch semantics spec (the client mirrors
 * it; tests/branching_php.php and tests/branch_dom_js.cjs implement the same
 * scenario table):
 *
 *   - Branch rule shape: { type, fields: [ONE field], branches: [ ... ] }.
 *     Each branch is a SPARSE copy of its source rule's per-rule keys
 *     (BRANCH_KEYS below) plus "when" (string, or null for the else branch).
 *   - Branch order = source-rule order (dialog rules first, then annotation
 *     rules — getRules() order); the else branch is forced LAST.
 *   - Runtime resolution: active = conditional branches whose "when" is true;
 *     none active and an else exists => the else is active; exactly one
 *     active => validate under it; none => the field is inert; MORE than one
 *     => a branch CONFLICT — show/log a configuration-style problem, validate
 *     nothing, and never block a save (the else can never conflict).
 *   - Config-time legality (a violation becomes a per-field configError rule
 *     instead of a branch rule): at most one sharing rule without "when";
 *     no two sharing rules with byte-identical "when" strings (they could
 *     never be told apart); all sharing rules must have the same type
 *     (single/pooled).
 *
 * Fields claimed by exactly one rule pass through UNTOUCHED — resolve() is
 * the identity for rule lists without sharing, so single-rule projects take
 * the exact code paths they took before 0.9.0.
 *
 * Pure static class with no REDCap dependency, unit-tested by
 * tests/branching_php.php; UniversalValidator::getRules() is the caller.
 */

namespace INSPIRE\UniversalValidator;

class Branching
{
    /**
     * Per-rule keys a branch inherits from its source rule (sparse copy —
     * only keys the rule actually carries). "when" is handled separately
     * (null marks the else branch); "fields"/"type"/"configError" never
     * belong to a branch.
     */
    const BRANCH_KEYS = ['algorithm', 'idPattern', 'source', 'strip', 'keepChars',
                         'idLengths', 'idMinLen', 'idMaxLen', 'expectedIds',
                         'blockSave', 'suggestFix', 'note',
                         // constraint mode (@UVASSERT)
                         'assert', 'message',
                         // unique mode (@UVUNIQUE)
                         'uniqueWith', 'uniqueScope', 'uniqueSurveys',
                         // choices mode (@UVCHOICES) — choicesAll travels on
                         // every branch (identical per field, attached from the
                         // dd) so a flattened branch is self-contained.
                         'choicesShow', 'choicesHide', 'choicesAll'];

    /**
     * The validation MODE a rule's "type" belongs to. Different modes on one
     * field COMPOSE (a check rule and a constraint rule both attach and both
     * must pass); several rules of the SAME mode on one field branch. So all
     * field-sharing logic below groups claims by (field, mode), and the client
     * dispatcher + server duplicate guard must key on the same pair.
     * single|pooled are two TYPES of the one "check" mode (their single-vs-
     * pooled clash stays a mixed-type conflict within that mode).
     */
    public static function modeOfType($type)
    {
        switch ($type) {
            case 'constraint': return 'constraint';
            case 'required':   return 'required';
            case 'unique':     return 'unique';
            case 'choices':    return 'choices';
            default:           return 'check'; // single | pooled | '' | unknown
        }
    }

    /**
     * Illegal sharing per field: field => ['kind' => 'two-unconditional' |
     * 'identical-when' | 'mixed-type', 'rules' => [ruleIndex, ...],
     * 'detail' => count|whenString|[typeA, typeB]]. Fields whose sharing is
     * legal (or not shared at all) are absent. Rule indexes refer to the
     * INPUT list, so callers can name "Rule 2 and Rule 5" in messages.
     */
    public static function fieldConflicts(array $rules)
    {
        $out = [];
        foreach (self::fieldClaims($rules) as $field => $byMode) {
            foreach ($byMode as $idxs) {
                if (count($idxs) < 2) continue;
                $conflict = self::conflictOf($rules, $field, $idxs);
                if ($conflict !== null && !isset($out[$field])) $out[$field] = $conflict;
            }
        }
        return $out;
    }

    /**
     * Rewrite a combined rule list: illegal sharing becomes per-field
     * configError rules, legal sharing becomes per-field branch rules, and
     * shared fields are removed from their source rules (a rule left with no
     * fields is dropped). No sharing => the input is returned unchanged.
     */
    public static function resolve(array $rules)
    {
        $sharedGroups = [];  // list of ['field'=>f, 'idxs'=>[...]] — one per (field, mode)
        foreach (self::fieldClaims($rules) as $field => $byMode) {
            foreach ($byMode as $idxs) {
                if (count($idxs) > 1) $sharedGroups[] = ['field' => $field, 'idxs' => $idxs];
            }
        }
        if (!$sharedGroups) return $rules;

        $removeFrom = [];  // ruleIndex => [field => true]
        $extra = [];       // synthesized per-field rules, appended in field order
        foreach ($sharedGroups as $grp) {
            $field = $grp['field'];
            $idxs  = $grp['idxs'];
            foreach ($idxs as $i) $removeFrom[$i][$field] = true;
            $conflict = self::conflictOf($rules, $field, $idxs);
            if ($conflict !== null) {
                $extra[] = [
                    'type'        => 'single',
                    'fields'      => [$field],
                    'configError' => self::message($field, $conflict),
                ];
                continue;
            }
            $branches = [];
            $else = null;
            foreach ($idxs as $i) {
                $b = self::branchOf($rules[$i]);
                if ($b['when'] === null) $else = $b;
                else $branches[] = $b;
            }
            if ($else !== null) $branches[] = $else;   // else is always LAST
            $first = $rules[$idxs[0]];
            $extra[] = [
                'type'     => (isset($first['type']) && $first['type'] !== '') ? $first['type'] : 'single',
                'fields'   => [$field],
                'branches' => $branches,
            ];
        }

        $out = [];
        foreach ($rules as $i => $r) {
            if (!isset($removeFrom[$i])) { $out[] = $r; continue; }
            $fields = [];
            foreach ((array) $r['fields'] as $f) {
                if (!isset($removeFrom[$i][$f])) $fields[] = $f;
            }
            if (!$fields) continue;   // every field moved into branch rules
            $r['fields'] = $fields;
            $out[] = $r;
        }
        foreach ($extra as $r) $out[] = $r;
        return $out;
    }

    /** The user-facing message for one fieldConflicts() entry. */
    public static function message($field, array $conflict)
    {
        switch ($conflict['kind']) {
            case 'two-unconditional':
                return 'field "' . $field . '" is covered by ' . $conflict['detail']
                    . ' rules with no "when" condition — at most ONE unconditional rule may share a '
                    . 'field (it becomes the fallback). Add "when" conditions or remove the extra rule(s).';
            case 'identical-when':
                return 'field "' . $field . '" is covered by 2 rules with the identical condition "'
                    . $conflict['detail'] . '" — the branches could never be told apart. '
                    . 'Make the conditions differ or merge the rules.';
            case 'mixed-type':
                return 'field "' . $field . '" is covered by both a single-value rule and a pooled rule '
                    . '— all rules sharing a field must have the same field type.';
        }
        return 'field "' . $field . '" has conflicting rules.';   // unreachable
    }

    // -- internals ------------------------------------------------------------

    /**
     * field => [mode => [ruleIndex, ...]] over live (non-configError) rules.
     * Claims are split BY MODE so a check rule and a constraint rule on the
     * same field are separate groups (they compose, they do not branch); only
     * two rules of the SAME mode on one field are a sharing group. A field
     * listed twice inside ONE rule counts once here — the pre-existing
     * duplicate guards still flag that separately.
     */
    private static function fieldClaims(array $rules)
    {
        $claims = [];
        foreach ($rules as $i => $r) {
            if (!is_array($r) || !empty($r['configError'])) continue;
            if (empty($r['fields']) || !is_array($r['fields'])) continue;
            $mode = self::modeOfType(isset($r['type']) ? $r['type'] : '');
            $seen = [];
            foreach ($r['fields'] as $f) {
                if (!is_string($f) || $f === '' || isset($seen[$f])) continue;
                $seen[$f] = true;
                $claims[$f][$mode][] = $i;
            }
        }
        return $claims;
    }

    /** The rule's trimmed "when" string, or null when it has none. */
    private static function whenOf(array $rule)
    {
        if (!isset($rule['when']) || !is_string($rule['when'])) return null;
        $w = trim($rule['when']);
        return $w === '' ? null : $w;
    }

    /**
     * Legality of one shared field; null when the sharing is legal. When a
     * field violates several rules at once, ONE conflict is reported, in
     * fixed priority order: two-unconditional, identical-when, mixed-type.
     */
    private static function conflictOf(array $rules, $field, array $idxs)
    {
        $unconditional = [];
        $whens = [];
        $identical = null;
        $types = [];
        foreach ($idxs as $i) {
            $w = self::whenOf($rules[$i]);
            if ($w === null) {
                $unconditional[] = $i;
            } elseif (isset($whens[$w])) {
                if ($identical === null) {
                    $identical = ['kind' => 'identical-when', 'rules' => [$whens[$w], $i], 'detail' => $w];
                }
            } else {
                $whens[$w] = $i;
            }
            $t = (isset($rules[$i]['type']) && $rules[$i]['type'] !== '') ? $rules[$i]['type'] : 'single';
            $types[$t] = $i;
        }
        if (count($unconditional) >= 2) {
            return ['kind' => 'two-unconditional', 'rules' => $unconditional, 'detail' => count($unconditional)];
        }
        if ($identical !== null) return $identical;
        if (count($types) > 1) {
            return ['kind' => 'mixed-type', 'rules' => array_values($types), 'detail' => array_keys($types)];
        }
        return null;
    }

    /** Sparse branch copy of one source rule (per-rule keys + when|null). */
    private static function branchOf(array $rule)
    {
        $b = [];
        foreach (self::BRANCH_KEYS as $k) {
            if (isset($rule[$k])) $b[$k] = $rule[$k];
        }
        $b['when'] = self::whenOf($rule);
        return $b;
    }
}
