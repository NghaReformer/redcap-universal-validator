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
 * blockSave ("off"|"confirm"|"hard"), when (a REDCap-style condition — the
 * rule validates only while it is true, see php/Logic.php), suggestFix
 * (boolean; opt IN to the "should end in X" check-character hint), note. A
 * malformed value becomes a visible per-field configuration error — never a
 * silently skipped rule.
 *
 * Pure static class with no REDCap dependency, unit-tested by
 * tests/annotation_php.php; UniversalValidator::getAnnotationRules() is the thin
 * glue that feeds it the project's data dictionary.
 */

namespace INSPIRE\UniversalValidator;

require_once __DIR__ . '/CheckCharacter.php';
require_once __DIR__ . '/Logic.php';

class AnnotationRules
{
    const TAG = '@UVALIDATE';
    const TAG_ASSERT = '@UVASSERT';
    const TAG_REQUIRED = '@UVREQUIRED';
    const TAG_UNIQUE = '@UVUNIQUE';
    const TAG_CHOICES = '@UVCHOICES';

    /**
     * Every action tag this module owns, mapped to the validation MODE the tag
     * configures. @UVALIDATE is the original check-character / regex ID tag;
     * the intent-named tags each configure one added mode. Different modes on
     * one field COMPOSE (all must pass); several tags of the SAME mode on one
     * field branch (php/Branching.php). The mode travels on the rule as its
     * "type" (single|pooled = check; constraint; required; …), which is what
     * the client dispatcher, the server audit, and Branching all key on.
     */
    const TAGS = [
        self::TAG          => 'check',
        self::TAG_ASSERT   => 'constraint',
        self::TAG_REQUIRED => 'required',
        self::TAG_UNIQUE   => 'unique',
        self::TAG_CHOICES  => 'choices',
    ];

    /** Uniqueness scopes: whole project (default), within the record's Data
     *  Access Group, or within the same event of a longitudinal project. */
    const UNIQUE_SCOPES = ['project', 'dag', 'event'];

    /** Composite-key size cap: [field]+with must stay a cheap lookup. */
    const MAX_UNIQUE_WITH = 5;

    /** Field types a constraint (@UVASSERT) may be attached to — any scalar
     *  input whose current answer is a single readable value. Checkbox (multi
     *  value), file and descriptive fields are excluded as the VALIDATED field,
     *  though any of them may still be REFERENCED inside an assert condition. */
    const CONSTRAINT_FIELD_TYPES = ['text', 'notes', 'dropdown', 'radio',
                                    'yesno', 'truefalse', 'calc', 'sql', 'slider'];

    /** Field types @UVREQUIRED may be attached to. Same scalar family as
     *  constraints MINUS calc (the person entering data cannot type into a
     *  calc, so "required" would trap them on a field they cannot fix). */
    const REQUIRED_FIELD_TYPES = ['text', 'notes', 'dropdown', 'radio',
                                  'yesno', 'truefalse', 'sql', 'slider'];

    /** Field types @UVUNIQUE may be attached to — same list as required (no
     *  calc: a data enterer cannot fix a calc collision). Composite "with"
     *  fields are checked separately against the data dictionary. */
    const UNIQUE_FIELD_TYPES = ['text', 'notes', 'dropdown', 'radio',
                                'yesno', 'truefalse', 'sql', 'slider'];

    /** Field types @UVCHOICES may filter — the multiple-choice family whose
     *  options come from select_choices_or_calculations. yesno/truefalse have
     *  fixed options (filtering one of two makes the field a foregone
     *  conclusion, not a choice) and sql options live outside the dictionary,
     *  so neither is eligible. Matrix membership is refused separately in the
     *  channel glue (grid rows render different markup). */
    const CHOICES_FIELD_TYPES = ['radio', 'dropdown', 'checkbox'];

    /** Cap on the show/hide code list: filtering is a per-choice DOM walk on
     *  every re-evaluation, so the list must stay small. REDCap fields with
     *  more choices than this belong in an autocomplete dropdown anyway. */
    const MAX_CHOICE_CODES = 200;

    const ALGORITHMS = [
        'iso7064_mod37_36', 'iso7064_mod11_10', 'iso7064_mod97_10',
        'iso7064_mod11_2', 'iso7064_mod37_2', 'iso7064_letters1',
        'iso7064_letters2', 'damm', 'verhoeff', 'luhn',
        'gs1_mod10', 'aba_mod10', 'mrz_mod10', 'weighted_mod11', 'none',
    ];

    /**
     * Friendly shorthands for the algorithm names, so a data manager can write
     * @UVALIDATE=3736 (or 37,36, mod37_36, ...) instead of the full
     * iso7064_mod37_36. This is the SINGLE source of truth for synonyms —
     * canonicalAlgorithm() resolves against it, the dialog dropdown stores
     * canonical names directly, and the client always receives canonical names
     * (the config is built server-side), so nothing else needs a synonym table.
     *
     * To add a shorthand: add it to the right canonical row here (lowercase),
     * then run tests/annotation_php.php — a collision guard there fails if a
     * shorthand clashes with a canonical name or another shorthand.
     *
     * Format: CANONICAL => [ shorthand, shorthand, ... ] (all lowercase).
     */
    const ALGORITHM_SYNONYMS = [
        'iso7064_mod37_36' => ['3736', '37_36', '37-36', '37,36', 'mod37_36', 'mod3736'],
        'iso7064_mod11_10' => ['1110', '11_10', '11-10', '11,10', 'mod11_10', 'mod1110'],
        'iso7064_mod97_10' => ['9710', '97_10', '97-10', '97,10', 'mod97_10', 'mod9710'],
        'iso7064_mod11_2'  => ['112', '11_2', '11-2', '11,2', 'mod11_2', 'mod112'],
        'iso7064_mod37_2'  => ['372', '37_2', '37-2', '37,2', 'mod37_2', 'mod372'],
        'iso7064_letters1' => ['letters1', 'letter1'],
        'iso7064_letters2' => ['letters2', 'letter2'],
        'luhn'             => ['mod10'],
        'gs1_mod10'        => ['gs1', 'gtin', 'ean', 'upc'],
        'aba_mod10'        => ['aba', 'routing'],
        'mrz_mod10'        => ['mrz', 'icao'],
        'weighted_mod11'   => ['isbn', 'mod11w', 'weighted11'],
        'none'             => ['regex', 'format'],
    ];

    /** Keys accepted in the JSON form ("pattern" maps to the engine's idPattern). */
    const JSON_KEYS = ['type', 'algorithm', 'source', 'pattern', 'alternates', 'strip', 'keepChars',
                       'idLengths', 'idMinLen', 'idMaxLen', 'expectedIds', 'blockSave', 'when',
                       'suggestFix', 'note'];

    /** Keys accepted INSIDE one entry of the "alternates" list. */
    const ALT_KEYS = ['pattern', 'algorithm', 'source', 'strip', 'lengths', 'label'];

