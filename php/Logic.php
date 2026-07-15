<?php
/**
 * Logic — the "when" condition dialect (a REDCap-STYLE logic subset).
 *
 * A validation rule may carry an optional `when` condition; the rule validates
 * only while the condition is true. This class is the NORMATIVE SPEC of the
 * dialect: the JavaScript twin in js/engine.js (QRID_when* block) must stay
 * behavior-identical, and tests/when_js.cjs + tests/when_php.php prove it by
 * driving both against the shared hand-curated tests/when_fixture.json.
 *
 * The dialect is deliberately a SUBSET of REDCap's logic language — documented
 * as "REDCap-style, not byte-for-byte REDCap":
 *
 *   grammar     or  := and ( OR and )*
 *               and := not ( AND not )*
 *               not := NOT not | primary
 *               primary := '(' or ')' | comparison
 *               comparison := operand op operand        (a bare [field] is an error)
 *               operand := [field] | [field(code)] | 'string' | "string" | number
 *   operators   =  <>  !=  >  <  >=  <=      (!= is canonicalized to <>)
 *   keywords    and / or / not, case-insensitive, word-bounded
 *   refs        [a-z][a-z0-9_]* (lowercased); optional (code) of [A-Za-z0-9._-]
 *   strings     no escape sequences — a quote of the same kind ends the string
 *   numbers     -?[0-9]+(\.[0-9]+)?
 *   whitespace  tab/CR/LF are treated as spaces; expression must be printable
 *               ASCII (0x20-0x7E) after that normalization
 *
 * NOT supported (each a parse error, so a rule saves with a clear message
 * instead of misbehaving): functions (datediff, ...), smart variables,
 * [event][field] prefixes, arithmetic, piping.
 *
 * Evaluation semantics:
 * Where conditions are EVALUATED (privacy-relevant, see fold()): a condition
 * may reference fields that are not on the page being rendered. Their values
 * are never sent to the browser — the server folds every comparison that
 * needs one into a constant before the condition is injected, so the page
 * carries field names, the designer's own literals, and booleans, but no
 * record data.
 *
 * Evaluation semantics:
 *   - a missing or empty field value resolves to '' (the server value reader
 *     drops empties, so "missing" and "empty" are the same thing by design);
 *   - a checkbox ref [f(code)] resolves to '1' when that code is checked,
 *     otherwise '0' (field absent from the value map counts as unchecked);
 *   - comparison is numeric (as floats) iff BOTH resolved sides match
 *     ^[+-]?([0-9]+(\.[0-9]*)?|\.[0-9]+)$ after ASCII trimming — deliberately
 *     no exponents or hex, where PHP and JavaScript number parsing diverge —
 *     otherwise an exact, case-sensitive string comparison (strcmp ordering;
 *     identical to JavaScript's relational operators on ASCII).
 *
 * Caps (parse errors beyond): MAX_EXPR_LEN chars, MAX_REFS field references,
 * MAX_DEPTH nesting levels (parentheses + not).
 *
 * Pure static class with no REDCap dependency, unit-tested by
 * tests/when_php.php; UniversalValidator and AnnotationRules are the glue.
 */

namespace INSPIRE\UniversalValidator;

class Logic
{
    const MAX_EXPR_LEN = 500; // characters, after whitespace normalization + trim
    const MAX_REFS     = 20;  // [field] references, counted before de-duplication
    const MAX_DEPTH    = 10;  // nesting levels: parentheses and "not"

    // Numeric-ness of a RESOLVED value (not a lexer rule): both sides must
    // match for a comparison to be numeric. No exponents, no hex, no leading
    // "0x" — PHP and JavaScript disagree about those, printable digits do not.
    const NUM_RE = '/^[+-]?([0-9]+(\.[0-9]*)?|\.[0-9]+)$/';

