<?php
/**
 * AnnotationRules — @UVALIDATE action-tag-style configuration.
 *
 * Lets a data manager attach validation where they already design fields: the
 * "Action Tags / Field Annotation" box in the Online Designer, or the
 * field_annotation column of a data dictionary CSV — which makes bulk
 * configuration a spreadsheet edit + one upload instead of clicking through the
 * module dialog per field. Three forms:
 *
 *   @UVALIDATE                        default check (ISO 7064 Mod 37,36), message only
 *   @UVALIDATE=iso7064_mod11_10       pick the check-character algorithm
 *   @UVALIDATE={"algorithm":"none","pattern":"FC[0-9]{4}","blockSave":"hard"}
 *                                     full rule as JSON
 *
 * JSON keys: type ("single"|"pooled"), algorithm, source, pattern, strip,
 * keepChars, idLengths (list or "10, 12"), idMinLen, idMaxLen, expectedIds,
 * blockSave ("off"|"confirm"|"hard"), note. A malformed value becomes a visible
 * per-field configuration error — never a silently skipped rule.
 *
 * Pure static class with no REDCap dependency, unit-tested by
 * tests/annotation_php.php; UniversalValidator::getAnnotationRules() is the thin
 * glue that feeds it the project's data dictionary.
 */

namespace INSPIRE\UniversalValidator;

require_once __DIR__ . '/CheckCharacter.php';

class AnnotationRules
{
    const TAG = '@UVALIDATE';

    const ALGORITHMS = [
        'iso7064_mod37_36', 'iso7064_mod11_10', 'iso7064_mod97_10',
        'iso7064_mod11_2', 'iso7064_mod37_2', 'iso7064_letters1',
        'iso7064_letters2', 'damm', 'verhoeff', 'luhn', 'none',
    ];

    /** Keys accepted in the JSON form ("pattern" maps to the engine's idPattern). */
    const JSON_KEYS = ['type', 'algorithm', 'source', 'pattern', 'strip', 'keepChars',
                       'idLengths', 'idMinLen', 'idMaxLen', 'expectedIds', 'blockSave', 'note'];

    /**
     * Extract the raw @UVALIDATE value from an annotation string.
     * Returns null when the tag is absent, '' for the bare tag, otherwise the
     * value text (bare token, 'quoted', "quoted", or a brace-balanced {...}).
     */
    public static function extractTag($annotation)
    {
        $ann = (string) $annotation;
        $pos = stripos($ann, self::TAG);
        while ($pos !== false) {
            $after = $pos + strlen(self::TAG);
            $ch = $after < strlen($ann) ? $ann[$after] : '';
            // a real tag ends at '=', whitespace, or end-of-string;
            // @UVALIDATED / @UVALIDATE2 are different tags — keep scanning.
            if ($ch === '' || $ch === '=' || ctype_space($ch)) break;
            $pos = stripos($ann, self::TAG, $after);
        }
        if ($pos === false) return null;
        $after = $pos + strlen(self::TAG);
        if ($after >= strlen($ann) || $ann[$after] !== '=') return '';
        return self::readValue(substr($ann, $after + 1));
    }

    /** Read one tag value: {json} (brace-balanced), quoted token, or bare token. */
    private static function readValue($rest)
    {
        if ($rest === '') return '';
        $c = $rest[0];
        if ($c === '{') {
            $depth = 0; $inStr = false; $esc = false;
            $n = strlen($rest);
            for ($i = 0; $i < $n; $i++) {
                $ch = $rest[$i];
                if ($inStr) {
                    if ($esc) { $esc = false; }
                    elseif ($ch === '\\') { $esc = true; }
                    elseif ($ch === '"') { $inStr = false; }
                    continue;
                }
                if ($ch === '"') { $inStr = true; continue; }
                if ($ch === '{') { $depth++; }
                elseif ($ch === '}') {
                    $depth--;
                    if ($depth === 0) return substr($rest, 0, $i + 1);
                }
            }
            return $rest; // unbalanced — parseField reports it as bad JSON
        }
        if ($c === '"' || $c === "'") {
            $end = strpos($rest, $c, 1);
            return $end === false ? substr($rest, 1) : substr($rest, 1, $end - 1);
        }
        preg_match('/^\S+/', $rest, $m);
        return $m ? $m[0] : '';
    }