    /**
     * Resolve a user-typed algorithm shorthand to its canonical name.
     *
     * Matching is case-insensitive. An already-canonical name (any case) is
     * returned canonical; an unrecognized value is returned UNCHANGED, so the
     * existing "unknown algorithm" whitelist error still fires for real typos.
     * Applied at every point a raw algorithm string is read into a rule, so the
     * stored value — and therefore the check-character engine and the client —
     * always sees a canonical name.
     */
    public static function canonicalAlgorithm($name)
    {
        if (!is_string($name) || $name === '') return $name;
        if (in_array($name, self::ALGORITHMS, true)) return $name;      // fast path: already canonical
        $key = strtolower(trim($name));
        if (in_array($key, self::ALGORITHMS, true)) return $key;        // canonical, just wrong case
        foreach (self::ALGORITHM_SYNONYMS as $canonical => $synonyms) {
            if (in_array($key, $synonyms, true)) return $canonical;
        }
        return $name;                                                   // unknown -> unchanged
    }

    /**
     * Extract the raw value of the FIRST @UVALIDATE tag (compatibility form).
     * Returns null when the tag is absent, '' for the bare tag, otherwise the
     * value text (bare token, 'quoted', "quoted", or a brace-balanced {...}).
     */
    public static function extractTag($annotation)
    {
        $tags = self::extractTags($annotation);
        return $tags ? $tags[0] : null;
    }

    /**
     * Extract EVERY real @UVALIDATE tag value from an annotation, in order —
     * one field may carry several tags since 0.9.0 (branched validation: each
     * tag becomes its own rule, and Branching::resolve() turns the sharing
     * into a per-field branch rule or a config error). Returns [] when no tag
     * is present; a bare tag contributes ''.
     */
    public static function extractTags($annotation)
    {
        return self::extractTagsFor($annotation, self::TAG);
    }