    /**
     * Parse one condition. Returns ['ok'=>true, 'ast'=>array] or
     * ['ok'=>false, 'error'=>string]. Error strings are subject-less predicates
     * ("is limited to...", "uses the function...") so callers can prefix
     * 'the "when" condition ' and read naturally.
     *
     * AST shape (internal — the fixture locks behavior, never this shape):
     *   ['or', [child, ...]] | ['and', [child, ...]] | ['not', child]
     *   | ['cmp', op, operand, operand] | ['const', bool]
     *   operand: ['ref', field, codeOrNull] | ['lit', string]
     * parse() never produces a 'const' node — fold() does (see there).
     */
    public static function parse($expr)
    {
        if (!is_string($expr)) return self::err('must be a non-empty condition string.');
        $s = strtr($expr, "\t\r\n", '   ');
        $s = trim($s, ' ');
        if ($s === '') return self::err('must be a non-empty condition string.');
        if (preg_match('/[^\x20-\x7E]/', $s)) {
            return self::err('must contain printable ASCII only.');
        }
        if (strlen($s) > self::MAX_EXPR_LEN) {
            return self::err('is limited to ' . self::MAX_EXPR_LEN . ' characters.');
        }
        $lex = self::lex($s);
        if (isset($lex['error'])) return self::err($lex['error']);
        $st = ['t' => $lex['tokens'], 'p' => 0];
        $r = self::parseOr($st, 0);
        if (isset($r['error'])) return self::err($r['error']);
        if ($st['p'] < count($st['t'])) {
            return self::err('has unexpected content after a complete condition — combine comparisons with "and"/"or".');
        }
        return ['ok' => true, 'ast' => $r['node']];
    }

    /**
     * Evaluate a parsed condition against a value map (field => string, or
     * field => [code => '0'|'1'] for checkboxes). Missing fields resolve to ''
     * (checkbox refs to '0'), so the caller may pass a sparse map.
     */
    public static function evaluate(array $ast, array $values)
    {
        switch ($ast[0]) {
            case 'const':
                return !empty($ast[1]);
            case 'or':
                foreach ($ast[1] as $c) { if (self::evaluate($c, $values)) return true; }
                return false;
            case 'and':
                foreach ($ast[1] as $c) { if (!self::evaluate($c, $values)) return false; }
                return true;
            case 'not':
                return !self::evaluate($ast[1], $values);
            case 'cmp':
                return self::compare(
                    $ast[1],
                    self::operandValue($ast[2], $values),
                    self::operandValue($ast[3], $values)
                );
        }
        return false; // unreachable for parse()-produced ASTs
    }

    /**
     * The unique [field]/[field(code)] references of a parsed condition, as
     * [[field, codeOrNull], ...] in first-appearance order. De-duplication is
     * per (field, code) pair — [cb(2)] and [cb(3)] are two entries.
     */
    public static function referencedFields(array $ast)
    {
        $out = [];
        $seen = [];
        self::collectRefs($ast, $out, $seen);
        return $out;
    }

    /**
     * Partially evaluate a condition for ONE rendered page, so no record value
     * ever has to be sent to the browser (SEC-005).
     *
     * $liveFields is the set (field => true) of fields the browser can read on
     * this page — the fields of the instrument being rendered. A comparison
     * whose references are ALL live is left alone: the browser reads those
     * fields from the form and re-evaluates as the user edits them. Every
     * OTHER comparison — one that needs a field the browser cannot see, and
     * therefore cannot change while the page is open — is evaluated here
     * against $values and replaced by ['const', bool].
     *
     * The result carries field names, the designer's own literals, and
     * booleans; the referenced records' values stay on the server. What a
     * folded constant still reveals is one bit ("this comparison held"), which
     * the feature inherently reveals anyway by validating or not.
     *
     * A comparison that MIXES a live and a non-live reference is folded whole
     * (it cannot be resolved in the browser at all): it is then correct as of
     * page load but does not react live — put both fields on one instrument
     * for live reaction.
     */
    public static function fold(array $ast, array $values, array $liveFields)
    {
        switch ($ast[0]) {
            case 'or':
            case 'and':
                $out = [];
                foreach ($ast[1] as $c) $out[] = self::fold($c, $values, $liveFields);
                return [$ast[0], $out];
            case 'not':
                return ['not', self::fold($ast[1], $values, $liveFields)];
            case 'cmp':
                $refs = 0;
                $live = 0;
                foreach ([$ast[2], $ast[3]] as $op) {
                    if ($op[0] !== 'ref') continue;
                    $refs++;
                    if (isset($liveFields[$op[1]])) $live++;
                }
                // all references readable in the browser -> keep it live
                if ($refs > 0 && $refs === $live) return $ast;
                // otherwise the browser could never resolve it: settle it here
                return ['const', self::evaluate($ast, $values)];
        }
        return $ast;   // 'const' (already folded) and anything unknown
    }