    /**
     * Parse one field's annotation into a rule fragment (engine keys, without
     * 'fields'): [] means "all defaults", ['error' => msg] a visible config
     * error, null an untagged field.
     */
    public static function parseField($annotation)
    {
        $val = self::extractTag($annotation);
        if ($val === null) return null;
        $val = trim($val);
        if ($val === '') return [];
        if ($val[0] !== '{') {
            if (!in_array($val, self::ALGORITHMS, true)) {
                return ['error' => 'unknown check algorithm "' . $val . '" — valid: '
                    . implode(', ', self::ALGORITHMS) . '.'];
            }
            if ($val === 'none') {
                return ['error' => self::TAG . '=none needs a format pattern — use the JSON form: '
                    . self::TAG . '={"algorithm":"none","pattern":"YOUR-REGEX"}'];
            }
            return ['algorithm' => $val];
        }
        $cfg = json_decode($val, true);
        if (!is_array($cfg)) {
            return ['error' => self::TAG . ' JSON does not parse ('
                . json_last_error_msg() . ') — use double quotes around keys and string values.'];
        }
        $unknown = array_diff(array_keys($cfg), self::JSON_KEYS);
        if ($unknown) {
            return ['error' => 'unknown ' . self::TAG . ' option(s): ' . implode(', ', $unknown)
                . ' — valid: ' . implode(', ', self::JSON_KEYS) . '.'];
        }
        return self::validateConfig($cfg);
    }

    /**
     * Validate a decoded JSON config; return a clean engine-key fragment or
     * ['error'=>...]. Structural/type checks (is it a string, a whole number, a
     * known key) live here; ALL semantic rule validation is delegated to
     * checkFragment(), the one validator shared with the settings dialog.
     */
    private static function validateConfig(array $cfg)
    {
        $out = [];

        foreach (['type', 'algorithm', 'source', 'blockSave'] as $k) {
            if (isset($cfg[$k])) {
                if (!is_string($cfg[$k])) return ['error' => '"' . $k . '" must be a string.'];
                $out[$k] = $cfg[$k];
            }
        }

        if (isset($cfg['pattern'])) {
            if (!is_string($cfg['pattern']) || $cfg['pattern'] === '') {
                return ['error' => '"pattern" must be a non-empty regex string.'];
            }
            $out['idPattern'] = $cfg['pattern'];
        }

        foreach (['strip', 'keepChars', 'note'] as $k) {
            if (isset($cfg[$k])) {
                if (!is_string($cfg[$k])) return ['error' => '"' . $k . '" must be a string.'];
                $out[$k] = $cfg[$k];
            }
        }

        if (isset($cfg['idLengths'])) {
            $lens = $cfg['idLengths'];
            if (is_string($lens)) $lens = preg_split('/[,\s]+/', trim($lens), -1, PREG_SPLIT_NO_EMPTY);
            if (!is_array($lens) || !count($lens)) {
                return ['error' => '"idLengths" must be a list of positive whole numbers, e.g. [10] or "10, 12".'];
            }
            $clean = [];
            foreach ($lens as $L) {
                if (!self::posInt($L)) {
                    return ['error' => '"idLengths" must be positive whole numbers — got ' . json_encode($L) . '.'];
                }
                $clean[] = (int) $L;
            }
            $out['idLengths'] = $clean;
        }
        foreach (['idMinLen', 'idMaxLen', 'expectedIds'] as $k) {
            if (isset($cfg[$k])) {
                if (!self::posInt($cfg[$k])) {
                    return ['error' => '"' . $k . '" must be a positive whole number.'];
                }
                $out[$k] = (int) $cfg[$k];
            }
        }
        $errors = self::checkFragment($out);
        if ($errors) return ['error' => implode(' ', $errors)];
        return $out;
    }