    /**
     * Extract every real occurrence of ONE tag from an annotation, in order.
     * Generalized from extractTags so each module tag (@UVALIDATE, @UVASSERT,
     * …) is scanned by the same value-boundary rules. A tag ends at '=',
     * whitespace, or end-of-string, so @UVALIDATED / @UVASSERTS never match.
     */
    public static function extractTagsFor($annotation, $tag)
    {
        $ann = (string) $annotation;
        $len = strlen($ann);
        $out = [];
        $offset = 0;
        while ($offset < $len) {
            $pos = stripos($ann, $tag, $offset);
            while ($pos !== false) {
                $after = $pos + strlen($tag);
                $ch = $after < $len ? $ann[$after] : '';
                // a real tag ends at '=', whitespace, or end-of-string;
                // @UVALIDATED / @UVALIDATE2 are different tags — keep scanning.
                if ($ch === '' || $ch === '=' || ctype_space($ch)) break;
                $pos = stripos($ann, $tag, $after);
            }
            if ($pos === false) break;
            $after = $pos + strlen($tag);
            if ($after >= $len || $ann[$after] !== '=') {
                $out[] = '';
                $offset = $after;
                continue;
            }
            $val = self::readValue(substr($ann, $after + 1));
            $out[] = $val;
            // Advance PAST the consumed value so tag-like text inside a value
            // (e.g. a pattern containing "@UVALIDATE") is never re-read as a
            // second tag. Quoted values also consumed their two quote marks.
            $consumed = strlen($val);
            $first = $after + 1 < $len ? $ann[$after + 1] : '';
            if ($first === '"' || $first === "'") $consumed += 2;
            $offset = $after + 1 + $consumed;
        }
        return $out;
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
     * error, null an untagged field. Compatibility form — reads the FIRST tag.
     */
    public static function parseField($annotation)
    {
        $all = self::parseFieldAll($annotation);
        return $all === null ? null : $all[0];
    }

    /**
     * Parse EVERY @UVALIDATE tag of one field's annotation: null for an
     * untagged field, otherwise a list of fragments (each [] | ['error'=>...]
     * | engine-key config), one per tag, in annotation order.
     */
    public static function parseFieldAll($annotation)
    {
        $tags = self::extractTags($annotation);
        if (!$tags) return null;
        $out = [];
        foreach ($tags as $val) $out[] = self::parseValue($val);
        return $out;
    }

    /**
     * Parse EVERY module tag on one field's annotation, across all modes
     * (@UVALIDATE check rules AND the intent-named mode tags like @UVASSERT):
     * null when the field carries none, else a list of fragments in tag order
     * (each [] | engine-key config | ['error'=>msg, '_tag'=>tag]). A fragment's
     * "type" carries its mode, so groupMulti/Branching/the audit route it. This
     * is the multi-mode superset of parseFieldAll (which stays @UVALIDATE-only
     * for back-compat).
     */
    public static function parseAllTags($annotation)
    {
        $any = false;
        $out = [];
        foreach (self::TAGS as $tag => $mode) {
            foreach (self::extractTagsFor($annotation, $tag) as $val) {
                $any = true;
                if ($mode === 'constraint')    $frag = self::parseAssertValue($val);
                elseif ($mode === 'required')  $frag = self::parseRequiredValue($val);
                elseif ($mode === 'unique')    $frag = self::parseUniqueValue($val);
                elseif ($mode === 'choices')   $frag = self::parseChoicesValue($val);
                else                           $frag = self::parseValue($val);
                if (isset($frag['error'])) $frag['_tag'] = $tag; // so groupMulti names the right tag
                $out[] = $frag;
            }
        }
        return $any ? $out : null;
    }

    /**
     * Parse one @UVUNIQUE value into a unique fragment. Three forms:
     *   @UVUNIQUE                       unique across the whole project
     *   @UVUNIQUE=event                 pick a scope (project | dag | event)
     *   @UVUNIQUE={"with":["site"],"scope":"event","when":"...",
     *              "message":"...","blockSave":"hard","surveys":true}
     * "with" makes the key composite (value + those fields together must be
     * unique). "surveys" is an explicit OPT-IN: a live used/free answer is
     * record-derived information, so survey respondents only get the check
     * when the designer decides the trade-off is acceptable (the server
     * answers surveys with a boolean only, never a record id).
     */
    private static function parseUniqueValue($val)
    {
        $val = trim($val);
        if ($val === '') {
            $out = ['type' => 'unique'];
            $errs = self::checkFragment($out);
            return $errs ? ['error' => implode(' ', $errs)] : $out;
        }
        if ($val[0] !== '{') {
            $scope = strtolower($val);
            if (!in_array($scope, self::UNIQUE_SCOPES, true)) {
                return ['error' => self::TAG_UNIQUE . '=' . $val . ' is not a scope — use '
                    . implode(', ', self::UNIQUE_SCOPES) . ', or the JSON form for other options.'];
            }
            $out = ['type' => 'unique', 'uniqueScope' => $scope];
            $errs = self::checkFragment($out);
            return $errs ? ['error' => implode(' ', $errs)] : $out;
        }
        $cfg = json_decode($val, true);
        if (!is_array($cfg)) {
            return ['error' => self::TAG_UNIQUE . ' JSON does not parse ('
                . json_last_error_msg() . ') — use double quotes around keys and string values.'];
        }
        $allowed = ['with', 'scope', 'when', 'message', 'blockSave', 'surveys'];
        $unknown = array_diff(array_keys($cfg), $allowed);
        if ($unknown) {
            return ['error' => 'unknown ' . self::TAG_UNIQUE . ' option(s): ' . implode(', ', $unknown)
                . ' — valid: ' . implode(', ', $allowed) . '.'];
        }
        $out = ['type' => 'unique'];
        if (isset($cfg['with'])) {
            if (!is_array($cfg['with'])) return ['error' => '"with" must be a list of field names, e.g. ["site"].'];
            // Field names are lowercase in REDCap — normalize here so the rule,
            // the client payload and the server lookup all agree.
            $out['uniqueWith'] = array_map(function ($w) {
                return is_string($w) ? strtolower(trim($w)) : $w;
            }, array_values($cfg['with']));
        }
        if (isset($cfg['scope'])) {
            if (!is_string($cfg['scope'])) return ['error' => '"scope" must be a string.'];
            $out['uniqueScope'] = strtolower($cfg['scope']);
        }
        if (isset($cfg['surveys'])) {
            if (!is_bool($cfg['surveys'])) return ['error' => '"surveys" must be true or false (unquoted).'];
            $out['uniqueSurveys'] = $cfg['surveys'];
        }
        foreach (['when', 'message', 'blockSave'] as $k) {
            if (isset($cfg[$k])) {
                if (!is_string($cfg[$k])) return ['error' => '"' . $k . '" must be a string.'];
                $out[$k] = $cfg[$k];
            }
        }
        $errs = self::checkFragment($out);
        return $errs ? ['error' => implode(' ', $errs)] : $out;
    }

    /**
     * Parse one @UVCHOICES value into a choices fragment (dynamic choice
     * filtering: show/hide individual options of a radio, dropdown or checkbox
     * field while a condition holds). JSON form only — the tag has no sensible
     * bare default, and a shorthand would just be a second grammar to learn:
     *   @UVCHOICES={"when":"[country]='1'","show":["101","102"]}
     *   @UVCHOICES={"when":"[legacy]<>'1'","hide":["9"],"blockSave":"hard"}
     * Exactly one of "show" (whitelist — everything else hides) or "hide"
     * (blacklist) per tag. Several tags on one field branch via Branching, so
     * a country→site cascade is one tag per country. A currently-selected
     * choice that becomes hidden is NEVER cleared — it stays visible, flagged
     * invalid, and blockSave decides whether the save is challenged.
     */
    private static function parseChoicesValue($val)
    {
        $val = trim($val);
        if ($val === '' || $val[0] !== '{') {
            return ['error' => self::TAG_CHOICES . ' needs a show or hide list — use the JSON form, e.g. '
                . self::TAG_CHOICES . '={"when":"[country]=\'1\'","show":["101","102"]}.'];
        }
        $cfg = json_decode($val, true);
        if (!is_array($cfg)) {
            return ['error' => self::TAG_CHOICES . ' JSON does not parse ('
                . json_last_error_msg() . ') — use double quotes around keys and string values.'];
        }
        $allowed = ['when', 'show', 'hide', 'message', 'blockSave'];
        $unknown = array_diff(array_keys($cfg), $allowed);
        if ($unknown) {
            return ['error' => 'unknown ' . self::TAG_CHOICES . ' option(s): ' . implode(', ', $unknown)
                . ' — valid: ' . implode(', ', $allowed) . '.'];
        }
        $out = ['type' => 'choices'];
        foreach (['show' => 'choicesShow', 'hide' => 'choicesHide'] as $k => $ruleKey) {
            if (!isset($cfg[$k])) continue;
            if (!is_array($cfg[$k])) {
                return ['error' => '"' . $k . '" must be a list of choice codes, e.g. ["1","2"].'];
            }
            $codes = [];
            foreach (array_values($cfg[$k]) as $c) {
                // Codes may be typed unquoted (JSON numbers) — normalize every
                // scalar to its trimmed string form, the shape REDCap stores.
                if (!is_string($c) && !is_int($c) && !is_float($c)) {
                    return ['error' => '"' . $k . '" entry ' . json_encode($c)
                        . ' is not a choice code — use strings or numbers.'];
                }
                $codes[] = trim((string) $c);
            }
            $out[$ruleKey] = $codes;
        }
        foreach (['when', 'message', 'blockSave'] as $k) {
            if (isset($cfg[$k])) {
                if (!is_string($cfg[$k])) return ['error' => '"' . $k . '" must be a string.'];
                $out[$k] = $cfg[$k];
            }
        }
        $errs = self::checkFragment($out);
        return $errs ? ['error' => implode(' ', $errs)] : $out;
    }

    /**
     * Parse one @UVREQUIRED value into a required fragment. Three forms:
     *   @UVREQUIRED                          always required (while non-blank saves)
     *   @UVREQUIRED="[consent]='1'"          required only WHILE the condition is
     *                                        true (the value IS the "when")
     *   @UVREQUIRED={"when":"...","message":"...","blockSave":"hard"}
     * A blank field is invalid while required; a filled field simply clears the
     * notice (required mode never judges the VALUE — pair with @UVALIDATE or
     * @UVASSERT for that; the modes compose).
     */
    private static function parseRequiredValue($val)
    {
        $val = trim($val);
        if ($val === '') {
            $out = ['type' => 'required'];
            $errs = self::checkFragment($out);
            return $errs ? ['error' => implode(' ', $errs)] : $out;
        }
        if ($val[0] !== '{') {
            $out = ['type' => 'required', 'when' => $val];
            $errs = self::checkFragment($out);
            return $errs ? ['error' => implode(' ', $errs)] : $out;
        }
        $cfg = json_decode($val, true);
        if (!is_array($cfg)) {
            return ['error' => self::TAG_REQUIRED . ' JSON does not parse ('
                . json_last_error_msg() . ') — use double quotes around keys and string values.'];
        }
        $allowed = ['when', 'message', 'blockSave'];
        $unknown = array_diff(array_keys($cfg), $allowed);
        if ($unknown) {
            return ['error' => 'unknown ' . self::TAG_REQUIRED . ' option(s): ' . implode(', ', $unknown)
                . ' — valid: ' . implode(', ', $allowed) . '.'];
        }
        $out = ['type' => 'required'];
        foreach ($allowed as $k) {
            if (isset($cfg[$k])) {
                if (!is_string($cfg[$k])) return ['error' => '"' . $k . '" must be a string.'];
                $out[$k] = $cfg[$k];
            }
        }
        $errs = self::checkFragment($out);
        return $errs ? ['error' => implode(' ', $errs)] : $out;
    }

    /**
     * Parse one @UVASSERT value into a constraint fragment. The value is EITHER
     * the condition itself (@UVASSERT="[end]>=[start]") or a JSON object
     * {assert, message, blockSave, when}. The field is invalid whenever the
     * condition is false; an empty field is inert (that is @UVREQUIRED's job).
     */
    private static function parseAssertValue($val)
    {
        $val = trim($val);
        if ($val === '') {
            return ['error' => self::TAG_ASSERT . ' needs a condition — e.g. '
                . self::TAG_ASSERT . '="[end_date]>=[start_date]".'];
        }
        if ($val[0] !== '{') {
            $out = ['type' => 'constraint', 'assert' => $val];
            $errs = self::checkFragment($out);
            return $errs ? ['error' => implode(' ', $errs)] : $out;
        }
        $cfg = json_decode($val, true);
        if (!is_array($cfg)) {
            return ['error' => self::TAG_ASSERT . ' JSON does not parse ('
                . json_last_error_msg() . ') — use double quotes around keys and string values.'];
        }
        $allowed = ['assert', 'message', 'blockSave', 'when'];
        $unknown = array_diff(array_keys($cfg), $allowed);
        if ($unknown) {
            return ['error' => 'unknown ' . self::TAG_ASSERT . ' option(s): ' . implode(', ', $unknown)
                . ' — valid: ' . implode(', ', $allowed) . '.'];
        }
        $out = ['type' => 'constraint'];
        foreach ($allowed as $k) {
            if (isset($cfg[$k])) {
                if (!is_string($cfg[$k])) return ['error' => '"' . $k . '" must be a string.'];
                $out[$k] = $cfg[$k];
            }
        }
        $errs = self::checkFragment($out);
        return $errs ? ['error' => implode(' ', $errs)] : $out;
    }

    /** Parse one raw tag value into a fragment (see parseField). */
    private static function parseValue($val)
    {
        $val = trim($val);
        if ($val === '') return [];
        if ($val[0] !== '{') {
            $canon = self::canonicalAlgorithm($val);
            if (!in_array($canon, self::ALGORITHMS, true)) {
                return ['error' => 'unknown check algorithm "' . $val . '" — valid: '
                    . implode(', ', self::ALGORITHMS) . '.'];
            }
            if ($canon === 'none') {
                return ['error' => self::TAG . '=none (or "regex"/"format") validates format only and '
                    . 'needs a pattern — use the JSON form: '
                    . self::TAG . '={"algorithm":"none","pattern":"YOUR-REGEX"}'];
            }
            return ['algorithm' => $canon];
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
        // Resolve an algorithm shorthand (e.g. "3736") to its canonical name
        // before checkFragment validates it, so the whole audit/engine chain and
        // the client all see the full iso7064_mod37_36 form.
        if (isset($out['algorithm'])) $out['algorithm'] = self::canonicalAlgorithm($out['algorithm']);

        if (isset($cfg['pattern'])) {
            if (!is_string($cfg['pattern']) || $cfg['pattern'] === '') {
                return ['error' => '"pattern" must be a non-empty regex string.'];
            }
            $out['idPattern'] = $cfg['pattern'];
        }

        if (isset($cfg['alternates'])) {
            $norm = self::normalizeAlternates($cfg['alternates']);
            if (isset($norm['error'])) return ['error' => $norm['error']];
            $out['alternates'] = $norm['alternates'];
        }

        if (isset($cfg['suggestFix'])) {
            // Strict boolean: "true"/1 would hide a typo'd intent, and the
            // check-character hint is deliberately opt-in (see README).
            if (!is_bool($cfg['suggestFix'])) {
                return ['error' => '"suggestFix" must be true or false (unquoted).'];
            }
            $out['suggestFix'] = $cfg['suggestFix'];
        }

        foreach (['strip', 'keepChars', 'when', 'note'] as $k) {
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

        // "alternates" describes accepted ID FORMATS, which only the check
        // modes read. Left unremarked on the others it validates clean, is
        // carried through Branching::BRANCH_KEYS and shipped to the browser,
        // and a designer reads it as active configuration when nothing will
        // ever consult it.
        if (isset($frag['alternates']) && $frag['alternates'] !== null && $frag['alternates'] !== ''
                && !in_array($type, ['single', 'pooled'], true)) {
            return ['"alternates" applies only to ID checks (type "single" or "pooled"); a '
                . $type . ' rule has no ID format to match.'];
        }
        // Constraint mode (@UVASSERT): a cross-field assertion, not an ID check.
        // It shares "when"/"blockSave" with check rules but none of the
        // check-character/pattern/pooled machinery, so it validates separately.
        if ($type === 'constraint') return self::checkConstraint($frag);
        // Required mode (@UVREQUIRED): blank-while-required is the only test.
        if ($type === 'required') return self::checkRequired($frag);
        // Unique mode (@UVUNIQUE): no-duplicates across records via the server.
        if ($type === 'unique') return self::checkUnique($frag);
        // Choices mode (@UVCHOICES): dynamic show/hide of individual options.
        if ($type === 'choices') return self::checkChoices($frag);

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
        // Optional "when" condition: syntax, caps, and dialect-subset gating all
        // live in Logic::parse (the normative spec). Dictionary-dependent ref
        // checks (does the field exist, is the checkbox code real) happen in the
        // channel glue, which is where the data dictionary is available.
        if (isset($frag['when'])) {
            if (!is_string($frag['when'])) {
                $errors[] = 'the "when" condition must be a non-empty condition string.';
            } else {
                $w = Logic::parse($frag['when']);
                if (empty($w['ok'])) {
                    $errors[] = 'the "when" condition ' . $w['error'];
                }
            }
        }

        $pattern = isset($frag['idPattern']) ? $frag['idPattern'] : null;
        // Shape FIRST. is_array() alone let a JSON object through, and the walk
        // below builds 'alternate ' . ($i + 1) from the key - a TypeError on
        // PHP 8, silent mis-numbering on the 7.4 floor, and inside
        // validateSettings the throw is swallowed into an allowed save. An
        // empty list was skipped entirely here while both runtimes refused it,
        // which is exactly the cross-channel disagreement this file forbids.
        $hasAlts = false;
        if (isset($frag['alternates']) && $frag['alternates'] !== null && $frag['alternates'] !== '') {
            $al = $frag['alternates'];
            if (!is_array($al) || !count($al) || array_keys($al) !== range(0, count($al) - 1)) {
                $errors[] = '"alternates" must be a non-empty JSON list [ ... ] of formats, not an '
                    . 'object { ... } and not empty — the browser and the server order object keys '
                    . 'differently, and alternates are tried in the order you write them.';
            } else {
                $hasAlts = true;
            }
        }
        $why = '';
        if ($pattern !== null && $pattern !== '') {
            // Single admission point, shared with the pooled parser and twinned
            // by QRID_gatePattern (js) — see CheckCharacter::gatePattern.
            if (CheckCharacter::gatePattern($pattern, $why) === null) $errors[] = $why;
        }
        if ($algo === 'none' && ($pattern === null || $pattern === '') && !$hasAlts) {
            $errors[] = 'algorithm "none" validates format only, so a format pattern is required.';
        }

        // ---- multi-format rules -------------------------------------------
        // Ambiguity is refused HERE, at config time, never resolved by guessing
        // at runtime: the same precedent M-03 set below.
        if ($hasAlts && !$errors) {
            $alts = $frag['alternates'];
            if (count($alts) > CheckCharacter::MAX_ALTERNATES) {
                $errors[] = '"alternates" lists ' . count($alts) . ' formats — at most '
                    . CheckCharacter::MAX_ALTERNATES . ' are supported. More ID families than that in '
                    . 'one field is usually a sign they belong in separate fields.';
            }
            if ($pattern !== null && $pattern !== '') {
                $errors[] = 'a rule with "alternates" must not also set a rule-level "pattern" — '
                    . 'give each alternate its own.';
            }
            if (isset($frag['idLengths']) || isset($frag['idMinLen']) || isset($frag['idMaxLen'])) {
                $errors[] = 'a rule with "alternates" must not also set rule-level "idLengths", '
                    . '"idMinLen" or "idMaxLen" — give each alternate its own "lengths".';
            }
        }
        if ($hasAlts && !$errors) {
            $alts = $frag['alternates'];
            $ruleAlgo   = $algo;
            $ruleSource = isset($frag['source']) ? $frag['source'] : 'normalized_id';
            $baseKeep   = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789'
                . (isset($frag['keepChars']) ? (string) $frag['keepChars'] : '');
            $union = [];        // every length any alternate can produce
            $roLens = [];       // lengths of the FORMAT-ONLY alternates
            $ckLens = [];       // lengths of the CHECK-BEARING alternates
            $keepSets = [];
            $shapes = [];
            $fwhy = '';
            foreach ($alts as $i => $a) {
                $nm = isset($a['label']) && $a['label'] !== '' ? (string) $a['label'] : 'alternate ' . ($i + 1);
                if (isset($a['label']) && strlen((string) $a['label']) > CheckCharacter::MAX_ALT_LABEL) {
                    $errors[] = 'alternate ' . ($i + 1) . ': "label" is limited to '
                        . CheckCharacter::MAX_ALT_LABEL . ' characters.';
                    continue;
                }
                // M-01: alternatesOf refuses a non-ASCII label at RUNTIME, so a
                // label that only passed a length check here would save clean
                // and then kill the rule on the form - breaking the invariant
                // that a rule one channel accepts another cannot reject.
                if (isset($a['label']) && preg_match('/[^\x20-\x7E]/', (string) $a['label'])) {
                    $errors[] = 'alternate ' . ($i + 1) . ': "label" must contain printable ASCII '
                        . 'characters only.';
                    continue;
                }
                // M-04: an alternate's own "strip" reaches normalize() exactly
                // as the rule-level one does, and PHP splits it by code point
                // while the browser splits by UTF-16 code unit - so outside
                // printable ASCII the two runtimes strip differently. Same gate
                // the rule level has had all along.
                if (isset($a['strip']) && preg_match('/[^\x20-\x7E]/', (string) $a['strip'])) {
                    $errors[] = $nm . ': "strip" must contain printable ASCII characters only.';
                    continue;
                }
                $aAlgo = isset($a['algorithm']) && $a['algorithm'] !== '' ? $a['algorithm'] : $ruleAlgo;
                if (!in_array($aAlgo, self::ALGORITHMS, true)) {
                    $errors[] = $nm . ': unknown algorithm "' . $aAlgo . '". Valid: '
                        . implode(', ', self::ALGORITHMS) . '.';
                    continue;
                }
                $aSource = isset($a['source']) && $a['source'] !== '' ? $a['source'] : $ruleSource;
                if ($aAlgo !== 'none' && !in_array($aSource, ['normalized_id', 'digits_only', 'sequence_only'], true)) {
                    $errors[] = $nm . ': unknown source "' . $aSource . '" — use normalized_id, '
                        . 'digits_only or sequence_only.';
                    continue;
                }
                if (!isset($a['pattern']) || !is_string($a['pattern']) || $a['pattern'] === '') {
                    // A pattern-less alternate matches everything, so in
                    // declaration order it would swallow every value and the
                    // alternates after it would never be reached.
                    $errors[] = $nm . ' needs a non-empty "pattern" — an alternate without one would '
                        . 'accept every value and the alternates after it would never be reached.';
                    continue;
                }
                $why = '';
                if (CheckCharacter::gatePattern($a['pattern'], $why) === null) {
                    $errors[] = $nm . ': ' . $why;
                    continue;
                }
                if ($type === 'pooled') {
                    if (!isset($a['lengths']) || !is_array($a['lengths']) || !count($a['lengths'])) {
                        $errors[] = $nm . ' needs "lengths" — a pooled rule splits a run at member '
                            . 'boundaries, so every format must say how long its IDs are.';
                        continue;
                    }
                    $ls = array_values(array_unique(array_map('intval', $a['lengths'])));
                    sort($ls);
                    foreach ($ls as $L) {
                        if ($L > CheckCharacter::MAX_ID_LEN) {
                            $errors[] = $nm . ': ID lengths above ' . CheckCharacter::MAX_ID_LEN
                                . ' characters are not supported.';
                            break;
                        }
                    }
                    $union = array_merge($union, $ls);
                    if ($aAlgo === 'none') $roLens = array_merge($roLens, $ls);
                    else                   $ckLens = array_merge($ckLens, $ls);
                }
                $shapes[$i] = ['name' => $nm, 'pattern' => $a['pattern'], 'formatOnly' => ($aAlgo === 'none')];
                // KEEP is computed once over the WHOLE field before anything is
                // split, so it is the union across alternates. An algorithm that
                // can emit "*" therefore makes "*" survive for a sibling that
                // cannot contain it, silently changing the string recorded for
                // that sibling.
                // Keyed by INDEX, not by display name: two alternates sharing
                // a label used to overwrite each other, and when EVERY entry
                // shared one label the map collapsed to a single row and the
                // comparison below was skipped entirely - silently disabling
                // the gate. Copy-pasting an entry and forgetting to rename it
                // is the obvious way to hit that.
                $keepSets[$i] = ['name' => $nm, 'keep' => self::keepSetFor($baseKeep, $aAlgo, $a['pattern'])];
            }
            // A format-only alternate accepts on shape alone, and the first
            // alternate that accepts wins - so if its pattern also accepts a
            // value a check-bearing alternate is meant to verify, that check
            // character is never tested and a mis-scan passes as clean. Pattern
            // subsumption is undecidable in general; this asks the decidable
            // question instead - is there a CONCRETE string both accept? - and
            // stays silent whenever no witness can be produced.
            // The pooled path also has the length guard below; this is the only
            // protection a single-value field gets.
            if (!$errors && count($shapes) > 1) {
                foreach ($shapes as $fi => $F) {
                    if (!$F['formatOnly']) continue;
                    foreach ($shapes as $ki => $K) {
                        if ($K['formatOnly']) continue;
                        $w = CheckCharacter::patternWitness($K['pattern']);
                        if ($w === null) continue;                  // no witness, no claim
                        $fre = CheckCharacter::gatePattern($F['pattern'], $fwhy, true);
                        if ($fre === null || !CheckCharacter::patTest($fre, $w)) continue;
                        $errors[] = $F['name'] . ' has no check character and its pattern also accepts '
                            . 'values meant for ' . $K['name'] . ' (for example "' . $w . '"). Whichever '
                            . 'alternate accepts first wins, so ' . $K['name'] . '\'s check character '
                            . 'would never be tested and a mis-scan would pass as a clean ID. Narrow '
                            . $F['name'] . '\'s pattern so the two cannot overlap.';
                        break 2;
                    }
                }
            }
            if (!$errors && count($keepSets) > 1) {
                $idxs  = array_keys($keepSets);
                $first = $keepSets[$idxs[0]];
                foreach ($idxs as $ix) {
                    $diff = array_merge(array_diff(str_split($keepSets[$ix]['keep']), str_split($first['keep'])),
                                        array_diff(str_split($first['keep']), str_split($keepSets[$ix]['keep'])));
                    if ($diff) {
                        $nm = $keepSets[$ix]['name'];
                        if ($nm === $first['name']) $nm = 'alternate ' . ($ix + 1);
                        $errors[] = $first['name'] . ' and ' . $nm . ' disagree about which characters survive '
                            . 'cleaning (' . implode(' ', array_unique($diff)) . '). Cleaning runs once over '
                            . 'the whole field, so those characters would be kept for every alternate — '
                            . 'including ones that cannot contain them, which changes the value recorded. '
                            . 'Add them to "keepChars" if that is intended, or give the alternates the same '
                            . 'check alphabet and separators.';
                        break;
                    }
                }
            }
            if (!$errors && $type === 'pooled' && $union) {
                $union = array_values(array_unique($union));
                sort($union);
                if (count($union) > CheckCharacter::MAX_LEN_CHOICES) {
                    $errors[] = 'the alternates declare ' . count($union) . ' distinct ID lengths — at most '
                        . CheckCharacter::MAX_LEN_CHOICES . ' are supported.';
                }
                $sw = $errors ? null : CheckCharacter::swallowSum($union);
                if ($sw !== null) {
                    $errors[] = 'the alternates produce ID lengths ' . implode(', ', $union) . ', and '
                        . $sw['target'] . ' = ' . implode(' + ', $sw['parts']) . ' — one "member" could '
                        . 'swallow ' . count($sw['parts']) . ' real ones and still verify, so a mis-scan '
                        . 'would be reported as a clean ID. Give the alternates lengths where no length is '
                        . 'the sum of two or more others, or split them into separate fields.';
                }
                // A format-only alternate sharing a length with a check-bearing
                // one accepts first (declaration order is irrelevant — the DP
                // takes any accepting pair), so that length's check character
                // would never actually be tested.
                $shared = array_values(array_unique(array_intersect($roLens, $ckLens)));
                if (!$errors && $shared) {
                    sort($shared);
                    $errors[] = 'a format-only alternate and a check-character alternate are both '
                        . implode(' and ', $shared) . ' characters long. At that length the format-only '
                        . 'alternate would accept the value first, so the check character would never be '
                        . 'tested. Give them different lengths, or drop the format-only alternate.';
                }
            }
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
                $sw = CheckCharacter::swallowSum($ints);
                if ($sw !== null) {
                    $errors[] = 'ID lengths ' . implode(', ', $ints) . ' are unsafe: '
                        . $sw['target'] . ' = ' . implode(' + ', $sw['parts']) . ', so one "member" '
                        . 'could swallow ' . count($sw['parts']) . ' real ones. Split such projects '
                        . 'into separate fields/rules.';
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
        // M-03: regex-only pooled parsing (algorithm "none" — no check character to
        // disambiguate a split) over a VARIABLE ID length is ambiguous: a run can
        // divide into different numbers of members, so an "expected count" cannot be
        // enforced (the parser would pick one division and could misreport the
        // count). Require a single exact length, drop expectedIds, or use a check
        // algorithm (which disambiguates by verification).
        // With alternates the same question is asked of the FORMAT-ONLY ones
        // only: a check-bearing alternate disambiguates its own members by
        // verification, so it cannot create count ambiguity. Two format-only
        // alternates pinned to different lengths reconstitute exactly the
        // variable-length regex-only rule this refuses -- a run of a*b
        // characters divides into b members of length a OR a of length b, both
        // fully accepted, with nothing to prefer. One format-only length is
        // therefore the exact boundary, not a conservative one, and it is what
        // lets a mixed rule (one format-only family + several check-bearing
        // ones) keep "expectedIds".
        if ($type === 'pooled' && !$errors && $hasAlts
                && isset($frag['expectedIds']) && self::posInt($frag['expectedIds'])) {
            $ro = [];
            foreach ($frag['alternates'] as $a) {
                $aAlgo = isset($a['algorithm']) && $a['algorithm'] !== '' ? $a['algorithm'] : $algo;
                if ($aAlgo !== 'none' || !isset($a['lengths']) || !is_array($a['lengths'])) continue;
                foreach ($a['lengths'] as $L) $ro[] = (int) $L;
            }
            if (count(array_unique($ro)) > 1) {
                sort($ro);
                $errors[] = '"expectedIds" cannot be enforced: the format-only alternates are '
                    . implode(' and ', array_values(array_unique($ro))) . ' characters long, and without a '
                    . 'check character a run can split into different numbers of IDs, so the count is '
                    . 'ambiguous. Give the format-only alternates a single length, drop "expectedIds", or '
                    . 'give them a check algorithm (which disambiguates by verification).';
            }
        }
        if ($type === 'pooled' && !$errors && !$hasAlts && $algo === 'none'
                && isset($frag['expectedIds']) && self::posInt($frag['expectedIds'])) {
            $variable = $lens
                ? (count(array_unique(array_map('intval', $lens))) > 1)
                : ((isset($frag['idMaxLen']) && self::posInt($frag['idMaxLen']) ? (int) $frag['idMaxLen'] : 14)
                    > (isset($frag['idMinLen']) && self::posInt($frag['idMinLen']) ? (int) $frag['idMinLen'] : 8));
            if ($variable) {
                $errors[] = '"expectedIds" cannot be enforced on a regex-only pooled field with a '
                    . 'variable ID length: without a check character a run can split into different '
                    . 'numbers of IDs, so the count is ambiguous. Set a single exact ID length, drop '
                    . '"expectedIds", or use a check algorithm (which disambiguates by verification).';
            }
        }
        return $errors;
    }

    /**
     * Shape-check and normalize an "alternates" list, shared by BOTH
     * configuration channels: the @UVALIDATE JSON above and the Configure
     * dialog's box (UniversalValidator::settingRowToRule). Structure only -
     * the semantic gates (pattern safety, the union length proofs, KEEP
     * agreement, the count-ambiguity rule) live in checkFragment, which every
     * channel also goes through, so a rule one channel accepts can never be one
     * another channel rejects.
     *
     * Returns ['alternates' => [...]] or ['error' => '...'].
     */
    public static function normalizeAlternates($alts)
    {
        if (!is_array($alts) || !count($alts)) {
            return ['error' => '"alternates" must be a non-empty LIST of formats, e.g. '
                . '[{"pattern":"FC[1-9]-[0-9]{4}","algorithm":"none","lengths":[8]}].'];
        }
        // Count first: checkFragment refuses anything over the cap anyway, and
        // normalizing 20,000 entries before saying so is pure wasted work.
        if (count($alts) > CheckCharacter::MAX_ALTERNATES) {
            return ['error' => '"alternates" lists ' . count($alts) . ' formats — at most '
                . CheckCharacter::MAX_ALTERNATES . ' are supported.'];
        }
        if (array_keys($alts) !== range(0, count($alts) - 1)) {
            // A JSON object would be ordered differently by the two runtimes
            // (PHP keeps insertion order, JavaScript reorders integer-like
            // keys), and alternates are tried in DECLARATION order.
            return ['error' => '"alternates" must be a JSON list [ ... ], not an object { ... } - '
                . 'the browser and the server order object keys differently, and alternates are '
                . 'tried in the order you write them.'];
        }
        $clean = [];
        foreach ($alts as $i => $a) {
            $nm = 'alternate ' . ($i + 1);
            if (!is_array($a) || ($a !== [] && array_keys($a) === range(0, count($a) - 1))) {
                return ['error' => '"alternates" entry ' . ($i + 1) . ' must be an object with a "pattern".'];
            }
            $unknown = array_diff(array_keys($a), self::ALT_KEYS);
            if ($unknown) {
                return ['error' => $nm . ' has unknown option(s): ' . implode(', ', $unknown)
                    . '. Valid: ' . implode(', ', self::ALT_KEYS) . '.'];
            }
            if (!isset($a['pattern']) || !is_string($a['pattern']) || $a['pattern'] === '') {
                return ['error' => $nm . ' needs a non-empty "pattern" - an alternate without one '
                    . 'would accept every value and the alternates after it would never be reached.'];
            }
            if (isset($a['algorithm'])) {
                if (!is_string($a['algorithm'])) return ['error' => $nm . ': "algorithm" must be a string.'];
                $a['algorithm'] = self::canonicalAlgorithm($a['algorithm']);
            }
            foreach (['source', 'strip', 'label'] as $k) {
                if (isset($a[$k]) && !is_string($a[$k])) return ['error' => $nm . ': "' . $k . '" must be a string.'];
            }
            if (isset($a['lengths'])) {
                $lens = $a['lengths'];
                if (is_string($lens)) $lens = array_map('trim', explode(',', $lens));
                if (!is_array($lens) || !count($lens)) {
                    return ['error' => $nm . ': "lengths" must be a list of positive whole numbers, e.g. [8].'];
                }
                foreach ($lens as $L) {
                    if (!self::posInt($L)) {
                        return ['error' => $nm . ': "lengths" must be positive whole numbers - got '
                            . json_encode($L) . '.'];
                    }
                }
                // L-01: bound the list here too. The union check only runs on
                // the POOLED path, so without this a single-type rule could
                // carry an arbitrarily long list into stored config.
                $a['lengths'] = array_values(array_unique(array_map('intval', $lens)));
                if (count($a['lengths']) > CheckCharacter::MAX_LEN_CHOICES) {
                    return ['error' => $nm . ': "lengths" lists ' . count($a['lengths'])
                        . ' values — at most ' . CheckCharacter::MAX_LEN_CHOICES . ' are supported.'];
                }
            }
            $clean[] = $a;
        }
        return ['alternates' => $clean];
    }

    /**
     * Which characters ONE alternate needs to survive the pooled cleaner: the
     * base alphabet, its algorithm's check alphabet (mod37_2 can emit "*"), and
     * every literal separator its pattern uses. Twin of
     * CheckCharacter::pooledKeepFor and keepFor (js) -- used here only to prove
     * the alternates agree, since cleaning runs once for the whole field.
     */
    private static function keepSetFor($base, $algo, $pattern)
    {
        $K = $base;
        if ($algo !== 'none') {
            $CA = CheckCharacter::checkAlphabet($algo);
            for ($i = 0; $i < strlen($CA); $i++) {
                if (strpos($K, $CA[$i]) === false) $K .= $CA[$i];
            }
        }
        $pat = (string) $pattern; $meta = '\\^$.|?*+()[]{}';
        for ($i = 0; $i < strlen($pat); $i++) {
            $pc = $pat[$i];
            if ($pc === '\\') {
                $i++;
                if ($i < strlen($pat)) {
                    $pc = $pat[$i];
                    if (strpos($meta, $pc) !== false && strpos($K, $pc) === false) $K .= $pc;
                }
                continue;
            }
            if (strpos($meta, $pc) === false && !preg_match('/[A-Za-z0-9]/', $pc) && strpos($K, $pc) === false) {
                $K .= $pc;
            }
        }
        return $K;
    }

    /**
     * Semantic validation for a constraint (@UVASSERT) fragment. The "assert"
     * condition and the optional "when" gate share the normative dialect spec
     * in php/Logic.php; dictionary-dependent reference checks (do the fields
     * exist) happen in the channel glue where the data dictionary is in hand.
     * A missing "message" is allowed — the client shows a generic wording — but
     * a message is strongly recommended (only the designer can word what an
     * arbitrary relationship means).
     */
    public static function checkConstraint(array $frag)
    {
        $errors = [];
        $assert = isset($frag['assert']) ? $frag['assert'] : null;
        if (!is_string($assert) || trim($assert) === '') {
            $errors[] = self::TAG_ASSERT . ' needs a non-empty "assert" condition, '
                . 'e.g. "[end_date]>=[start_date]".';
        } else {
            $a = Logic::parse($assert);
            if (empty($a['ok'])) $errors[] = 'the "assert" condition ' . $a['error'];
        }
        if (isset($frag['when'])) {
            if (!is_string($frag['when'])) {
                $errors[] = 'the "when" condition must be a non-empty condition string.';
            } else {
                $w = Logic::parse($frag['when']);
                if (empty($w['ok'])) $errors[] = 'the "when" condition ' . $w['error'];
            }
        }
        if (isset($frag['blockSave'])
            && !in_array($frag['blockSave'], ['off', 'confirm', 'hard'], true)) {
            $errors[] = '"blockSave" must be off, confirm or hard.';
        }
        if (isset($frag['message']) && !is_string($frag['message'])) {
            $errors[] = '"message" must be a string.';
        }
        return $errors;
    }

    /**
     * Semantic validation for a required (@UVREQUIRED) fragment: optional
     * "when" gate (normative dialect in php/Logic.php), optional "message"
     * wording, optional blockSave. There is no condition to require — the
     * bare tag is complete.
     */
    public static function checkRequired(array $frag)
    {
        $errors = [];
        if (isset($frag['when'])) {
            if (!is_string($frag['when']) || trim($frag['when']) === '') {
                $errors[] = 'the "when" condition must be a non-empty condition string.';
            } else {
                $w = Logic::parse($frag['when']);
                if (empty($w['ok'])) $errors[] = 'the "when" condition ' . $w['error'];
            }
        }
        if (isset($frag['blockSave'])
            && !in_array($frag['blockSave'], ['off', 'confirm', 'hard'], true)) {
            $errors[] = '"blockSave" must be off, confirm or hard.';
        }
        if (isset($frag['message']) && !is_string($frag['message'])) {
            $errors[] = '"message" must be a string.';
        }
        return $errors;
    }

    /**
     * Semantic validation for a unique (@UVUNIQUE) fragment: scope whitelist,
     * composite "with" list shape (valid field-name syntax, no duplicates,
     * capped), optional when/message/blockSave/surveys. Whether the with-fields
     * exist (and are scalar) is checked in the channel glue with the data
     * dictionary in hand.
     */
    public static function checkUnique(array $frag)
    {
        $errors = [];
        if (isset($frag['uniqueScope'])
            && !in_array($frag['uniqueScope'], self::UNIQUE_SCOPES, true)) {
            $errors[] = '"scope" must be ' . implode(', ', self::UNIQUE_SCOPES) . '.';
        }
        if (isset($frag['uniqueWith'])) {
            $with = $frag['uniqueWith'];
            if (!is_array($with) || !$with) {
                $errors[] = '"with" must be a non-empty list of field names.';
            } elseif (count($with) > self::MAX_UNIQUE_WITH) {
                $errors[] = '"with" is limited to ' . self::MAX_UNIQUE_WITH . ' fields.';
            } else {
                $seen = [];
                foreach ($with as $w) {
                    if (!is_string($w) || !preg_match('/^[a-z][a-z0-9_]*$/', strtolower($w))) {
                        $errors[] = '"with" entry ' . json_encode($w) . ' is not a valid REDCap field name.';
                        break;
                    }
                    $lw = strtolower($w);
                    if (isset($seen[$lw])) { $errors[] = '"with" lists "' . $lw . '" twice.'; break; }
                    $seen[$lw] = true;
                }
            }
        }
        if (isset($frag['uniqueSurveys']) && !is_bool($frag['uniqueSurveys'])) {
            $errors[] = '"surveys" must be true or false (unquoted).';
        }
        if (isset($frag['when'])) {
            if (!is_string($frag['when']) || trim($frag['when']) === '') {
                $errors[] = 'the "when" condition must be a non-empty condition string.';
            } else {
                $w = Logic::parse($frag['when']);
                if (empty($w['ok'])) $errors[] = 'the "when" condition ' . $w['error'];
            }
        }
        if (isset($frag['blockSave'])
            && !in_array($frag['blockSave'], ['off', 'confirm', 'hard'], true)) {
            $errors[] = '"blockSave" must be off, confirm or hard.';
        }
        if (isset($frag['message']) && !is_string($frag['message'])) {
            $errors[] = '"message" must be a string.';
        }
        return $errors;
    }

    /**
     * Semantic validation for a choices (@UVCHOICES) fragment: exactly one of
     * choicesShow/choicesHide, a non-empty deduplicated code list under the
     * cap, optional when/message/blockSave. Whether the codes exist in the
     * field's own choice list is checked in the channel glue with the data
     * dictionary in hand (getAnnotationRules), which also attaches choicesAll.
     */
    public static function checkChoices(array $frag)
    {
        $errors = [];
        $hasShow = isset($frag['choicesShow']);
        $hasHide = isset($frag['choicesHide']);
        if ($hasShow === $hasHide) {
            $errors[] = $hasShow
                ? '"show" and "hide" cannot be combined — a whitelist already says everything about the other codes.'
                : self::TAG_CHOICES . ' needs a "show" or "hide" list of choice codes.';
        }
        foreach (['choicesShow' => 'show', 'choicesHide' => 'hide'] as $ruleKey => $label) {
            if (!isset($frag[$ruleKey])) continue;
            $codes = $frag[$ruleKey];
            if (!is_array($codes) || !$codes) {
                $errors[] = '"' . $label . '" must be a non-empty list of choice codes.';
                continue;
            }
            if (count($codes) > self::MAX_CHOICE_CODES) {
                $errors[] = '"' . $label . '" is limited to ' . self::MAX_CHOICE_CODES . ' codes.';
                continue;
            }
            $seen = [];
            foreach ($codes as $c) {
                if (!is_string($c) || $c === '') {
                    $errors[] = '"' . $label . '" entry ' . json_encode($c) . ' is not a choice code.';
                    break;
                }
                if (isset($seen[$c])) { $errors[] = '"' . $label . '" lists code "' . $c . '" twice.'; break; }
                $seen[$c] = true;
            }
        }
        if (isset($frag['when'])) {
            if (!is_string($frag['when']) || trim($frag['when']) === '') {
                $errors[] = 'the "when" condition must be a non-empty condition string.';
            } else {
                $w = Logic::parse($frag['when']);
                if (empty($w['ok'])) $errors[] = 'the "when" condition ' . $w['error'];
            }
        }
        if (isset($frag['blockSave'])
            && !in_array($frag['blockSave'], ['off', 'confirm', 'hard'], true)) {
            $errors[] = '"blockSave" must be off, confirm or hard.';
        }
        if (isset($frag['message']) && !is_string($frag['message'])) {
            $errors[] = '"message" must be a string.';
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
     * Group per-field fragments (field => ONE fragment) into engine rules —
     * compatibility form over groupMulti().
     */
    public static function group(array $perField)
    {
        $wrapped = [];
        foreach ($perField as $field => $frag) $wrapped[$field] = [$frag];
        return self::groupMulti($wrapped);
    }

    /**
     * Group per-field fragment LISTS (field => [fragments] from parseFieldAll)
     * into engine rules: fragments with identical configs share one rule, so
     * 50 tagged fields with the same tag produce one rule with 50 fields, not
     * 50 rules. Error fragments become per-field config-error rules so the
     * message shows exactly on the mis-tagged field. A field carrying several
     * DIFFERENT tags contributes to several rules — Branching::resolve() then
     * turns that sharing into a branch rule (or a config error when illegal).
     * Two byte-identical tags on one field collapse into a single claim.
     */
    public static function groupMulti(array $perField)
    {
        $rules = [];
        $byKey = [];
        foreach ($perField as $field => $frags) {
            foreach ($frags as $frag) {
                if (isset($frag['error'])) {
                    $tag = isset($frag['_tag']) ? $frag['_tag'] : self::TAG;
                    $rules[] = [
                        'type' => 'single', 'fields' => [$field],
                        'configError' => $tag . ' on "' . $field . '": ' . $frag['error'],
                    ];
                    continue;
                }
                $canon = $frag;
                unset($canon['_tag']);
                ksort($canon);
                $key = json_encode($canon);
                if (!isset($byKey[$key])) {
                    $rule = $frag;
                    $rule['type'] = $frag['type'] ?? 'single';
                    $rule['fields'] = [];
                    $byKey[$key] = count($rules);
                    $rules[] = $rule;
                }
                if (!in_array($field, $rules[$byKey[$key]]['fields'], true)) {
                    $rules[$byKey[$key]]['fields'][] = $field;
                }
            }
        }
        return $rules;
    }
}