    /**
     * Dictionary-dependent reference checks, shared by every configuration
     * channel that has the data dictionary at hand. $types is field => REDCap
     * field type; $choicesByField is checkboxField => [codes] (absent field =
     * codes unknown, membership not checked). Returns full-sentence error
     * strings, [] when sound.
     */
    public static function checkRefs(array $ast, array $types, array $choicesByField)
    {
        $errors = [];
        foreach (self::referencedFields($ast) as $ref) {
            $f = $ref[0];
            $code = $ref[1];
            if (!array_key_exists($f, $types)) {
                $errors[] = 'the "when" condition references "[' . $f . ']", which is not a field in this project.';
                continue;
            }
            $type = (string) $types[$f];
            if ($type === 'file' || $type === 'descriptive') {
                $errors[] = 'the "when" condition cannot reference "[' . $f . ']" — file and descriptive fields have no comparable value.';
                continue;
            }
            if ($type === 'checkbox') {
                $codes = (isset($choicesByField[$f]) && is_array($choicesByField[$f])) ? $choicesByField[$f] : [];
                if ($code === null) {
                    $errors[] = 'in the "when" condition, "[' . $f . ']" is a checkbox — reference one option as ['
                        . $f . '(code)]' . ($codes ? '; its codes are: ' . implode(', ', $codes) . '.' : '.');
                } elseif ($codes && !in_array((string) $code, array_map('strval', $codes), true)) {
                    $errors[] = 'in the "when" condition, checkbox "' . $f . '" has no choice code "' . $code
                        . '" — its codes are: ' . implode(', ', $codes) . '.';
                }
                continue;
            }
            if ($code !== null) {
                $errors[] = 'in the "when" condition, "[' . $f . '(' . $code . ')]": only checkbox fields take a (code).';
            }
        }
        return $errors;
    }

    /**
     * Choice codes of a REDCap select_choices_or_calculations string
     * ("1, Yes | 2, No" => ['1', '2']). A part without a comma is taken whole;
     * empty parts are skipped. Callers should only feed this checkbox rows —
     * for calc fields the column holds an equation, not choices.
     */
    public static function parseChoiceCodes($raw)
    {
        if (!is_string($raw) || trim($raw) === '') return [];
        $codes = [];
        foreach (explode('|', $raw) as $part) {
            $comma = strpos($part, ',');
            $code = trim($comma === false ? $part : substr($part, 0, $comma));
            if ($code !== '') $codes[] = $code;
        }
        return $codes;
    }

    // -- internals ------------------------------------------------------------

    private static function err($msg)
    {
        return ['ok' => false, 'error' => $msg];
    }