    /**
     * Shared semantic validation for one rule fragment (engine keys). Used by
     * ALL configuration channels — @UVALIDATE annotations, the settings dialog
     * (settingRowToRule), and the save-time validateSettings() gate — so a rule
     * one channel accepts can never be one another channel (or the runtime
     * pooled parser) rejects. Returns a list of error strings, [] when sound.
     */
    public static function checkFragment(array $frag)
    {
        $errors = [];
        $type = isset($frag['type']) && $frag['type'] !== '' ? $frag['type'] : 'single';
        $algo = isset($frag['algorithm']) && $frag['algorithm'] !== '' ? $frag['algorithm'] : 'iso7064_mod37_36';

        if (!in_array($type, ['single', 'pooled'], true)) {
            $errors[] = '"type" must be "single" or "pooled".';
        }
        if (!in_array($algo, self::ALGORITHMS, true)) {
            $errors[] = 'unknown check algorithm "' . $algo . '" — valid: '
                . implode(', ', self::ALGORITHMS) . '.';
        }
        if (isset($frag['source'])
            && !in_array($frag['source'], ['normalized_id', 'digits_only', 'sequence_only'], true)) {
            $errors[] = '"source" must be normalized_id, digits_only or sequence_only.';
        }
        if (isset($frag['blockSave'])
            && !in_array($frag['blockSave'], ['off', 'confirm', 'hard'], true)) {
            $errors[] = '"blockSave" must be off, confirm or hard.';
        }

        $pattern = isset($frag['idPattern']) ? $frag['idPattern'] : null;
        if ($pattern !== null && $pattern !== '') {
            if (!is_string($pattern)) {
                $errors[] = 'the format pattern must be a regex string.';
            } elseif (preg_match('/[^\x20-\x7E]/', $pattern)) {
                // The client (JS RegExp, UTF-16) and server (PCRE /u, code
                // points) are only proven to agree on printable ASCII.
                $errors[] = 'the format pattern must contain printable ASCII only — '
                    . 'the browser and server regex engines are only guaranteed to agree on that subset.';
            } elseif (preg_match('/\\\\[AZ]/', $pattern)) {
                $errors[] = 'the format pattern uses Python-only \A or \Z anchors — patterns are '
                    . 'JavaScript regex; use ^ and $ instead (anchors are optional anyway).';
            } elseif (strpos($pattern, '(?P<') !== false) {
                $errors[] = 'the format pattern uses Python-only (?P<name>...) groups, '
                    . 'which JavaScript cannot compile.';
            } elseif (CheckCharacter::riskyPattern($pattern)) {
                $errors[] = 'the format pattern looks catastrophically backtracking (nested '
                    . 'quantifiers, a repeated ambiguous group, or overlapping unbounded quantifiers '
                    . '— e.g. (a+)+, (a|aa)+, or .*.* / [0-9]*[0-9]*) — rewrite it so no ambiguous '
                    . 'group repeats and no two unbounded quantifiers overlap.';
            } elseif (!CheckCharacter::patternCompiles($pattern)) {
                $errors[] = 'the format pattern does not compile as a regex — check the syntax.';
            }
        }
        if ($algo === 'none' && ($pattern === null || $pattern === '')) {
            $errors[] = 'algorithm "none" validates format only, so a format pattern is required.';
        }

        foreach (['strip' => 'strip', 'keepChars' => 'keepChars'] as $k => $label) {
            if (isset($frag[$k]) && is_string($frag[$k]) && preg_match('/[^\x20-\x7E]/', $frag[$k])) {
                $errors[] = '"' . $label . '" must contain printable ASCII characters only '
                    . '(Unicode dashes in VALUES are unified automatically before checking).';
            }
        }
        if (isset($frag['keepChars']) && is_string($frag['keepChars'])
            && strlen($frag['keepChars']) > CheckCharacter::MAX_KEEP_CHARS) {
            $errors[] = '"keepChars" is limited to ' . CheckCharacter::MAX_KEEP_CHARS . ' characters.';
        }

        // Length caps bound the pooled parser's per-keystroke/per-save work.
        $lens = isset($frag['idLengths']) && is_array($frag['idLengths']) ? $frag['idLengths'] : null;
        if ($lens) {
            if (count($lens) > CheckCharacter::MAX_LEN_CHOICES) {
                $errors[] = 'at most ' . CheckCharacter::MAX_LEN_CHOICES . ' exact ID lengths are supported.';
            }
            foreach ($lens as $L) {
                if (self::posInt($L) && (int) $L > CheckCharacter::MAX_ID_LEN) {
                    $errors[] = 'ID lengths above ' . CheckCharacter::MAX_ID_LEN . ' characters are not supported.';
                    break;
                }
            }
        }
        foreach (['idMinLen', 'idMaxLen'] as $k) {
            if (isset($frag[$k]) && self::posInt($frag[$k]) && (int) $frag[$k] > CheckCharacter::MAX_ID_LEN) {
                $errors[] = '"' . $k . '" is limited to ' . CheckCharacter::MAX_ID_LEN . '.';
            }
        }
        if (isset($frag['expectedIds']) && self::posInt($frag['expectedIds'])
            && (int) $frag['expectedIds'] > CheckCharacter::MAX_EXPECTED_IDS) {
            $errors[] = '"expectedIds" is limited to ' . CheckCharacter::MAX_EXPECTED_IDS . '.';
        }

        // Pooled-only structural safety (mirrors the pooled parser's own gates,
        // so a rule that passes here can never be "unconfigurable" at runtime).
        if ($type === 'pooled' && !$errors) {
            if ($lens) {
                $ints = array_values(array_unique(array_map('intval', $lens)));
                sort($ints);
                $set = array_flip($ints);
                foreach ($ints as $a) {
                    foreach ($ints as $b) {
                        if (isset($set[$a + $b])) {
                            $errors[] = 'ID lengths ' . implode(', ', $ints) . ' are unsafe: '
                                . ($a + $b) . ' = ' . $a . ' + ' . $b . ', so one "member" could swallow '
                                . 'two real ones. Split such projects into separate fields/rules.';
                            break 2;
                        }
                    }
                }
            } else {
                $min = isset($frag['idMinLen']) && self::posInt($frag['idMinLen']) ? (int) $frag['idMinLen'] : 8;
                $max = isset($frag['idMaxLen']) && self::posInt($frag['idMaxLen']) ? (int) $frag['idMaxLen'] : 14;
                if ($max < $min) {
                    $errors[] = 'the maximum ID length (' . $max . ') is smaller than the minimum (' . $min . ').';
                } elseif ($max >= 2 * $min) {
                    $errors[] = 'the maximum ID length (' . $max . ') must be LESS than 2 x the minimum ('
                        . (2 * $min) . ') so one "member" can never swallow two real ones. Narrow the '
                        . 'range, or set exact ID length(s) instead.';
                }
            }
        }
        return $errors;
    }

    public static function posInt($v)
    {
        if (is_int($v)) return $v > 0;
        if (is_string($v)) return ctype_digit($v) && (int) $v > 0;
        return false;
    }

    /**
     * Group per-field fragments (field => fragment from parseField) into engine
     * rules: fields with identical configs share one rule, so 50 tagged fields
     * with the same tag produce one rule with 50 fields, not 50 rules. Error
     * fragments become per-field config-error rules so the message shows exactly
     * on the mis-tagged field.
     */
    public static function group(array $perField)
    {
        $rules = [];
        $byKey = [];
        foreach ($perField as $field => $frag) {
            if (isset($frag['error'])) {
                $rules[] = [
                    'type' => 'single', 'fields' => [$field],
                    'configError' => self::TAG . ' on "' . $field . '": ' . $frag['error'],
                ];
                continue;
            }
            $canon = $frag;
            ksort($canon);
            $key = json_encode($canon);
            if (!isset($byKey[$key])) {
                $rule = $frag;
                $rule['type'] = $frag['type'] ?? 'single';
                $rule['fields'] = [];
                $byKey[$key] = count($rules);
                $rules[] = $rule;
            }
            $rules[$byKey[$key]]['fields'][] = $field;
        }
        return $rules;
    }
}