    /**
     * Tokenize a normalized expression. Returns ['tokens'=>[...]] or
     * ['error'=>string]. Tokens: ['ref', field, codeOrNull] | ['lit', string]
     * | ['op', op] | ['kw', and|or|not] | ['('] | [')'].
     */
    private static function lex($s)
    {
        $tokens = [];
        $n = strlen($s);
        $refs = 0;
        $i = 0;
        while ($i < $n) {
            $ch = $s[$i];
            if ($ch === ' ') { $i++; continue; }
            if ($ch === '[') {
                if (!preg_match('/^\[([A-Za-z][A-Za-z0-9_]*)(\(([A-Za-z0-9._-]+)\))?\]/', substr($s, $i), $m)) {
                    return ['error' => 'may only use plain [field] or [field(code)] references — '
                        . 'no [event][field] prefixes, smart variables, or empty brackets.'];
                }
                $i += strlen($m[0]);
                if ($i < $n && $s[$i] === '[') {
                    return ['error' => 'may only use plain [field] or [field(code)] references — '
                        . 'no [event][field] prefixes, smart variables, or empty brackets.'];
                }
                $refs++;
                if ($refs > self::MAX_REFS) {
                    return ['error' => 'uses more than ' . self::MAX_REFS . ' field references.'];
                }
                $tokens[] = ['ref', strtolower($m[1]), (isset($m[3]) && $m[3] !== '') ? $m[3] : null];
                continue;
            }
            if ($ch === "'" || $ch === '"') {
                $end = strpos($s, $ch, $i + 1);
                if ($end === false) return ['error' => 'has an unterminated quoted string.'];
                $tokens[] = ['lit', substr($s, $i + 1, $end - $i - 1)];
                $i = $end + 1;
                continue;
            }
            if ($ch === '-' || ctype_digit($ch)) {
                if (preg_match('/^-?[0-9]+(\.[0-9]+)?/', substr($s, $i), $m)) {
                    $tokens[] = ['lit', $m[0]]; // numbers are literals; numeric-ness is decided at compare time
                    $i += strlen($m[0]);
                    continue;
                }
                return ['error' => 'contains an unexpected character "' . $ch . '".'];
            }
            $two = substr($s, $i, 2);
            if ($two === '<>' || $two === '<=' || $two === '>=') { $tokens[] = ['op', $two]; $i += 2; continue; }
            if ($two === '!=') { $tokens[] = ['op', '<>']; $i += 2; continue; } // canonicalized
            if ($ch === '=') { $tokens[] = ['op', '=']; $i++; continue; }
            if ($ch === '<' || $ch === '>') { $tokens[] = ['op', $ch]; $i++; continue; }
            if ($ch === '(') { $tokens[] = ['(']; $i++; continue; }
            if ($ch === ')') { $tokens[] = [')']; $i++; continue; }
            if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*/', substr($s, $i), $m)) {
                $w = strtolower($m[0]);
                $i += strlen($m[0]);
                if ($w === 'and' || $w === 'or' || $w === 'not') { $tokens[] = ['kw', $w]; continue; }
                $j = $i;
                while ($j < $n && $s[$j] === ' ') $j++;
                if ($j < $n && $s[$j] === '(') {
                    return ['error' => 'uses the function "' . $m[0] . '(...)", which is not supported — only '
                        . '[field] or [field(code)] comparisons combined with and/or/not and parentheses.'];
                }
                return ['error' => 'contains the bare word "' . $m[0] . '" — field references are written '
                    . 'in [brackets], text values in quotes.'];
            }
            return ['error' => 'contains an unexpected character "' . $ch . '".'];
        }
        return ['tokens' => $tokens];
    }

    /** or := and ( OR and )*  — returns ['node'=>...] or ['error'=>...]. */
    private static function parseOr(array &$st, $depth)
    {
        $r = self::parseAnd($st, $depth);
        if (isset($r['error'])) return $r;
        $children = [$r['node']];
        while (self::peekKw($st, 'or')) {
            $st['p']++;
            $r = self::parseAnd($st, $depth);
            if (isset($r['error'])) return $r;
            $children[] = $r['node'];
        }
        return ['node' => count($children) === 1 ? $children[0] : ['or', $children]];
    }

    /** and := not ( AND not )* */
    private static function parseAnd(array &$st, $depth)
    {
        $r = self::parseNot($st, $depth);
        if (isset($r['error'])) return $r;
        $children = [$r['node']];
        while (self::peekKw($st, 'and')) {
            $st['p']++;
            $r = self::parseNot($st, $depth);
            if (isset($r['error'])) return $r;
            $children[] = $r['node'];
        }
        return ['node' => count($children) === 1 ? $children[0] : ['and', $children]];
    }

    /** not := NOT not | primary — "not" adds a nesting level. */
    private static function parseNot(array &$st, $depth)
    {
        if (self::peekKw($st, 'not')) {
            if ($depth + 1 > self::MAX_DEPTH) {
                return ['error' => 'is nested more than ' . self::MAX_DEPTH . ' levels deep.'];
            }
            $st['p']++;
            $r = self::parseNot($st, $depth + 1);
            if (isset($r['error'])) return $r;
            return ['node' => ['not', $r['node']]];
        }
        return self::parsePrimary($st, $depth);
    }

    /** primary := '(' or ')' | comparison — parentheses add a nesting level. */
    private static function parsePrimary(array &$st, $depth)
    {
        $t = self::peek($st);
        if ($t !== null && $t[0] === '(') {
            if ($depth + 1 > self::MAX_DEPTH) {
                return ['error' => 'is nested more than ' . self::MAX_DEPTH . ' levels deep.'];
            }
            $st['p']++;
            $r = self::parseOr($st, $depth + 1);
            if (isset($r['error'])) return $r;
            $t = self::peek($st);
            if ($t === null || $t[0] !== ')') return ['error' => 'has an unbalanced parenthesis.'];
            $st['p']++;
            return $r;
        }
        return self::parseCmp($st);
    }

    /** comparison := operand op operand */
    private static function parseCmp(array &$st)
    {
        $lhs = self::parseOperand($st);
        if (isset($lhs['error'])) return $lhs;
        $t = self::peek($st);
        if ($t === null || $t[0] !== 'op') {
            return ['error' => "must compare each [field] or value to something, e.g. [specimen_type]='2'."];
        }
        $op = $t[1];
        $st['p']++;
        $rhs = self::parseOperand($st);
        if (isset($rhs['error'])) return $rhs;
        return ['node' => ['cmp', $op, $lhs['node'], $rhs['node']]];
    }

    /** operand := ref | string | number */
    private static function parseOperand(array &$st)
    {
        $t = self::peek($st);
        if ($t === null) {
            return ['error' => 'ends where a [field] or quoted value was expected.'];
        }
        if ($t[0] === 'ref') { $st['p']++; return ['node' => ['ref', $t[1], $t[2]]]; }
        if ($t[0] === 'lit') { $st['p']++; return ['node' => ['lit', $t[1]]]; }
        $label = ($t[0] === 'op' || $t[0] === 'kw') ? $t[1] : $t[0];
        return ['error' => 'has "' . $label . '" where a [field] or quoted value was expected.'];
    }

    private static function peek(array $st)
    {
        return isset($st['t'][$st['p']]) ? $st['t'][$st['p']] : null;
    }

    private static function peekKw(array $st, $kw)
    {
        $t = self::peek($st);
        return $t !== null && $t[0] === 'kw' && $t[1] === $kw;
    }

    /** Resolve one operand against the value map (see evaluate()). */
    private static function operandValue(array $op, array $values)
    {
        if ($op[0] === 'lit') return $op[1];
        $f = $op[1];
        $code = $op[2];
        $v = isset($values[$f]) ? $values[$f] : null;
        if ($code !== null) {
            if (is_array($v)) return (isset($v[$code]) && (string) $v[$code] === '1') ? '1' : '0';
            return '0';
        }
        if ($v === null || is_array($v)) return ''; // checkbox without (code) never reaches here via config gates
        return (string) $v;
    }

    /** ASCII-whitespace trim + the numeric-or-string comparison from the spec. */
    private static function compare($op, $a, $b)
    {
        $a = trim((string) $a, " \t\r\n");
        $b = trim((string) $b, " \t\r\n");
        if (preg_match(self::NUM_RE, $a) && preg_match(self::NUM_RE, $b)) {
            $fa = (float) $a;
            $fb = (float) $b;
            switch ($op) {
                case '=':  return $fa == $fb;
                case '<>': return $fa != $fb;
                case '>':  return $fa > $fb;
                case '<':  return $fa < $fb;
                case '>=': return $fa >= $fb;
                case '<=': return $fa <= $fb;
            }
            return false;
        }
        switch ($op) {
            case '=':  return $a === $b;
            case '<>': return $a !== $b;
            case '>':  return strcmp($a, $b) > 0;
            case '<':  return strcmp($a, $b) < 0;
            case '>=': return strcmp($a, $b) >= 0;
            case '<=': return strcmp($a, $b) <= 0;
        }
        return false;
    }

    private static function collectRefs(array $ast, array &$out, array &$seen)
    {
        switch ($ast[0]) {
            case 'or':
            case 'and':
                foreach ($ast[1] as $c) self::collectRefs($c, $out, $seen);
                return;
            case 'not':
                self::collectRefs($ast[1], $out, $seen);
                return;
            case 'cmp':
                foreach ([$ast[2], $ast[3]] as $opnd) {
                    if ($opnd[0] === 'ref') {
                        $key = $opnd[1] . '|' . ($opnd[2] === null ? '' : '(' . $opnd[2] . ')');
                        if (!isset($seen[$key])) {
                            $seen[$key] = true;
                            $out[] = [$opnd[1], $opnd[2]];
                        }
                    }
                }
                return;
        }
    }
}
