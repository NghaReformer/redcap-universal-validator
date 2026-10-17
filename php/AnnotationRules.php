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
require_once __DIR__ . '/ModeRegistry.php';
require_once __DIR__ . '/TemporalLogic.php';
require_once __DIR__ . '/GrowthReference.php';
require_once __DIR__ . '/GrowthOutputs.php';
require_once __DIR__ . '/GrowthCurve.php';

class AnnotationRules
{
    const TAG = '@UVALIDATE';
    const TAG_ASSERT = '@UVASSERT';
    const TAG_REQUIRED = '@UVREQUIRED';
    const TAG_UNIQUE = '@UVUNIQUE';
    const TAG_CHOICES = '@UVCHOICES';
    const TAG_WINDOW = '@UVWINDOW';
    const TAG_EXISTS = '@UVEXISTS';
    const TAG_RANGE = '@UVRANGE';

    /** Most languages one text key (a "message" map) may name, "default" included. */
    const MAX_TEXT_LANGUAGES = 50;

    /** Longest @UVRANGE "unit" text: a label such as "g/dL", not a sentence. */
    const MAX_RANGE_UNIT = 20;
    /** Longest @UVRANGE limit, in characters once any exponent is written out. */
    const MAX_RANGE_BOUND = 64;
    /** With a growth "reference", the limits are z-scores no further than this from 0. */
    const MAX_GROWTH_Z = 20;
    /** "measureScale" bounds: a millionth (micrograms to grams) to a million. */
    const MIN_MEASURE_SCALE = '0.000001';
    const MAX_MEASURE_SCALE = '1000000';
    /** Cap on @UVRANGE "return" pairs; what each key may name is php/growth_outputs.json (GrowthOutputs). */
    const MAX_RANGE_RETURN = 16;
    /** The limits a rule's returned bounds come from ("returnLimits"; soft is the default). */
    const RETURN_LIMITS = ['soft', 'hard'];

    /** Where an @UVEXISTS lookup may search: the whole project (default), the
     *  record's own Data Access Group, or the event of the entry being checked. */
    const EXISTS_SCOPES = ['project', 'dag', 'event'];

    /** Cap on @UVEXISTS "match" pairs, so a lookup stays one narrow read. */
    const MAX_EXISTS_MATCH = 5;

    /** Cap on @UVEXISTS "return" pairs: the fields one lookup writes into this form. */
    const MAX_EXISTS_RETURN = 10;

    /** Cap on the distinct fields an @UVEXISTS "where" condition tests in the entry found. */
    const MAX_EXISTS_WHERE = 10;

    /**
     * An @UVEXISTS "project": a project id, or an alias the project's own
     * settings map to one (so a data dictionary moves between servers unchanged).
     */
    const EXISTS_PROJECT_RE = '/^(?:[1-9][0-9]{0,9}|[a-z][a-z0-9_-]{0,39})$/';

    /** Largest window bound, in units either side of the anchor. */
    const MAX_WINDOW_BOUND = 36500;
    /** The most periods a @UVWINDOW "offset" moves either way (100 years of months). */
    const MAX_WINDOW_OFFSET = 1200;
    /** A date written in a @UVWINDOW "from": Y-M-D, with an optional time to the minute or second. */
    const WINDOW_LITERAL_RE = '/^\d{4}-\d{2}-\d{2}(?: \d{2}:\d{2}(?::\d{2})?)?$/D';

    /*
     * Which tag configures which validation MODE, the field types each mode
     * accepts, and the parser/validator each one uses all live in
     * php/modes.json (served by ModeRegistry). Different modes on one field
     * COMPOSE (all must pass); several tags of the SAME mode on one field
     * branch (php/Branching.php). The mode travels on the rule as its "type"
     * (single|pooled = check; constraint; required; ...), which is what the
     * client dispatcher, the server audit, and Branching all key on.
     */

    /** Uniqueness scopes: whole project (default), within the record's Data
     *  Access Group, or within the same event of a longitudinal project. */
    const UNIQUE_SCOPES = ['project', 'dag', 'event'];

    /** Composite-key size cap: [field]+with must stay a cheap lookup. */
    const MAX_UNIQUE_WITH = 5;

    /** Cap on the other fields a unique value is also looked for in ("also"):
     *  each one widens every lookup's read by one field. */
    const MAX_UNIQUE_ALSO = 5;

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
                       'suggestFix', 'note', 'caseSensitive'];

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
        return array_column(self::extractTagsDetailed($annotation, $tag), 'value');
    }

    /**
     * extractTagsFor with each value's reading problem: a list of
     * ['value' => string, 'error' => string|null]. $bare says how an unquoted,
     * non-JSON value ends: 'token' (at the first space, the default) or
     * 'condition' (the whole condition, for the tags whose bare value IS one —
     * see Logic::parsePrefix). A condition that goes on past the point where it
     * can be read gets an error instead of being cut short.
     */
    public static function extractTagsDetailed($annotation, $tag, $bare = 'token')
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
                $out[] = ['value' => '', 'error' => null];
                $offset = $after;
                continue;
            }
            $read = self::readValue(substr($ann, $after + 1), $bare);
            $eg = $tag . '="[a]=\'1\' and [b]=\'2\'".';
            $out[] = ['value' => $read['value'], 'error' => !$read['continues'] ? null
                : (isset($read['hint'])
                    ? 'reads as the condition "' . $read['value'] . '" followed by more of a condition, which ' . $read['hint']
                        . ' Then put the whole condition in quotes, e.g. ' . $eg
                    : 'reads as the condition "' . $read['value'] . '" followed by more of a condition — put the whole condition in quotes, e.g. '
                        . $eg)];
            // Advance PAST the consumed value so tag-like text inside a value
            // (e.g. a pattern containing "@UVALIDATE") is never re-read as a
            // second tag. Quoted values also consumed their two quote marks.
            $offset = $after + 1 + $read['length'];
        }
        return $out;
    }

    /**
     * Read one tag value: {json} (brace-balanced), quoted text, or a bare
     * value — one token, or with $bare 'condition' the longest condition.
     * Returns ['value', 'length' => bytes consumed, 'continues' => bool], plus
     * 'hint' (Logic::parsePrefix) when a condition goes on with "&&", "||" or "!".
     */
    private static function readValue($rest, $bare = 'token')
    {
        $plain = function ($v, $length = null) {
            return ['value' => $v, 'length' => $length === null ? strlen($v) : $length, 'continues' => false];
        };
        if ($rest === '') return $plain('');
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
                    if ($depth === 0) return $plain(substr($rest, 0, $i + 1));
                }
            }
            return $plain($rest); // unbalanced — parseField reports it as bad JSON
        }
        if ($c === '"' || $c === "'") {
            $end = strpos($rest, $c, 1);
            return $end === false ? $plain(substr($rest, 1), strlen($rest))
                : $plain(substr($rest, 1, $end - 1), $end + 1);
        }
        if ($bare === 'condition') {
            $p = Logic::parsePrefix($rest);
            if ($p['length'] > 0) {
                return ['value' => substr($rest, 0, $p['length']), 'length' => $p['length'], 'continues' => $p['continues'],
                        'hint' => $p['hint']];
            }
            // nothing parses: keep the first token, so the parser reports the
            // condition's own error, as before
        }
        preg_match('/^\S+/', $rest, $m);
        return $plain($m ? $m[0] : '');
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
    public static function parseFieldAll($annotation, array $opts = [])
    {
        $tags = self::extractTags($annotation);
        if (!$tags) return null;
        $out = [];
        foreach ($tags as $val) $out[] = self::parseValue($val, $opts);
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
    public static function parseAllTags($annotation, array $opts = [])
    {
        $any = false;
        $out = [];
        foreach (ModeRegistry::tagMap() as $tag => $mode) {
            $parser = ModeRegistry::mode($mode)['parser'];
            foreach (self::extractTagsDetailed($annotation, $tag, ModeRegistry::bareValue($mode)) as $read) {
                $any = true;
                $frag = $read['error'] !== null ? ['error' => $tag . ' ' . $read['error']] : self::$parser($read['value'], $opts);
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
     *              "message":"...","blockSave":"hard","surveys":true,
     *              "also":["typed_id"]}
     * "with" makes the key composite (value + those fields together must be
     * unique). "also" looks for the value in those other fields of the other
     * records too, so a value saved in either field counts as used.
     * "surveys" is an explicit OPT-IN: a live used/free answer is
     * record-derived information, so survey respondents only get the check
     * when the designer decides the trade-off is acceptable (the server
     * answers surveys with a boolean only, never a record id).
     */
    private static function parseUniqueValue($val, array $opts = [])
    {
        $val = trim($val);
        if ($val === '') {
            $out = ['type' => 'unique'];
            $errs = self::checkFragment($out, $opts);
            return $errs ? ['error' => implode(' ', $errs)] : $out;
        }
        if ($val[0] !== '{') {
            $scope = strtolower($val);
            if (!in_array($scope, !empty($opts['qualified']) ? array_merge(self::UNIQUE_SCOPES, ['record']) : self::UNIQUE_SCOPES, true)) {
                return ['error' => self::TAG_UNIQUE . '=' . $val . ' is not a scope — use '
                    . implode(', ', self::UNIQUE_SCOPES) . ', or the JSON form for other options.'];
            }
            $out = ['type' => 'unique', 'uniqueScope' => $scope];
            $errs = self::checkFragment($out, $opts);
            return $errs ? ['error' => implode(' ', $errs)] : $out;
        }
        $cfg = json_decode($val, true);
        if (!is_array($cfg)) {
            return ['error' => self::TAG_UNIQUE . ' JSON does not parse ('
                . json_last_error_msg() . ') — use double quotes around keys and string values.'];
        }
        $allowed = ['with', 'also', 'scope', 'when', 'message', 'blockSave', 'surveys', 'caseSensitive', 'references'];
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
        if (isset($cfg['also'])) {
            if (!is_array($cfg['also'])) return ['error' => '"also" must be a list of field names, e.g. ["typed_id"].'];
            $out['uniqueAlso'] = array_map(function ($a) {
                return is_string($a) ? strtolower(trim($a)) : $a;
            }, array_values($cfg['also']));
        }
        if (isset($cfg['scope'])) {
            if (!is_string($cfg['scope'])) return ['error' => '"scope" must be a string.'];
            $out['uniqueScope'] = strtolower($cfg['scope']);
        }
        if (isset($cfg['surveys'])) {
            if (!is_bool($cfg['surveys'])) return ['error' => '"surveys" must be true or false (unquoted).'];
            $out['uniqueSurveys'] = $cfg['surveys'];
        }
        if (($e = self::takeTexts($cfg, $out)) !== null) return ['error' => $e];
        foreach (['when', 'message', 'blockSave'] as $k) {
            if (isset($cfg[$k])) {
                if (!is_string($cfg[$k])) return ['error' => '"' . $k . '" must be a string.'];
                $out[$k] = $cfg[$k];
            }
        }
        self::takeCaseSensitive($cfg, $out);
        $errs = self::checkFragment($out, $opts);
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
    private static function parseChoicesValue($val, array $opts = [])
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
        $allowed = ['when', 'show', 'hide', 'message', 'blockSave', 'caseSensitive', 'references'];
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
        if (($e = self::takeTexts($cfg, $out)) !== null) return ['error' => $e];
        foreach (['when', 'message', 'blockSave'] as $k) {
            if (isset($cfg[$k])) {
                if (!is_string($cfg[$k])) return ['error' => '"' . $k . '" must be a string.'];
                $out[$k] = $cfg[$k];
            }
        }
        self::takeCaseSensitive($cfg, $out);
        $errs = self::checkFragment($out, $opts);
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
    private static function parseRequiredValue($val, array $opts = [])
    {
        $val = trim($val);
        if ($val === '') {
            $out = ['type' => 'required'];
            $errs = self::checkFragment($out, $opts);
            return $errs ? ['error' => implode(' ', $errs)] : $out;
        }
        if ($val[0] !== '{') {
            $out = ['type' => 'required', 'when' => $val];
            $errs = self::checkFragment($out, $opts);
            return $errs ? ['error' => implode(' ', $errs)] : $out;
        }
        $cfg = json_decode($val, true);
        if (!is_array($cfg)) {
            return ['error' => self::TAG_REQUIRED . ' JSON does not parse ('
                . json_last_error_msg() . ') — use double quotes around keys and string values.'];
        }
        $strings = ['when', 'message', 'blockSave'];
        $allowed = array_merge($strings, ['caseSensitive', 'references']);
        $unknown = array_diff(array_keys($cfg), $allowed);
        if ($unknown) {
            return ['error' => 'unknown ' . self::TAG_REQUIRED . ' option(s): ' . implode(', ', $unknown)
                . ' — valid: ' . implode(', ', $allowed) . '.'];
        }
        $out = ['type' => 'required'];
        if (($e = self::takeTexts($cfg, $out)) !== null) return ['error' => $e];
        foreach ($strings as $k) {
            if (isset($cfg[$k])) {
                if (!is_string($cfg[$k])) return ['error' => '"' . $k . '" must be a string.'];
                $out[$k] = $cfg[$k];
            }
        }
        self::takeCaseSensitive($cfg, $out);
        $errs = self::checkFragment($out, $opts);
        return $errs ? ['error' => implode(' ', $errs)] : $out;
    }

    /**
     * Parse one @UVASSERT value into a constraint fragment. The value is EITHER
     * the condition itself (@UVASSERT="[end]>=[start]") or a JSON object
     * {assert, message, blockSave, when, caseSensitive}. The field is invalid
     * whenever the condition is false; an empty field is inert (that is
     * @UVREQUIRED's job). Text compares case-insensitively ('Yes' = 'yes')
     * unless "caseSensitive" is true, in the "assert" and the "when" alike.
     */
    private static function parseAssertValue($val, array $opts = [])
    {
        $val = trim($val);
        if ($val === '') {
            return ['error' => self::TAG_ASSERT . ' needs a condition — e.g. '
                . self::TAG_ASSERT . '="[end_date]>=[start_date]".'];
        }
        if ($val[0] !== '{') {
            $out = ['type' => 'constraint', 'assert' => $val];
            $errs = self::checkFragment($out, $opts);
            return $errs ? ['error' => implode(' ', $errs)] : $out;
        }
        $cfg = json_decode($val, true);
        if (!is_array($cfg)) {
            return ['error' => self::TAG_ASSERT . ' JSON does not parse ('
                . json_last_error_msg() . ') — use double quotes around keys and string values.'];
        }
        $strings = ['assert', 'message', 'blockSave', 'when'];
        $allowed = array_merge($strings, ['caseSensitive', 'references']);
        $unknown = array_diff(array_keys($cfg), $allowed);
        if ($unknown) {
            return ['error' => 'unknown ' . self::TAG_ASSERT . ' option(s): ' . implode(', ', $unknown)
                . ' — valid: ' . implode(', ', $allowed) . '.'];
        }
        $out = ['type' => 'constraint'];
        if (($e = self::takeTexts($cfg, $out)) !== null) return ['error' => $e];
        foreach ($strings as $k) {
            if (isset($cfg[$k])) {
                if (!is_string($cfg[$k])) return ['error' => '"' . $k . '" must be a string.'];
                $out[$k] = $cfg[$k];
            }
        }
        self::takeCaseSensitive($cfg, $out);
        $errs = self::checkFragment($out, $opts);
        return $errs ? ['error' => implode(' ', $errs)] : $out;
    }

    /**
     * Parse one @UVWINDOW value into a window fragment (a date must fall in a
     * window around another date, and/or not after today). JSON form only:
     *   @UVWINDOW={"from":"[visit_date_bl]","window":[21,35],"unit":"days"}
     *   @UVWINDOW={"from":"[dob]","window":[0,null]}          not before the anchor
     *   @UVWINDOW={"notFuture":true}                          not after today
     *   @UVWINDOW={"notPast":true}                            not before today
     *   @UVWINDOW={"from":"today","window":[-30,0]}           within the last 30 days
     *   @UVWINDOW={"from":"[enrol]","window":[0,6],"unit":"months"}
     *   @UVWINDOW={"period":"month","offset":-1}              last month
     *   @UVWINDOW={"period":"week","from":"[visit_date]"}     the week of visit_date
     * "window" is [earliest, latest] in whole units from the "from" date,
     * inclusive; null leaves that side open. "from" is a field reference, which
     * may name another event ([baseline_arm_1][visit_date]) once event and
     * instance references are enabled, or "today", "now" or a Y-M-D date
     * (windowAnchor). A "period" (week, month, quarter, year) is the calendar
     * period holding the "from" date, today when there is none, moved by
     * "offset" periods (a whole number or [first, last]). Whether the field
     * and its "from" field are date fields of the same kind is checked with
     * the data dictionary in hand (UniversalValidator::annotateWindowField /
     * annotateWindowDictionary).
     */
    private static function parseWindowValue($val, array $opts = [])
    {
        $val = trim($val);
        if ($val === '' || $val[0] !== '{') {
            return ['error' => self::TAG_WINDOW . ' needs its settings as JSON, e.g. '
                . self::TAG_WINDOW . '={"from":"[visit_date_bl]","window":[21,35]} or '
                . self::TAG_WINDOW . '={"notFuture":true}.'];
        }
        $cfg = json_decode($val, true);
        if (!is_array($cfg)) {
            return ['error' => self::TAG_WINDOW . ' JSON does not parse ('
                . json_last_error_msg() . ') — use double quotes around keys and string values.'];
        }
        $allowed = ['from', 'window', 'unit', 'period', 'offset', 'weekStart', 'notFuture', 'notPast',
                    'when', 'message', 'blockSave', 'caseSensitive', 'references'];
        $unknown = array_diff(array_keys($cfg), $allowed);
        if ($unknown) {
            return ['error' => 'unknown ' . self::TAG_WINDOW . ' option(s): ' . implode(', ', $unknown)
                . ' — valid: ' . implode(', ', $allowed) . '.'];
        }
        $out = ['type' => 'window'];
        if (array_key_exists('from', $cfg)) {
            if (!is_string($cfg['from'])) {
                return ['error' => '"from" must be a field reference such as "[visit_date]", "today", "now" or a date such as "2026-01-01".'];
            }
            $from = trim($cfg['from']);
            $kw = strtolower($from);
            // "today", "now" and a written date are not fields: they travel
            // as windowAnchor, so no field-reference path ever reads them.
            if ($kw === 'today' || $kw === 'now') $out['windowAnchor'] = $kw;
            elseif (preg_match(self::WINDOW_LITERAL_RE, $from)) $out['windowAnchor'] = $from;
            else $out['windowFrom'] = $from;
        }
        if (array_key_exists('period', $cfg)) {
            if (!is_string($cfg['period'])) return ['error' => '"period" must be week, month, quarter or year.'];
            $out['windowPeriod'] = strtolower(trim($cfg['period']));
            // A period with no "from" is the period holding today.
            if (!isset($out['windowFrom']) && !isset($out['windowAnchor'])) $out['windowAnchor'] = 'today';
        }
        if (array_key_exists('offset', $cfg)) {
            $o = $cfg['offset'];
            if (is_array($o)) {
                if (array_keys($o) !== [0, 1]) {
                    return ['error' => '"offset" must be a whole number of periods, or [first, last] — e.g. -1 for the last one, [-3,-1] for the three before this one.'];
                }
                $out['windowOffLo'] = $o[0];
                $out['windowOffHi'] = $o[1];
            } else {
                $out['windowOffLo'] = $o;
                $out['windowOffHi'] = $o;
            }
            // An offset of 0 is the default, dropped so two spellings of one rule group as one.
            if (isset($out['windowPeriod']) && $out['windowOffLo'] === 0 && $out['windowOffHi'] === 0) {
                unset($out['windowOffLo'], $out['windowOffHi']);
            }
        }
        if (array_key_exists('weekStart', $cfg)) {
            if (!is_string($cfg['weekStart'])) return ['error' => '"weekStart" must be monday or sunday.'];
            $out['windowWeekStart'] = strtolower(trim($cfg['weekStart']));
        }
        if (array_key_exists('window', $cfg)) {
            $w = $cfg['window'];
            if (!is_array($w) || array_keys($w) !== [0, 1]) {
                return ['error' => '"window" must be a list of two bounds, [earliest, latest] — e.g. [21,35], or [0,null] for an open end.'];
            }
            if ($w[0] === null && $w[1] === null) {
                return ['error' => '"window" needs at least one bound — [null,null] would check nothing.'];
            }
            // An open side is simply absent from the rule, so two rules that
            // differ only in how they spelled "open" still group as one.
            if ($w[0] !== null) $out['windowLo'] = $w[0];
            if ($w[1] !== null) $out['windowHi'] = $w[1];
        }
        if (array_key_exists('unit', $cfg)) {
            if (!is_string($cfg['unit'])) return ['error' => '"unit" must be a string.'];
            $out['windowUnit'] = strtolower(trim($cfg['unit']));
        } elseif ((isset($out['windowFrom']) || isset($out['windowAnchor'])) && !isset($out['windowPeriod'])) {
            $out['windowUnit'] = 'days';
        }
        // An explicit false IS the default, so it is dropped, as caseSensitive is.
        if (array_key_exists('notFuture', $cfg) && $cfg['notFuture'] !== false) $out['windowNotFuture'] = $cfg['notFuture'];
        if (array_key_exists('notPast', $cfg) && $cfg['notPast'] !== false) $out['windowNotPast'] = $cfg['notPast'];
        if (($e = self::takeTexts($cfg, $out)) !== null) return ['error' => $e];
        foreach (['when', 'message', 'blockSave'] as $k) {
            if (isset($cfg[$k])) {
                if (!is_string($cfg[$k])) return ['error' => '"' . $k . '" must be a string.'];
                $out[$k] = $cfg[$k];
            }
        }
        self::takeCaseSensitive($cfg, $out);
        $errs = self::checkFragment($out, $opts);
        return $errs ? ['error' => implode(' ', $errs)] : $out;
    }

    /**
     * Parse one @UVEXISTS value into an exists fragment. The value must already
     * be saved somewhere in the project:
     *   @UVEXISTS=record                  a record ID of this project
     *   @UVEXISTS=[specimen_id]           a saved value of field specimen_id
     *   @UVEXISTS={"in":"[specimen_id]","event":"enrol_arm_1","scope":"dag",
     *              "match":{"site_code":"[site]"},"when":"...","message":"...",
     *              "blockSave":"hard","surveys":true}
     * "match" narrows the lookup to saved entries whose target field holds the
     * same value as a field of this record (target field => [local field]).
     * "surveys" is an opt-in, as for @UVUNIQUE: a found / not found answer is
     * record-derived information.
     * "project" (a project id or alias) looks in another project instead. The
     * searched fields are then that project's ("existsRemoteTargets"), so they
     * never enter this project's field checks, read set or reverse dependencies.
     * "return" writes fields of the entry found into fields of this form
     * (field there => [field here], "record" for the record ID found):
     *   "return":{"lab_site":"[result_site]","record":"[lab_record]"}
     * It becomes existsReturn (the map, kept on the server: the page never
     * learns where the values come from) and existsReturnFields (the fields
     * here, which the page fills).
     * "where" is a condition on fields of the entry found (of the other
     * project with "project"): only an entry where it holds counts as found.
     *   "where":"[consent]='1' and ([status]<>'3' or [status]='')"
     * It becomes existsWhere (the text) and existsWhereFields (the fields it
     * tests), both kept on the server; the fields join the searched fields
     * (existsTargets, or existsRemoteTargets with "project"), so rights,
     * Identifier checks, another project's agreement and the scan's read set
     * cover them as they cover "in". "whereMessage" is what staff see when
     * the value is saved but no entry holding it meets "where".
     */
    private static function parseExistsValue($val, array $opts = [])
    {
        $val = trim($val);
        if ($val === '') {
            return ['error' => self::TAG_EXISTS . ' needs to know where to look: ' . self::TAG_EXISTS . '=record, '
                . self::TAG_EXISTS . '=[field], or the JSON form with "in".'];
        }
        if ($val[0] !== '{') {
            $in = self::existsSource($val);
            if ($in === false) return ['error' => self::EXISTS_RECORD_FIELD];
            if ($in === null) {
                return ['error' => self::TAG_EXISTS . '=' . $val . ' is not a place to look — use record, one field '
                    . 'reference such as [specimen_id], or the JSON form for other options.'];
            }
            $out = ['type' => 'exists', 'existsIn' => $in];
            if ($in !== 'record') $out['existsTargets'] = [$in];
            $errs = self::checkFragment($out, $opts);
            return $errs ? ['error' => implode(' ', $errs)] : $out;
        }
        $cfg = json_decode($val, true);
        if (!is_array($cfg)) {
            return ['error' => self::TAG_EXISTS . ' JSON does not parse ('
                . json_last_error_msg() . ') — use double quotes around keys and string values.'];
        }
        $allowed = ['in', 'project', 'event', 'scope', 'match', 'when', 'message', 'blockSave', 'surveys', 'caseSensitive', 'return',
                    'where', 'whereMessage'];
        $unknown = array_diff(array_keys($cfg), $allowed);
        if ($unknown) {
            return ['error' => 'unknown ' . self::TAG_EXISTS . ' option(s): ' . implode(', ', $unknown)
                . ' — valid: ' . implode(', ', $allowed) . '.'];
        }
        if (!isset($cfg['in'])) {
            return ['error' => self::TAG_EXISTS . ' needs "in": "record" or one field reference such as "[specimen_id]".'];
        }
        if (!is_string($cfg['in'])) return ['error' => '"in" must be a string.'];
        $in = self::existsSource($cfg['in']);
        if ($in === false) return ['error' => self::EXISTS_RECORD_FIELD];
        if ($in === null) {
            return ['error' => '"in" must be "record" or one field reference such as "[specimen_id]" — got '
                . json_encode($cfg['in']) . '. To look in one event, add "event".'];
        }
        $out = ['type' => 'exists', 'existsIn' => $in];
        if (isset($cfg['project'])) {
            $p = $cfg['project'];
            if (is_int($p) || (is_string($p) && trim($p) !== '')) {
                $out['existsProject'] = strtolower(trim((string) $p));
            } else {
                return ['error' => '"project" must be a project id such as 123, or a project alias such as "lab".'];
            }
        }
        if (isset($cfg['event'])) {
            if (!is_string($cfg['event'])) return ['error' => '"event" must be a unique event name such as "enrolment_arm_1".'];
            $out['existsEvent'] = strtolower(trim($cfg['event']));
        }
        if (isset($cfg['scope'])) {
            if (!is_string($cfg['scope'])) return ['error' => '"scope" must be a string.'];
            $out['existsScope'] = strtolower(trim($cfg['scope']));
        }
        if (isset($cfg['match'])) {
            if (!is_array($cfg['match']) || !$cfg['match'] || array_keys($cfg['match']) === range(0, count($cfg['match']) - 1)) {
                return ['error' => '"match" must be an object of target field to field of this record, e.g. '
                    . '{"site_code":"[site]"}.'];
            }
            $match = [];
            foreach ($cfg['match'] as $target => $local) {
                $t = strtolower(trim((string) $target));   // a target named "record": refused by checkExists
                $l = is_string($local) ? self::existsSource($local) : null;
                if ($l === false) return ['error' => self::EXISTS_RECORD_FIELD];
                if ($l === null || $l === 'record') {
                    return ['error' => '"match" value for "' . $t . '" must be one field reference of this record, such as "[site]".'];
                }
                if (isset($match[$t])) return ['error' => '"match" names the target "' . $t . '" twice.'];
                $match[$t] = $l;
            }
            $out['existsMatch'] = $match;
        }
        if (array_key_exists('return', $cfg)) {
            $r = $cfg['return'];
            if (!is_array($r) || !$r || array_keys($r) === range(0, count($r) - 1)) {
                return ['error' => '"return" must be an object of field there to field of this form, e.g. '
                    . '{"lab_site":"[result_site]"} ("record" there for the record ID found).'];
            }
            $ret = [];
            $seen = [];     // field here => the field there written to it
            foreach ($r as $source => $target) {
                $s = strtolower(trim((string) $source));
                $t = is_string($target) ? self::existsSource($target) : null;
                if ($t === false) return ['error' => self::EXISTS_RECORD_FIELD];
                if ($t === null || $t === 'record') {
                    return ['error' => '"return" value for "' . $s . '" must be one field reference of this form, such as "[result_site]".'];
                }
                if (isset($ret[$s])) return ['error' => '"return" names "' . $s . '" twice.'];
                if (isset($seen[$t])) {
                    return ['error' => '"return" writes "' . $seen[$t] . '" and "' . $s . '" to the same field "[' . $t . ']" — give each its own.'];
                }
                $ret[$s] = $t;
                $seen[$t] = $s;
            }
            $out['existsReturn'] = $ret;
            $out['existsReturnFields'] = array_values($ret);
        }
        if (isset($cfg['surveys'])) {
            if (!is_bool($cfg['surveys'])) return ['error' => '"surveys" must be true or false (unquoted).'];
            if ($cfg['surveys']) $out['existsSurveys'] = true;
        }
        if (array_key_exists('where', $cfg)) {
            if (!is_string($cfg['where'])) {
                return ['error' => '"where" must be a condition on fields of the entry found, such as "[consent]=\'1\'".'];
            }
            $out['existsWhere'] = trim($cfg['where']);
            $out['existsWhereFields'] = self::existsWhereFieldsOf($out['existsWhere']);
        }
        if (($e = self::takeTexts($cfg, $out)) !== null) return ['error' => $e];
        foreach (['when', 'message', 'blockSave', 'whereMessage'] as $k) {
            if (isset($cfg[$k])) {
                if (!is_string($cfg[$k])) return ['error' => '"' . $k . '" must be a string.'];
                $out[$k] = $cfg[$k];
            }
        }
        self::takeCaseSensitive($cfg, $out);
        // What the rule reads, as field lists: the fields of THIS record the
        // lookup is narrowed by, and the fields it searches in other entries
        // (the searched field, the "match" targets and the "where" fields).
        if (isset($out['existsMatch'])) $out['existsLocal'] = array_values(array_unique(array_values($out['existsMatch'])));
        $targets = $in === 'record' ? [] : [$in];
        foreach (array_keys(isset($out['existsMatch']) ? $out['existsMatch'] : []) as $t) $targets[] = (string) $t;
        foreach (isset($out['existsWhereFields']) ? $out['existsWhereFields'] : [] as $t) $targets[] = (string) $t;
        if ($targets) $out[isset($out['existsProject']) ? 'existsRemoteTargets' : 'existsTargets'] = array_values(array_unique($targets));
        $errs = self::checkFragment($out, $opts);
        return $errs ? ['error' => implode(' ', $errs)] : $out;
    }

    /**
     * Parse one @UVRANGE value into a range fragment: two tiers of plausibility
     * limits for a number. JSON form only:
     *   @UVRANGE={"soft":[12,17.5],"hard":[3,25],"unit":"g/dL"}
     *   @UVRANGE={"soft":[null,140],"softBlock":"off","hard":[40,250],"hardBlock":"hard"}
     * Outside "soft" is unusual (softBlock: off or confirm, default confirm);
     * outside "hard", or not a number at all, is implausible (hardBlock:
     * confirm or hard, default hard). Bounds are inclusive; null leaves a side
     * open. Each bound is kept as an exact decimal string (rangeBound), so the
     * two runtimes compare the same digits. Whether the field holds numbers is
     * checked with the data dictionary in hand
     * (UniversalValidator::annotateRangeField).
     *
     * With "reference" the limits are z-scores against a growth reference
     * (php/GrowthReference.php), and the rule names its inputs:
     *   @UVRANGE={"reference":"who-wfa","sex":"[sex]","male":"1","female":"2",
     *             "age":{"dob":"[dob]","at":"[visit_date]"},"soft":[-2,2],"hard":[-6,5]}
     * "sex", "age" (one of {"dob","at"}, {"days"} or {"months"}) and "by" become
     * the operand keys rangeSex, rangeAgeDob/rangeAgeAt, rangeAgeDays,
     * rangeAgeMonths and rangeBy; checkGrowth refuses a wrong combination.
     *
     * "return" writes values worked out from the reference to Text fields of
     * the same form, and the rule then needs no limits when none of the values
     * is made from one (GrowthOutputs::limitFree: "z", "percentile",
     * "valueAtZ:<z>", "valueAtPercentile:<p>"):
     *   "return":{"z":"[wfa_z]","lower":"[wfa_low]","upper":"[wfa_high]","range":"[wfa_range]"}
     * z is the z-score of the measurement, lower and upper the smallest and
     * largest measurements, written with "decimals", that pass the low and high
     * z limit of the "soft" limits (or "hard", "returnLimits"), and range the
     * two as "7.7 to 12.0 kg" (GrowthReference::bound). percentile is written
     * with "percentileDecimals", valueAtZ and valueAtPercentile with
     * "decimals" (GrowthReference::output). The keys php/growth_outputs.json
     * allows are read by GrowthOutputs::parse. They become rangeReturn (key =>
     * field name), rangeReturnLimits, rangeDecimals and rangePercentileDecimals.
     */
    private static function parseRangeValue($val, array $opts = [])
    {
        $val = trim($val);
        if ($val === '' || $val[0] !== '{') {
            return ['error' => self::TAG_RANGE . ' needs its settings as JSON, e.g. '
                . self::TAG_RANGE . '={"soft":[12,17.5],"hard":[3,25]}.'];
        }
        $cfg = json_decode($val, true, 512, JSON_BIGINT_AS_STRING);
        if (!is_array($cfg)) {
            return ['error' => self::TAG_RANGE . ' JSON does not parse ('
                . json_last_error_msg() . ') — use double quotes around keys and string values.'];
        }
        // The same JSON with every number as the text it was typed as. A limit
        // is read from here, so 0.12345678901234567890 keeps all its digits
        // instead of becoming the nearest double. Types are still judged on
        // $cfg: a "unit" typed as 5 is a number, not the string "5".
        $exact = json_decode(self::quoteJsonNumbers($val), true);
        if (array_key_exists('blockSave', $cfg)) {
            return ['error' => '"blockSave" does not apply to ' . self::TAG_RANGE . ' — use "softBlock" (off or confirm) '
                . 'for values outside "soft", and "hardBlock" (confirm or hard) for values outside "hard".'];
        }
        $allowed = ['soft', 'hard', 'softBlock', 'hardBlock', 'unit', 'when', 'message', 'caseSensitive',
                    'reference', 'sex', 'male', 'female', 'age', 'by', 'plausible', 'measureScale',
                    'return', 'returnLimits', 'decimals', 'percentileDecimals', 'show'];
        $unknown = array_diff(array_keys($cfg), $allowed);
        if ($unknown) {
            return ['error' => 'unknown ' . self::TAG_RANGE . ' option(s): ' . implode(', ', $unknown)
                . ' — valid: ' . implode(', ', $allowed) . '.'];
        }
        $out = ['type' => 'range'];
        // "plausible" (growth references only) is a pair like "soft" and "hard",
        // in the reference's unit rather than in z-scores.
        foreach (['soft' => 'rangeSoft', 'hard' => 'rangeHard', 'plausible' => 'rangePlausible'] as $k => $prefix) {
            if (!array_key_exists($k, $cfg)) continue;
            $pair = $cfg[$k];
            if (!is_array($pair) || array_keys($pair) !== [0, 1]) {
                return ['error' => '"' . $k . '" must be a list of two limits, [low, high] — e.g. [12,17.5], or [null,140] for no low limit.'];
            }
            if ($pair[0] === null && $pair[1] === null) {
                return ['error' => '"' . $k . '" needs at least one limit — [null,null] would check nothing.'];
            }
            foreach ([0 => 'Lo', 1 => 'Hi'] as $i => $side) {
                if ($pair[$i] === null) continue;
                $typed = isset($exact[$k][$i]) && is_string($exact[$k][$i]) ? $exact[$k][$i] : null;
                $b = self::rangeBound($pair[$i], $typed);
                $what = 'the "' . $k . '" ' . ($i === 0 ? 'low' : 'high') . ' limit';
                if ($b === null) {
                    return ['error' => $what . ' must be a number such as 12 or 17.5 — got '
                        . ((is_int($pair[$i]) || is_float($pair[$i])) && $typed !== null ? $typed : json_encode($pair[$i])) . '.'];
                }
                if ($b === false || strlen($b) > self::MAX_RANGE_BOUND) {
                    return ['error' => $what . ' is longer than ' . self::MAX_RANGE_BOUND . ' characters written out.'];
                }
                $out[$prefix . $side] = $b;
            }
        }
        if (array_key_exists('measureScale', $cfg)) {
            $typed = isset($exact['measureScale']) && is_string($exact['measureScale']) ? $exact['measureScale'] : null;
            $b = self::rangeBound($cfg['measureScale'], $typed);
            if ($b === null || $b === false) {
                return ['error' => '"measureScale" must be a number such as 0.001 (grams typed into a reference in kg) — got '
                    . ((is_int($cfg['measureScale']) || is_float($cfg['measureScale'])) && $typed !== null ? $typed : json_encode($cfg['measureScale'])) . '.'];
            }
            $out['rangeMeasureScale'] = $b;
        }
        if (($e = self::takeTexts($cfg, $out)) !== null) return ['error' => $e];
        foreach (['softBlock' => 'rangeSoftBlock', 'hardBlock' => 'rangeHardBlock', 'unit' => 'rangeUnit',
                  'when' => 'when', 'message' => 'message'] as $k => $key) {
            if (!isset($cfg[$k])) continue;
            if (!is_string($cfg[$k])) return ['error' => '"' . $k . '" must be a string.'];
            $out[$key] = $k === 'unit' ? trim($cfg[$k]) : $cfg[$k];
        }
        // A growth reference: the limits are z-scores, and the reference reads
        // the sex and the age (or the length or height) from other fields.
        if (array_key_exists('reference', $cfg)) {
            if (!is_string($cfg['reference']) || trim($cfg['reference']) === '') {
                return ['error' => '"reference" must be the id of a growth reference, such as "who-wfa".'];
            }
            $out['rangeReference'] = trim($cfg['reference']);
        }
        foreach (['sex' => 'rangeSex', 'by' => 'rangeBy'] as $k => $key) {
            if (!array_key_exists($k, $cfg)) continue;
            if (!is_string($cfg[$k])) return ['error' => '"' . $k . '" must be one field reference, such as "[' . ($k === 'sex' ? 'sex' : 'height_cm') . ']".'];
            $out[$key] = $cfg[$k];
        }
        foreach (['male' => 'rangeMale', 'female' => 'rangeFemale'] as $k => $key) {
            if (!array_key_exists($k, $cfg)) continue;
            $v = is_int($cfg[$k]) ? (string) $cfg[$k] : $cfg[$k];
            if (!is_string($v) || trim($v) === '') {
                return ['error' => '"' . $k . '" must be the code the "sex" field stores for ' . $k . ', such as "' . ($k === 'male' ? '1' : '2') . '".'];
            }
            $out[$key] = trim($v);
        }
        if (array_key_exists('age', $cfg)) {
            $a = $cfg['age'];
            $keys = is_array($a) ? array_keys($a) : [];
            sort($keys);
            if (!in_array($keys, [['at', 'dob'], ['days'], ['months']], true)) {
                return ['error' => '"age" must be {"dob":"[dob]","at":"[visit_date]"}, {"days":"[age_days]"} or {"months":"[age_months]"}.'];
            }
            foreach ($a as $k => $v) {
                if (!is_string($v)) return ['error' => '"age" "' . $k . '" must be one field reference, such as "[' . ($k === 'at' ? 'visit_date' : $k) . ']".'];
                $out['rangeAge' . ucfirst($k)] = $v;
            }
        }
        // Values worked out from the reference, written to Text fields of the
        // same form: {"z":"[wfa_z]","lower":"[wfa_low]","upper":"[wfa_high]",
        // "range":"[wfa_range]"}. checkRange says what else they need.
        if (array_key_exists('return', $cfg)) {
            // a catalog that cannot be read refuses this rule only
            $why = GrowthOutputs::problem();
            if ($why !== null) return ['error' => '"return" cannot be checked: ' . $why . '.'];
            $r = $cfg['return'];
            if (!is_array($r) || !$r || array_values($r) === $r) {
                return ['error' => '"return" must say which field gets which value, e.g. {"z":"[wfa_z]","range":"[wfa_range]"} — '
                    . 'the values are ' . GrowthOutputs::validText() . '.'];
            }
            if (count($r) > self::MAX_RANGE_RETURN) {
                return ['error' => '"return" is limited to ' . self::MAX_RANGE_RETURN . ' values.'];
            }
            $targets = [];
            $same = [];
            foreach ($r as $k => $v) {
                $k = (string) $k;
                $known = GrowthOutputs::parse($k);
                if (isset($known['error'])) return ['error' => $known['error']];
                // "valueAtZ:2" and "valueAtZ:2.0" are one value
                $c = GrowthOutputs::canonicalKey($k);
                if (isset($same[$c])) {
                    return ['error' => '"return" "' . $same[$c] . '" and "' . $k . '" are the same value — return it once.'];
                }
                $same[$c] = $k;
                if (!is_string($v) || !preg_match('/^\[([A-Za-z][A-Za-z0-9_]*)\]$/', trim($v), $m)) {
                    return ['error' => '"return" "' . $k . '" must be one field of this form, such as "[' . GrowthOutputs::exampleOf($k) . ']".'];
                }
                $targets[$k] = strtolower($m[1]);
            }
            $seen = [];
            foreach ($targets as $k => $f) {
                if (isset($seen[$f])) {
                    return ['error' => '"return" writes "' . $seen[$f] . '" and "' . $k . '" to the same field "[' . $f . ']" — give each its own.'];
                }
                $seen[$f] = $k;
            }
            $out['rangeReturn'] = $targets;
        }
        if (array_key_exists('returnLimits', $cfg)) {
            if (!is_string($cfg['returnLimits']) || !in_array($cfg['returnLimits'], self::RETURN_LIMITS, true)) {
                return ['error' => '"returnLimits" must be "soft" or "hard" — the limits the returned bounds come from.'];
            }
            $out['rangeReturnLimits'] = $cfg['returnLimits'];
        }
        if (array_key_exists('decimals', $cfg)) {
            if (!is_int($cfg['decimals']) || $cfg['decimals'] < 0 || $cfg['decimals'] > GrowthReference::MAX_DECIMALS) {
                return ['error' => '"decimals" must be a whole number from 0 to ' . GrowthReference::MAX_DECIMALS
                    . ' — how many decimals the returned bounds and values at a z-score or percentile are written with.'];
            }
            $out['rangeDecimals'] = $cfg['decimals'];
        }
        if (array_key_exists('percentileDecimals', $cfg)) {
            $pd = $cfg['percentileDecimals'];
            if (!is_int($pd) || $pd < 0 || $pd > GrowthReference::MAX_PERCENTILE_DECIMALS) {
                return ['error' => '"percentileDecimals" must be a whole number from 0 to ' . GrowthReference::MAX_PERCENTILE_DECIMALS
                    . ' — how many decimals the returned percentile is written with.'];
            }
            $out['rangePercentileDecimals'] = $pd;
        }
        // The growth chart and the table shown on the page (page only).
        if (array_key_exists('show', $cfg)) {
            // the chart's lines and the table's columns are catalog outputs:
            // an unreadable catalog refuses this rule only, as for "return"
            $bad = GrowthOutputs::problem();
            if ($bad !== null) return ['error' => '"show" cannot be checked: ' . $bad . '.'];
            $raw = json_decode($val, false, 512, JSON_BIGINT_AS_STRING);
            $s = self::parseShow($cfg['show'], isset($exact['show']) ? $exact['show'] : null, $out,
                (is_object($raw) && property_exists($raw, 'show')) ? $raw->show : null);
            if (isset($s['error'])) return $s;
            $out['rangeShow'] = $s;
        }
        $columns = isset($out['rangeShow']['table']['columns']) ? $out['rangeShow']['table']['columns'] : [];
        if (isset($out['rangeReturn']) || $columns) {
            $why = self::returnKeyUnused($cfg, isset($out['rangeReturn']) ? array_keys($out['rangeReturn']) : [], $columns);
            if ($why !== null) return ['error' => $why];
        }
        self::takeCaseSensitive($cfg, $out);
        $errs = self::checkFragment($out, $opts);
        return $errs ? ['error' => implode(' ', $errs)] : $out;
    }

    /**
     * Why a key that shapes returned values is written on a rule whose
     * "return" ($keys) has none of the values it shapes, or null. The
     * catalog (php/growth_outputs.json) says which outputs each key applies
     * to: "returnLimits" to those made from a limit, "decimals" and
     * "percentileDecimals" to those whose "places" name them. The values a
     * rule returns ($keys) and the columns of its page table ($columns) both
     * count. Only the keys written in the tag ($cfg) are judged; the
     * dictionary hook fills in "decimals" for every rule that returns or
     * shows such values.
     */
    private static function returnKeyUnused(array $cfg, array $keys, array $columns = [])
    {
        foreach (self::RETURN_SHAPING as $key => $u) {
            $test = self::shapes($key);
            if (!array_key_exists($key, $cfg) || GrowthOutputs::anyWhere($keys, $test)
                    || GrowthOutputs::anyWhere($columns, $test, 'column')) continue;
            $ids = GrowthOutputs::idsWhere($test);
            $last = array_pop($ids);
            $list = ($ids ? '"' . implode('", "', $ids) . '" and ' : '') . '"' . $last . '"';
            return '"' . $key . '" ' . sprintf($u, $list) . ', and ' . ($columns ? 'neither "return" nor the "show" "table" has '
                . ($ids ? 'any of them' : 'it') : '"return" has ' . ($ids ? 'none of them' : 'none')) . '.';
        }
        return null;
    }

    /** The keys that shape returned or shown values, and what each does to the outputs it applies to (shapes()). */
    const RETURN_SHAPING = [
        'returnLimits' => 'picks the limits %s are made from',
        'decimals' => 'sets the decimals of %s',
        'percentileDecimals' => 'sets the decimals of %s',
    ];

    /** Which catalog outputs key $key (one of RETURN_SHAPING) applies to, as a test of an entry. */
    private static function shapes($key)
    {
        if ($key === 'returnLimits') return function ($e) { return strpos($e['needs'], 'limit-') === 0; };
        return function ($e) use ($key) { return isset($e['places']) && $e['places'] === $key; };
    }

    /**
     * "show": what an @UVRANGE growth rule shows on the page, never saved.
     *   "chart": a growth chart: "lines" ({"z":[...]} or {"percentiles":[...]},
     *            1 to MAX_CHART_LINES; the line names and the numbers each
     *            takes come from php/growth_outputs.json), "bands" (shade the
     *            "soft" range), "y" ("value" or "z"), "span" ([low, high] in
     *            the reference's axis unit, or "auto"), "open" and "labels";
     *   "table": a table: "rows" ("history", this visit's row, or "curve",
     *            reference values every "step" over its "span", at most
     *            GrowthCurve::MAX_CURVE_ROWS rows), "columns" (catalog outputs
     *            of the column shape, at most MAX_TABLE_COLUMNS), "open" and
     *            "labels".
     * Either may be true for its defaults. Without "lines" the chart draws the
     * z-scores of the rule's limits that a line may have, and 0. Labels are
     * texts (or, through takeTexts, per-language maps). The result is the
     * page's rangeShow: every default filled in, numbers as canonical text.
     */
    private static function parseShow($show, $exact, array &$out, $raw = null)
    {
        if (!is_array($show) || !$show || array_values($show) === $show) {
            return ['error' => '"show" must say what the page shows, e.g. {"chart":{"lines":{"percentiles":[3,50,97]}}} or {"table":true}.'];
        }
        $unknown = array_diff(array_keys($show), ['chart', 'table']);
        if ($unknown) return ['error' => 'unknown "show" option(s): ' . implode(', ', $unknown) . ' — valid: chart, table.'];
        $res = [];
        foreach (['chart', 'table'] as $part) {
            if (!array_key_exists($part, $show)) continue;
            $p = $show[$part];
            if ($p === true) $p = [];
            // the tag as written ($raw, decoded to objects) tells [] from {}
            $rp = (is_object($raw) && property_exists($raw, $part)) ? $raw->$part : null;
            if (!is_array($p) || ($p && array_values($p) === $p) || is_array($rp)) {
                return ['error' => '"show" "' . $part . '" must be true or an object of its settings, e.g. "' . $part . '":{"open":false}.'];
            }
            $ex = (is_array($exact) && isset($exact[$part]) && is_array($exact[$part])) ? $exact[$part] : [];
            $rl = (is_object($rp) && property_exists($rp, 'labels')) ? $rp->labels : null;
            $r = $part === 'chart' ? self::parseShowChart($p, $ex, $out, $rl)
                : self::parseShowTable($p, $ex, $out, isset($res['chart']) ? $res['chart'] : null, $rl);
            if (isset($r['error'])) return $r;
            $res[$part] = $r;
        }
        return $res;
    }

    /** Most curves one growth chart draws ("show" "chart" "lines"). */
    const MAX_CHART_LINES = 9;
    /** Most columns of the page table ("show" "table" "columns"). */
    const MAX_TABLE_COLUMNS = 16;
    /** Longest chart or table label, in characters. */
    const MAX_SHOW_LABEL = 200;
    /**
     * The labels a chart and a table take ("labels"); a table also takes one
     * per column it shows. A chart's "x" may hold "{unit}", the word for the
     * unit its axis counts in, and the unit and sex words are labels too
     * ("months", "male"), so the chart reads in the page's language.
     */
    const CHART_LABELS = ['summary', 'title', 'x', 'y', 'point', 'lines', 'band', 'days', 'weeks', 'months', 'years', 'male', 'female'];
    const TABLE_LABELS = ['summary', 'caption'];
    /** The columns of a table that has no "columns": this visit's row, or reference values beside the age. */
    const HISTORY_COLUMNS = ['age', 'value', 'z', 'percentile'];

    private static function parseShowChart(array $p, array $ex, array &$out, $rawLabels)
    {
        $keys = ['lines', 'bands', 'y', 'span', 'open', 'labels'];
        $unknown = array_diff(array_keys($p), $keys);
        if ($unknown) return ['error' => 'unknown "show" "chart" option(s): ' . implode(', ', $unknown) . ' — valid: ' . implode(', ', $keys) . '.'];
        $c = ['lines' => null, 'bands' => false, 'y' => 'value', 'span' => null, 'open' => null, 'labels' => []];
        if (array_key_exists('lines', $p)) {
            $l = self::parseShowLines($p['lines'], isset($ex['lines']) ? $ex['lines'] : null);
            if (isset($l['error'])) return $l;
            $c['lines'] = $l;
        } else {
            $c['lines'] = self::defaultLines($out);
        }
        foreach (['bands', 'open'] as $k) {
            if (!array_key_exists($k, $p)) continue;
            if (!is_bool($p[$k])) return ['error' => '"show" "chart" "' . $k . '" must be true or false (unquoted).'];
            $c[$k] = $p[$k];
        }
        if (array_key_exists('y', $p)) {
            if (!in_array($p['y'], ['value', 'z'], true)) {
                return ['error' => '"show" "chart" "y" must be "value" (the measurement, the default) or "z" (z-scores, where every line is flat).'];
            }
            $c['y'] = $p['y'];
        }
        if (array_key_exists('span', $p)) {
            $s = self::parseShowSpan($p['span'], isset($ex['span']) ? $ex['span'] : null, 'chart');
            if (isset($s['error'])) return $s;
            $c['span'] = $s['span'];
        }
        $lab = self::parseShowLabels($p, 'chart', self::CHART_LABELS, $out, $rawLabels);
        if (isset($lab['error'])) return $lab;
        $c['labels'] = $lab;
        return $c;
    }

    private static function parseShowTable(array $p, array $ex, array &$out, $chart, $rawLabels)
    {
        $keys = ['rows', 'step', 'span', 'columns', 'open', 'labels'];
        $unknown = array_diff(array_keys($p), $keys);
        if ($unknown) return ['error' => 'unknown "show" "table" option(s): ' . implode(', ', $unknown) . ' — valid: ' . implode(', ', $keys) . '.'];
        $t = ['rows' => 'history', 'step' => null, 'span' => null, 'columns' => [], 'open' => null, 'labels' => []];
        if (array_key_exists('rows', $p)) {
            if (!in_array($p['rows'], ['history', 'curve'], true)) {
                return ['error' => '"show" "table" "rows" must be "history" (this visit, the default) or "curve" (reference values every "step").'];
            }
            $t['rows'] = $p['rows'];
        }
        $curve = $t['rows'] === 'curve';
        foreach (['step', 'span'] as $k) {
            if (array_key_exists($k, $p) && !$curve) {
                return ['error' => '"show" "table" "' . $k . '" only applies with "rows":"curve", a table of reference values.'];
            }
        }
        if ($curve) {
            if (!array_key_exists('step', $p)) {
                return ['error' => '"show" "table" "rows":"curve" needs "step", the distance between two rows in the reference\'s axis unit, '
                    . 'such as 30 (days) or 1 (month).'];
            }
            $typed = (isset($ex['step']) && is_string($ex['step'])) ? $ex['step'] : null;
            $st = self::rangeBound($p['step'], $typed);
            if (!is_string($st) || Logic::numCompare($st, '0') <= 0) {
                return ['error' => '"show" "table" "step" must be a number above 0, such as 30 — got '
                    . ((is_int($p['step']) || is_float($p['step'])) && $typed !== null ? $typed : json_encode($p['step'])) . '.'];
            }
            $t['step'] = GrowthCurve::numText($st);
            $whose = 'its';
            if (array_key_exists('span', $p)) {
                $s = self::parseShowSpan($p['span'], isset($ex['span']) ? $ex['span'] : null, 'table');
                if (isset($s['error'])) return $s;
                $t['span'] = $s['span'];
            } elseif ($chart !== null) {
                $t['span'] = $chart['span'];   // the chart's, when there is one
                $whose = 'the chart\'s';
            }
            // Counted here only up to the span's end, not at it: a reference
            // that ends "below" that value has no row there. The page counts
            // again with the reference in hand (GrowthCurve::showProblems).
            if (is_array($t['span'])) {
                $n = count(GrowthCurve::xsBetween((float) $t['span'][0], (float) $t['span'][1], (float) $t['step'], (float) $t['span'][1]));
                if ($n > GrowthCurve::MAX_CURVE_ROWS) {
                    return ['error' => '"show" "table" with "rows":"curve" and "step" ' . $t['step'] . ' has more than ' . GrowthCurve::MAX_CURVE_ROWS
                        . ' rows over ' . $whose . ' "span" [' . $t['span'][0] . ', ' . $t['span'][1] . '] — take a larger "step", or a shorter "span".'];
                }
            }
        }
        $canon = [];   // a column as the catalog writes it => as the tag wrote it, for its label
        if (array_key_exists('columns', $p)) {
            $cols = $p['columns'];
            if (!is_array($cols) || !$cols || array_values($cols) !== $cols) {
                return ['error' => '"show" "table" "columns" must be a list such as ["age","value","z","percentile"] — the columns are '
                    . GrowthOutputs::validText('column') . '.'];
            }
            if (count($cols) > self::MAX_TABLE_COLUMNS) {
                return ['error' => '"show" "table" "columns" is limited to ' . self::MAX_TABLE_COLUMNS . ' columns.'];
            }
            foreach ($cols as $col) {
                if (!is_string($col)) return ['error' => '"show" "table" "columns" must be a list of column names such as "z" — got ' . json_encode($col) . '.'];
                $known = GrowthOutputs::parse($col, '"show" "table" "columns"', 'column', 'entry');
                if (isset($known['error'])) return ['error' => $known['error']];
                $ck = GrowthOutputs::canonicalKey($col, 'column');
                if (isset($canon[$ck])) {
                    return ['error' => '"show" "table" "columns" "' . $canon[$ck] . '" and "' . $col . '" are the same column — list it once.'];
                }
                $canon[$ck] = $col;
                if ($curve && GrowthOutputs::spec($known['of'])['needs'] === 'measurement') {
                    return ['error' => '"show" "table" column "' . $col . '" needs a measurement, and a table of reference values ("rows":"curve") '
                        . 'has none — show it with "rows":"history".'];
                }
            }
            $t['columns'] = $cols;
        } elseif ($curve) {
            // a column per curve of the chart (or per default line) that a
            // column can name: a default line at a limit beyond what
            // "valueAtZ:" takes is drawn, not listed
            $lines = $chart !== null ? $chart['lines'] : self::defaultLines($out);
            $id = GrowthOutputs::lineKinds()[$lines['kind']];
            $t['columns'] = ['age'];
            foreach ($lines['values'] as $v) if (GrowthOutputs::paramOk($id, $v)) $t['columns'][] = $id . ':' . $v;
        } else {
            $t['columns'] = self::HISTORY_COLUMNS;
        }
        if (!$canon) foreach ($t['columns'] as $col) $canon[GrowthOutputs::canonicalKey($col, 'column')] = $col;
        if (array_key_exists('open', $p)) {
            if (!is_bool($p['open'])) return ['error' => '"show" "table" "open" must be true or false (unquoted).'];
            $t['open'] = $p['open'];
        }
        $lab = self::parseShowLabels($p, 'table', array_merge(self::TABLE_LABELS, $t['columns']), $out, $rawLabels, $canon);
        if (isset($lab['error'])) return $lab;
        $t['labels'] = $lab;
        return $t;
    }

    /**
     * A chart's "lines": {"z":[-2,0,2]} or {"percentiles":[3,50,97]}, the
     * names and the numbers each takes from the catalog (the outputs of the
     * line shape). Numbers are read as limits are (rangeBound: 2.5e0 is
     * 2.5). ['kind' => name, 'values' => [text, ...]] in ascending order, or
     * ['error' => why].
     */
    private static function parseShowLines($l, $exact)
    {
        $kinds = GrowthOutputs::lineKinds();
        $eg = implode(' or ', array_map(function ($k) { return '{"' . $k . '":[...]}'; }, array_keys($kinds)));
        if (!is_array($l) || count($l) !== 1 || array_values($l) === $l || !isset($kinds[key($l)])) {
            return ['error' => '"show" "chart" "lines" must be ' . $eg . ', e.g. {"percentiles":[3,15,50,85,97]}.'];
        }
        $kind = (string) key($l);
        $id = $kinds[$kind];
        $vals = $l[$kind];
        if (!is_array($vals) || !$vals || array_values($vals) !== $vals) {
            return ['error' => '"show" "chart" "lines" "' . $kind . '" must be a list of numbers, such as [-2,0,2].'];
        }
        if (count($vals) > self::MAX_CHART_LINES) {
            return ['error' => '"show" "chart" "lines" has more than ' . self::MAX_CHART_LINES . ' lines.'];
        }
        $seen = [];
        $norm = [];
        foreach ($vals as $i => $v) {
            $typed = (is_array($exact) && isset($exact[$kind][$i]) && is_string($exact[$kind][$i])) ? $exact[$kind][$i] : null;
            $b = self::rangeBound($v, $typed);
            $n = is_string($b) ? GrowthCurve::numText($b) : null;
            if ($n === null || !GrowthOutputs::paramOk($id, $n)) {
                // a number as typed; anything else as JSON, so a word shows its quotes
                return ['error' => '"show" "chart" "lines" "' . $kind . '" takes ' . GrowthOutputs::paramText($id) . ' for each line — got '
                    . ((is_int($v) || is_float($v)) && $typed !== null ? $typed : json_encode($v)) . '.'];
            }
            if (isset($seen[$n])) return ['error' => '"show" "chart" "lines" "' . $kind . '" names ' . $n . ' twice.'];
            $seen[$n] = true;
            $norm[] = $n;
        }
        usort($norm, function ($a, $b) { return Logic::numCompare($a, $b); });
        return ['kind' => $kind, 'values' => $norm];
    }

    /**
     * The lines of a chart that names none: 0 and each of the rule's limits,
     * in ascending order, as z-score lines. A limit is drawn whatever its
     * value (valueAt reads any z-score), so every limit the check uses is on
     * the chart.
     */
    private static function defaultLines(array $out)
    {
        $seen = ['0' => true];
        foreach (['rangeHardLo', 'rangeSoftLo', 'rangeSoftHi', 'rangeHardHi'] as $k) {
            if (!isset($out[$k]) || !is_string($out[$k])) continue;
            $n = GrowthCurve::numText($out[$k]);
            if ($n !== null) $seen[$n] = true;
        }
        $vals = array_map('strval', array_keys($seen));
        usort($vals, function ($a, $b) { return Logic::numCompare($a, $b); });
        $kinds = array_flip(GrowthOutputs::lineKinds());
        return ['kind' => $kinds['valueAtZ'], 'values' => $vals];
    }

    /** A "span": "auto" (null) or [low, high], low below high, as canonical texts. */
    private static function parseShowSpan($s, $exact, $part)
    {
        if ($s === 'auto') return ['span' => null];
        $what = '"show" "' . $part . '" "span" must be "auto" or [low, high] in the reference\'s axis unit (days, months or cm), '
            . 'low below high, such as [0,730]';
        if (!is_array($s) || array_keys($s) !== [0, 1]) return ['error' => $what . '.'];
        $b = [];
        foreach ([0, 1] as $i) {
            $typed = (is_array($exact) && isset($exact[$i]) && is_string($exact[$i])) ? $exact[$i] : null;
            $v = self::rangeBound($s[$i], $typed);
            if (!is_string($v)) return ['error' => $what . ' — got ' . json_encode($s) . '.'];
            $b[] = GrowthCurve::numText($v);
        }
        if (Logic::numCompare($b[0], $b[1]) >= 0) return ['error' => $what . ' — got [' . $b[0] . ', ' . $b[1] . '].'];
        return ['span' => $b];
    }

    /**
     * The "labels" of a chart or a table: each a text of at most
     * MAX_SHOW_LABEL characters on one line (GrowthReference::oneLine), in
     * every language a map gives (takeTexts has already put the default here
     * and the other languages under "i18n"). A table's column label may name
     * its column as the catalog writes it ($canon: "valueAtZ:2.5" for a
     * column written "valueAtZ:2.50"); it is kept under the column's own
     * spelling, its other languages too. $rawLabels is "labels" as written,
     * to tell a list from an object.
     */
    private static function parseShowLabels(array $p, $part, array $allowed, array &$out, $rawLabels, array $canon = [])
    {
        if (!array_key_exists('labels', $p)) return [];
        $l = $p['labels'];
        if (!is_array($l) || ($l && array_values($l) === $l) || is_array($rawLabels)) {
            return ['error' => '"show" "' . $part . '" "labels" must be an object of label name to text, e.g. {"title":"Weight"}.'];
        }
        $res = [];
        foreach ($l as $k => $text) {
            $k = (string) $k;
            $name = $k;
            if (!in_array($k, $allowed, true)) {
                $ck = $canon && strpos($k, ':') !== false ? GrowthOutputs::canonicalKey($k, 'column') : null;
                if ($ck === null || !isset($canon[$ck])) {
                    return ['error' => 'unknown "show" "' . $part . '" label "' . $k . '" — valid: ' . implode(', ', $allowed) . '.'];
                }
                $name = $canon[$ck];
            }
            if (array_key_exists($name, $res)) {
                return ['error' => '"show" "' . $part . '" labels "' . $name . '" twice, written two ways — give it once.'];
            }
            $texts = [$text];
            $from = 'show.' . $part . '.labels.' . $k;
            $map = isset($out['i18n'][$from]) ? $out['i18n'][$from] : [];
            foreach ($map as $t) $texts[] = $t;
            foreach ($texts as $t) {
                if (!is_string($t) || trim($t) === '' || !GrowthReference::oneLine($t)
                        || (function_exists('mb_strlen') ? mb_strlen($t, 'UTF-8') : strlen($t)) > self::MAX_SHOW_LABEL) {
                    return ['error' => '"show" "' . $part . '" label "' . $k . '" must be a text of 1 to ' . self::MAX_SHOW_LABEL
                        . ' characters on one line, or an object of language id to such texts.'];
                }
            }
            if ($name !== $k && $map) {
                $out['i18n']['show.' . $part . '.labels.' . $name] = $map;
                unset($out['i18n'][$from]);
            }
            $res[$name] = $text;
        }
        return $res;
    }

    /**
     * The "show" part of a growth rule, once its other keys are known: bands
     * need a "soft" limit, and a table column made from a limit needs that
     * limit (of the "returnLimits" tier, as a returned bound does). Whether
     * a span and a table of reference values fit the reference is for the
     * page (GrowthCurve::showProblems, through the dictionary hook): it
     * leaves that part off and never refuses the rule.
     */
    private static function checkShow(array $frag, array $limits)
    {
        $errors = [];
        $s = $frag['rangeShow'];
        if (!is_array($s)) return ['"show" must be an object.'];
        if (!empty($s['chart']['bands']) && !isset($limits['rangeSoftLo']) && !isset($limits['rangeSoftHi'])) {
            $errors[] = '"show" "chart" "bands" shades the "soft" range, and this rule has no "soft" limits.';
        }
        $tier = isset($frag['rangeReturnLimits']) && $frag['rangeReturnLimits'] === 'hard' ? 'hard' : 'soft';
        foreach ((isset($s['table']['columns']) && is_array($s['table']['columns'])) ? $s['table']['columns'] : [] as $col) {
            $p = GrowthOutputs::parse($col, '"show" "table" "columns"', 'column', 'entry');
            if (!isset($p['of'])) continue;
            $miss = self::missingLimit(GrowthOutputs::spec($p['of'])['needs'], $tier, $limits);
            if ($miss !== null) {
                $errors[] = '"show" "table" column "' . $col . '" is made from ' . $miss[0] . ', which this rule does not have — ' . $miss[1] . '.';
            }
        }
        return $errors;
    }

    /**
     * What a value made from limits ($need, the catalog's "needs") lacks
     * when it is made from the $tier limits of a rule with $limits:
     * [what it is made from, what to do], or null when the tier has them.
     * The other tier is suggested only when it has what the value needs.
     */
    private static function missingLimit($need, $tier, array $limits)
    {
        $ok = function ($t) use ($need, $limits) {
            $T = ucfirst($t);
            $lo = isset($limits['range' . $T . 'Lo']);
            $hi = isset($limits['range' . $T . 'Hi']);
            if ($need === 'limit-low') return $lo;
            if ($need === 'limit-high') return $hi;
            if ($need === 'limit-any') return $lo || $hi;
            return true;
        };
        if ($ok($tier)) return null;
        $other = $tier === 'soft' ? 'hard' : 'soft';
        $what = $need === 'limit-low' ? 'the low "' . $tier . '" limit'
            : ($need === 'limit-high' ? 'the high "' . $tier . '" limit' : 'the "' . $tier . '" limits');
        $add = $need === 'limit-any' ? 'add them' : 'add one';
        return [$what, $ok($other) ? $add . ', or write "returnLimits":"' . $other . '"' : $add];
    }

    /**
     * One @UVRANGE limit as an exact decimal string, or null. A JSON number is
     * taken as the text it was typed as ($typed, from quoteJsonNumbers), so
     * 17.5, 3.0 and 9007199254740993 keep their digits; a string must be a
     * number too. An exponent is written out digit for digit (1e3 is 1000,
     * -2.5e-5 is -0.000025), the same way Logic::normalizeNumber reads one
     * typed into the field. False when written out it would be longer than
     * MAX_RANGE_BOUND characters.
     */
    private static function rangeBound($b, $typed)
    {
        if (is_int($b) || is_float($b)) {
            if (!is_string($typed)) return null;
            $t = $typed;
        } elseif (is_string($b)) {
            $t = trim($b);
        } else {
            return null;
        }
        if (preg_match(Logic::NUM_RE, $t)) return Logic::canonicalDecimal($t);
        $p = Logic::exponentParts($t);
        if ($p === null) return null;
        // Too long is said as such, without writing out "1e1000".
        if (Logic::partsLength($p) > self::MAX_RANGE_BOUND) return false;
        return Logic::partsText($p);
    }

    /**
     * The JSON text with every number token outside a string wrapped in
     * quotes, so json_decode hands back each number exactly as typed. Only
     * called on text that already decodes, so every token it wraps is a valid
     * JSON number.
     */
    private static function quoteJsonNumbers($json)
    {
        $out = '';
        $n = strlen($json);
        $inString = false;
        for ($i = 0; $i < $n; $i++) {
            $c = $json[$i];
            if ($inString) {
                $out .= $c;
                if ($c === '\\' && $i + 1 < $n) {
                    $out .= $json[++$i];
                } elseif ($c === '"') {
                    $inString = false;
                }
                continue;
            }
            if ($c === '"') {
                $inString = true;
                $out .= $c;
                continue;
            }
            if ($c === '-' || ($c >= '0' && $c <= '9')) {
                $j = $i;
                while ($j < $n && strpos('0123456789+-.eE', $json[$j]) !== false) $j++;
                $out .= '"' . substr($json, $i, $j - $i) . '"';
                $i = $j - 1;
                continue;
            }
            $out .= $c;
        }
        return $out;
    }

    /**
     * "record", or the field one plain [field] reference names, or null; false
     * for [record]. "record" is the record ID everywhere a lookup is described
     * (here, the other project's agreement, its rights and Identifier checks),
     * so a field that happens to be called "record" cannot be searched: it
     * would be checked as the record ID and read as the field.
     */
    private static function existsSource($text)
    {
        $t = trim((string) $text);
        if (strtolower($t) === 'record') return 'record';
        if (!preg_match('/^\[([A-Za-z][A-Za-z0-9_]*)\]$/', $t, $m)) return null;
        $f = strtolower($m[1]);
        return $f === 'record' ? false : $f;
    }

    const EXISTS_RECORD_FIELD = 'a field named "record" cannot be used by ' . self::TAG_EXISTS
        . ': "record" stands for the record ID. Write record (without brackets) to look up record IDs.';

    /** Parse one raw tag value into a fragment (see parseField). */
    private static function parseValue($val, array $opts = [])
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
        return self::validateConfig($cfg, $opts);
    }

    /**
     * Validate a decoded JSON config; return a clean engine-key fragment or
     * ['error'=>...]. Structural/type checks (is it a string, a whole number, a
     * known key) live here; ALL semantic rule validation is delegated to
     * checkFragment(), the one validator shared with the settings dialog.
     */
    private static function validateConfig(array $cfg, array $opts = [])
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
        self::takeCaseSensitive($cfg, $out);

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
        $errors = self::checkFragment($out, $opts);
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
    public static function checkFragment(array $frag, array $opts = [])
    {
        if (isset($frag['references']) || ($frag['uniqueScope'] ?? null) === 'record') {
            if (empty($opts['qualified'])) return ['Enable event and instance references in project settings first.'];
        }
        if (!empty($opts['qualified'])) {
            require_once __DIR__ . '/TemporalRules.php';
            $bindingErrors = TemporalRules::validate($frag);
            if ($bindingErrors) return $bindingErrors;
        }
        $errors = [];
        $type = isset($frag['type']) && $frag['type'] !== '' ? $frag['type'] : 'single';

        // "caseSensitive" means the same thing in every mode — how text compares
        // in the rule's conditions — so it is checked once, here, and merged into
        // whichever mode validator answers below.
        $caseErrors = self::caseSensitiveErrors($frag);

        // "alternates" describes accepted ID FORMATS, which only the check
        // modes read. Left unremarked on the others it validates clean, is
        // carried through the branch keys (ModeRegistry::branchKeys()) and shipped to the browser,
        // and a designer reads it as active configuration when nothing will
        // ever consult it.
        if (isset($frag['alternates']) && $frag['alternates'] !== null && $frag['alternates'] !== ''
                && !in_array($type, ModeRegistry::types('check'), true)) {
            return ['"alternates" applies only to ID checks (type "single" or "pooled"); a '
                . $type . ' rule has no ID format to match.'];
        }
        // Every mode except the ID check has its own validator (modes.json
        // "checker"): it shares "when"/"blockSave" with check rules but none of
        // the check-character/pattern/pooled machinery below.
        $checker = ModeRegistry::mode(ModeRegistry::modeOfType($type))['checker'];
        if ($checker !== null) return array_merge(self::$checker($frag, $opts), $caseErrors);

        $errors = $caseErrors;
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
                $w = Logic::parse($frag['when'], $opts);
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
                    . 'differently, and the order you write them decides which format a value is credited to.';
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
            $keepSets = [];
            $shapes = [];
            foreach ($alts as $i => $a) {
                if (!is_array($a)) {
                    $errors[] = 'alternate ' . ($i + 1) . ' must be a JSON object { ... } describing one '
                        . 'format.';
                    continue;
                }
                // Type BEFORE content. Every scalar below is cast or concatenated
                // as a string, and an array in any of them raised a TypeError
                // instead of an error message - which validateSettings swallows
                // into an ALLOWED save of a rule nothing validated (L-1). This
                // file is documented as the gate every channel goes through, so
                // it has to be safe standing on its own, not only behind the
                // normalizers that happen to run first today.
                $bad = null;
                foreach (['label', 'pattern', 'algorithm', 'source', 'strip'] as $sk) {
                    if (isset($a[$sk]) && $a[$sk] !== null && !is_string($a[$sk])) { $bad = $sk; break; }
                }
                if ($bad !== null) {
                    $errors[] = 'alternate ' . ($i + 1) . ': "' . $bad . '" must be text.';
                    continue;
                }
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
                }
                $shapes[$i] = ['name' => $nm, 'pattern' => $a['pattern'], 'formatOnly' => ($aAlgo === 'none'),
                               'lengths' => ($type === 'pooled') ? $ls : null];
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
                $keepSets[$i] = ['name' => $nm, 'keep' => self::keepSetFor($baseKeep, $aAlgo, $a['pattern']),
                                 'legacy' => self::keepSetFor($baseKeep, $aAlgo, $a['pattern'], true)];
            }
            // Both runtimes apply SHAPE OWNERSHIP: a value whose shape matches a
            // check-bearing alternate must pass that check, and a format-only
            // alternate is consulted only when no check-bearing shape matched
            // (CheckCharacter::validateSingleField, pooledVerifiesAs and their js
            // twins). That stops a format-only alternate from excusing a bad
            // check character inside the check-bearing shape. It cannot stop a
            // mis-scan that BREAKS the shape - an O for a 0, a dropped digit -
            // from landing in an overlapping format-only shape and passing on
            // format alone. So the two shapes must still be shown disjoint here.
            //
            // CheckCharacter::patternOverlap decides it exactly: is there a
            // value both patterns accept? For a pooled pair only values of a
            // length both alternates declare count, because the parser only
            // tries a format at its declared lengths; two pooled alternates
            // with no length in common never compete for a token. So C-Z
            // check-bearing codes beside legacy A/B codes, all six long, are
            // accepted, while SK[0-9]+[0-9A-Z] beside [A-Z0-9]{9} is refused
            // (the sampling probe this replaced looked only at 4-character SK
            // values and missed it). When the answer is unknown - a pattern the
            // reader cannot model, or the search budget exhausted - the rule is
            // refused rather than assumed safe (H-1).
            if (!$errors && count($shapes) > 1) {
                foreach ($shapes as $fi => $F) {
                    if (!$F['formatOnly']) continue;
                    foreach ($shapes as $ki => $K) {
                        if ($K['formatOnly']) continue;
                        $lens = ($F['lengths'] === null)
                            ? [null] : array_values(array_intersect($F['lengths'], $K['lengths']));
                        $ex = null;
                        $unknown = false;
                        foreach ($lens as $L) {
                            $ov = CheckCharacter::patternOverlap($K['pattern'], $F['pattern'], $L);
                            if ($ov['overlap'] === null) { $unknown = true; break; }
                            if ($ov['overlap']) { $ex = $ov['example']; break; }
                        }
                        if ($unknown) {
                            $errors[] = $K['name'] . '\'s pattern is too complex to prove it does not '
                                . 'overlap ' . $F['name'] . ', which has no check character. If the two '
                                . 'shapes do overlap, a mis-scanned ' . $K['name'] . ' ID that no longer fits '
                                . $K['name'] . '\'s pattern could pass as a clean ' . $F['name'] . ' ID, '
                                . 'its check character never tested. Write ' . $K['name'] . '\'s pattern with '
                                . 'explicit character classes (no backreferences), or narrow '
                                . $F['name'] . '\'s so the two cannot overlap.';
                            break 2;
                        }
                        if ($ex === null) continue;
                        $errors[] = $F['name'] . ' has no check character and its pattern also accepts '
                            . 'values meant for ' . $K['name'] . ' (for example "' . $ex . '"). A mis-scanned '
                            . $K['name'] . ' ID that no longer fits ' . $K['name'] . '\'s pattern - an O for '
                            . 'a 0, a dropped character - could then pass as a clean ' . $F['name'] . ' ID, '
                            . 'its check character never tested. Narrow ' . $F['name'] . '\'s pattern so '
                            . 'the two cannot overlap.';
                        break 2;
                    }
                }
            }
            if (!$errors && count($keepSets) > 1) {
                $idxs  = array_keys($keepSets);
                $first = $keepSets[$idxs[0]];
                $differ = function ($a, $b) {
                    return array_merge(array_diff(str_split($a), str_split($b)), array_diff(str_split($b), str_split($a)));
                };
                // Grandfathered: before 2.1.0-rc.2 the reader counted regex
                // syntax (the ":" of "(?:", the "," of "{n,m}") as kept
                // characters, so a sibling using that character literally
                // agreed by accident and the rule saved. That is allowed to
                // stand only when the field's runtime KEEP union is the same
                // under both readers, i.e. cleaning really is unchanged. An
                // escaped separator the old reader dropped ("LG\/[0-9]{5}")
                // is kept now, and kept for every sibling: a "/" people type
                // between SK IDs would turn into junk, so that rule is refused.
                $newUnion = ''; $legUnion = '';
                foreach ($keepSets as $ks) { $newUnion .= $ks['keep']; $legUnion .= $ks['legacy']; }
                $sameUnion = !array_diff(str_split($newUnion), str_split($legUnion));
                foreach ($idxs as $ix) {
                    $diff = $differ($keepSets[$ix]['keep'], $first['keep']);
                    if ($diff && $sameUnion && !$differ($keepSets[$ix]['legacy'], $first['legacy'])) $diff = [];
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
            // keys), and declaration order decides which alternate a value is
            // credited to.
            return ['error' => '"alternates" must be a JSON list [ ... ], not an object { ... } - '
                . 'the browser and the server order object keys differently, and the order you '
                . 'write them decides which format a value is credited to.'];
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
    private static function keepSetFor($base, $algo, $pattern, $legacy = false)
    {
        $K = $base;
        if ($algo !== 'none') {
            $CA = CheckCharacter::checkAlphabet($algo);
            for ($i = 0; $i < strlen($CA); $i++) {
                if (strpos($K, $CA[$i]) === false) $K .= $CA[$i];
            }
        }
        $lit = CheckCharacter::patternLiterals($pattern, $legacy);
        for ($i = 0; $i < strlen($lit); $i++) {
            if (strpos($K, $lit[$i]) === false) $K .= $lit[$i];
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
    public static function checkConstraint(array $frag, array $opts = [])
    {
        $errors = [];
        $assert = isset($frag['assert']) ? $frag['assert'] : null;
        if (!is_string($assert) || trim($assert) === '') {
            $errors[] = self::TAG_ASSERT . ' needs a non-empty "assert" condition, '
                . 'e.g. "[end_date]>=[start_date]".';
        } else {
            $a = Logic::parse($assert, $opts);
            if (empty($a['ok'])) $errors[] = 'the "assert" condition ' . $a['error'];
        }
        if (isset($frag['when'])) {
            if (!is_string($frag['when'])) {
                $errors[] = 'the "when" condition must be a non-empty condition string.';
            } else {
                $w = Logic::parse($frag['when'], $opts);
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
     * Carry a tag's "caseSensitive" option onto its fragment. An explicit false
     * IS the default, so it is dropped: the rule then groups and branches
     * exactly like one that never named the key. Any other non-true value is
     * passed through for checkFragment to refuse, the same refusal the
     * settings channel gets.
     */
    private static function takeCaseSensitive(array $cfg, array &$out)
    {
        if (array_key_exists('references', $cfg)) $out['references'] = $cfg['references'];
        if (array_key_exists('caseSensitive', $cfg) && $cfg['caseSensitive'] !== false) {
            $out['caseSensitive'] = $cfg['caseSensitive'];
        }
    }

    /**
     * A tag's text keys (modes.json "textKeys", e.g. "message") may be one
     * string, as always, or a map of Multi-Language Management language id to
     * text: {"default":"Too high","fr":"Trop élevé"}. A map is turned back
     * into the plain string every other reader expects (the audit, the scan,
     * pages without MLM): "default", else the first language listed. The
     * other languages go to $out["i18n"][key], keyed by lowercased language
     * id, for the page to pick from. A string, or no key, is left alone, so a
     * tag written before maps existed parses to exactly what it did before.
     *
     * A text key may also be a path into the tag's JSON, its steps joined by
     * "." and "*" standing for every key of an object: "show.chart.labels.*"
     * is each label of a growth chart. Such a text's other languages go
     * under the path with the "*" replaced by the key
     * ("show.chart.labels.title"). Returns the refusal, or null.
     */
    private static function takeTexts(array &$cfg, array &$out)
    {
        $mode = ModeRegistry::modeOfType(isset($out['type']) ? $out['type'] : '');
        foreach (ModeRegistry::textKeys($mode) as $tk) {
            foreach (self::textSlots($cfg, explode('.', $tk), []) as $slot) {
                list($path, $map) = $slot;
                if (!is_array($map)) continue;
                $name = implode('.', $path);
                $k = implode('" "', $path);   // as a message names it: "message", "show" "chart" "labels" "x"
                $eg = ' — e.g. "' . $path[count($path) - 1] . '":{"default":"…","fr":"…"}.';
                if (!$map || array_values($map) === $map) {
                    return '"' . $k . '" must be a string, or an object of language id to text' . $eg;
                }
                if (count($map) > self::MAX_TEXT_LANGUAGES) {
                    return '"' . $k . '" names more than ' . self::MAX_TEXT_LANGUAGES . ' languages.';
                }
                $langs = [];
                $default = null;
                foreach ($map as $lang => $text) {
                    // PHP turns a key such as "12" into an integer: not a language id either.
                    if (!is_string($lang) || !preg_match('/^[A-Za-z][A-Za-z0-9_-]{0,49}$/D', $lang)) {
                        return '"' . $k . '" language id ' . json_encode((string) $lang) . ' is not valid: use the id the '
                            . 'project\'s Multi-Language Management shows (letters, digits, "-" or "_"), or "default"' . $eg;
                    }
                    $id = strtolower($lang);
                    if (isset($langs[$id]) || ($id === 'default' && $default !== null)) {
                        return '"' . $k . '" names language "' . $id . '" twice (language ids ignore case).';
                    }
                    if (!is_string($text) || trim($text) === '') {
                        return '"' . $k . '" text for "' . $lang . '" must be a non-empty string.';
                    }
                    if ($id === 'default') $default = $text;
                    else $langs[$id] = $text;
                }
                if ($default === null) $default = reset($langs);
                self::setPath($cfg, $path, $default);
                if ($langs) $out['i18n'][$name] = $langs;
            }
        }
        return null;
    }

    /**
     * The values a text-key path reaches in $node: [[path, value], ...],
     * each path the list of keys walked. A step that is missing, or that
     * reaches something other than an object, ends that walk.
     */
    private static function textSlots($node, array $steps, array $path)
    {
        if (!$steps) return [[$path, $node]];
        $step = array_shift($steps);
        if (!is_array($node)) return [];
        if ($step !== '*') {
            return array_key_exists($step, $node) ? self::textSlots($node[$step], $steps, array_merge($path, [$step])) : [];
        }
        if (array_values($node) === $node && $node) return [];   // a list: the parser refuses it
        $out = [];
        foreach ($node as $key => $v) {
            if (!is_string($key)) continue;
            foreach (self::textSlots($v, $steps, array_merge($path, [$key])) as $s) $out[] = $s;
        }
        return $out;
    }

    /** Set the value at $path (a list of keys) in $node. */
    private static function setPath(array &$node, array $path, $value)
    {
        $at = &$node;
        foreach ($path as $key) $at = &$at[$key];
        $at = $value;
        unset($at);
    }

    /**
     * Strict boolean, like "suggestFix": "true" or 1 would hide a typo, and a
     * quoted "false" would read as true to a loose check.
     */
    private static function caseSensitiveErrors(array $frag)
    {
        return (array_key_exists('caseSensitive', $frag) && !is_bool($frag['caseSensitive']))
            ? ['"caseSensitive" must be true or false (unquoted).'] : [];
    }

    /**
     * Semantic validation for a required (@UVREQUIRED) fragment: optional
     * "when" gate (normative dialect in php/Logic.php), optional "message"
     * wording, optional blockSave. There is no condition to require — the
     * bare tag is complete.
     */
    public static function checkRequired(array $frag, array $opts = [])
    {
        return self::checkCommon($frag, $opts);
    }

    /**
     * The keys most modes share: an optional "when" gate (normative dialect
     * in php/Logic.php), "blockSave" and "message". "caseSensitive" is checked
     * once for every mode in checkFragment.
     */
    private static function checkCommon(array $frag, array $opts = [])
    {
        $errors = [];
        if (isset($frag['when'])) {
            if (!is_string($frag['when']) || trim($frag['when']) === '') {
                $errors[] = 'the "when" condition must be a non-empty condition string.';
            } else {
                $w = Logic::parse($frag['when'], $opts);
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
     * Semantic validation for a window (@UVWINDOW) fragment: a "from" that is
     * exactly one field reference, or "today", "now" or a readable date;
     * whole-number bounds within MAX_WINDOW_BOUND with earliest <= latest, a
     * known unit; a known period, never with a window, its offsets within
     * MAX_WINDOW_OFFSET and first <= last, weekStart only on a week;
     * strict-boolean notFuture and notPast, and at least one of window /
     * period / notFuture / notPast. Field types (is the
     * field a date, is the "from" field the same kind of date) are checked in
     * the channel glue, where the data dictionary is in hand.
     */
    public static function checkWindow(array $frag, array $opts = [])
    {
        $errors = [];
        $hasField = isset($frag['windowFrom']);
        $hasAnchor = isset($frag['windowAnchor']);
        $hasFrom = $hasField || $hasAnchor;
        $hasBound = array_key_exists('windowLo', $frag) || array_key_exists('windowHi', $frag);
        $hasPeriod = isset($frag['windowPeriod']);
        $notFuture = array_key_exists('windowNotFuture', $frag);
        $notPast = array_key_exists('windowNotPast', $frag);
        if ($hasAnchor) {
            $a = $frag['windowAnchor'];
            if (!is_string($a) || ($a !== 'today' && $a !== 'now' && TemporalLogic::anchorType($a) === null)) {
                $errors[] = '"from" ' . json_encode($a) . ' is not a date — write it as Y-M-D, e.g. "2026-01-01" or "2026-01-01 08:00".';
            }
        }
        if ($hasPeriod) {
            if (!in_array($frag['windowPeriod'], TemporalLogic::WINDOW_PERIODS, true)) {
                $errors[] = '"period" must be week, month, quarter or year.';
            }
            if ($hasBound) $errors[] = '"period" and "window" cannot be combined — use one or the other.';
            if (isset($frag['windowUnit'])) $errors[] = '"unit" only applies to a "window" — a "period" counts whole periods.';
        }
        if (array_key_exists('windowOffLo', $frag) || array_key_exists('windowOffHi', $frag)) {
            $lo = isset($frag['windowOffLo']) ? $frag['windowOffLo'] : null;
            $hi = isset($frag['windowOffHi']) ? $frag['windowOffHi'] : null;
            if (!$hasPeriod) $errors[] = '"offset" only applies to a "period".';
            if (!is_int($lo) || !is_int($hi)) {
                $errors[] = '"offset" must be a whole number of periods, or [first, last] — got ' . json_encode([$lo, $hi]) . '.';
            } elseif (abs($lo) > self::MAX_WINDOW_OFFSET || abs($hi) > self::MAX_WINDOW_OFFSET) {
                $errors[] = '"offset" is limited to ' . self::MAX_WINDOW_OFFSET . ' periods either way.';
            } elseif ($lo > $hi) {
                $errors[] = 'the "offset" first period (' . $lo . ') is after its last (' . $hi . ').';
            }
        }
        if (isset($frag['windowWeekStart'])) {
            if (!in_array($frag['windowWeekStart'], ['monday', 'sunday'], true)) $errors[] = '"weekStart" must be monday or sunday.';
            if (!$hasPeriod || $frag['windowPeriod'] !== 'week') $errors[] = '"weekStart" only applies to "period": "week".';
        }
        if ($hasAnchor && !$hasBound && !$hasPeriod) {
            $errors[] = '"from" needs a "window" to check against, e.g. "window":[-30,0], or a "period".';
        }
        if ($hasField) {
            $from = $frag['windowFrom'];
            if (ModeRegistry::operandRef($from, $opts) === null) {
                $errors[] = (empty($opts['qualified']) && ModeRegistry::operandRef($from, ['qualified' => true]) !== null)
                    ? 'a "from" date in another event or instance needs event and instance references — enable them in project settings first.'
                    : '"from" must be exactly one date field reference, e.g. "[visit_date]" or "[baseline_arm_1][visit_date]" '
                      . '(not a checkbox code, a {binding} or a list of instances).';
            }
            if (!$hasBound && !$hasPeriod) $errors[] = '"from" needs a "window" to check against, e.g. "window":[21,35], or a "period".';
        } elseif ($hasBound && !$hasAnchor) {
            $errors[] = '"window" needs a "from" date to count from — a field, "today" or "now".';
        }
        if (isset($frag['windowUnit']) && !$hasFrom) {
            $errors[] = '"unit" only applies to a "window" — add a "from" date.';
        }
        if (!$hasFrom && !$hasBound && !$hasPeriod && !$notFuture && !$notPast) {
            $errors[] = self::TAG_WINDOW . ' needs a "from" date with a "window", a "period", "notFuture": true or "notPast": true.';
        }
        foreach (['windowLo' => 'earliest', 'windowHi' => 'latest'] as $k => $label) {
            if (!array_key_exists($k, $frag)) continue;
            $b = $frag[$k];
            if (!is_int($b)) {
                $errors[] = 'the "window" ' . $label . ' bound must be a whole number or null — got ' . json_encode($b) . '.';
            } elseif (abs($b) > self::MAX_WINDOW_BOUND) {
                $errors[] = 'the "window" ' . $label . ' bound is limited to ' . self::MAX_WINDOW_BOUND . ' units either side of the "from" date.';
            }
        }
        if (isset($frag['windowLo'], $frag['windowHi']) && is_int($frag['windowLo']) && is_int($frag['windowHi'])
                && $frag['windowLo'] > $frag['windowHi']) {
            $errors[] = 'the "window" earliest bound (' . $frag['windowLo'] . ') is after its latest bound ('
                . $frag['windowHi'] . ').';
        }
        if (isset($frag['windowUnit'])
                && (!is_string($frag['windowUnit']) || !in_array($frag['windowUnit'], TemporalLogic::windowUnitNames(), true))) {
            $units = TemporalLogic::windowUnitNames();
            $errors[] = '"unit" must be ' . implode(', ', array_slice($units, 0, -1)) . ' or ' . end($units) . '.';
        }
        if ($notFuture && $frag['windowNotFuture'] !== true) {
            $errors[] = '"notFuture" must be true or false (unquoted).';
        }
        if ($notPast && $frag['windowNotPast'] !== true) {
            $errors[] = '"notPast" must be true or false (unquoted).';
        }
        return array_merge($errors, self::checkCommon($frag, $opts));
    }

    /**
     * Semantic validation for an exists (@UVEXISTS) fragment: a source that is
     * "record" or a field name, a known scope, an "event" that is a plausible
     * unique event name, "match" pairs of field names under the cap, and the
     * combinations that make no sense ("event" with "scope":"event"; "event",
     * "scope":"event" or "match" on a record-ID lookup). Whether the fields and
     * the event exist is checked in the channel glue with the dictionary in hand.
     * Event and instance references in "when" are not supported here yet.
     * "where" and "whereMessage": existsWhereErrors.
     */
    public static function checkExists(array $frag, array $opts = [])
    {
        $errors = [];
        $in = isset($frag['existsIn']) ? $frag['existsIn'] : null;
        if (!is_string($in) || ($in !== 'record' && !preg_match('/^[a-z][a-z0-9_]*$/', $in))) {
            $errors[] = self::TAG_EXISTS . ' needs "in": "record" or one field reference such as "[specimen_id]".';
        }
        $scope = isset($frag['existsScope']) ? $frag['existsScope'] : 'project';
        if (!in_array($scope, self::EXISTS_SCOPES, true)) {
            $scopes = self::EXISTS_SCOPES;
            $last = array_pop($scopes);
            $errors[] = '"scope" must be ' . implode(', ', $scopes) . ' or ' . $last . '.';
        }
        if (isset($frag['existsEvent']) && (!is_string($frag['existsEvent']) || !preg_match('/^[a-z0-9_]+$/', $frag['existsEvent']))) {
            $errors[] = '"event" must be a unique event name such as "enrolment_arm_1".';
        }
        if (isset($frag['existsEvent']) && $scope === 'event') {
            $errors[] = '"event" and "scope":"event" cannot be combined — "event" names the one event to look in, '
                . '"scope":"event" means the event of the entry being checked.';
        }
        if ($in === 'record') {
            // With "return" or "where", "event" names the event whose values
            // are copied or tested; the record ID is still looked up as a whole.
            if (isset($frag['existsEvent']) && !isset($frag['existsReturn']) && !isset($frag['existsWhere'])) {
                $errors[] = '"event" does not apply to "in":"record" — a record ID belongs to the whole record.';
            }
            if ($scope === 'event') $errors[] = '"scope":"event" does not apply to "in":"record" — a record ID belongs to the whole record.';
            if (isset($frag['existsMatch'])) $errors[] = '"match" needs "in" to name a field; a record ID has nothing to match.';
        }
        if (isset($frag['existsMatch'])) {
            $match = $frag['existsMatch'];
            if (!is_array($match) || !$match) {
                $errors[] = '"match" must name at least one target field.';
            } elseif (count($match) > self::MAX_EXISTS_MATCH) {
                $errors[] = '"match" is limited to ' . self::MAX_EXISTS_MATCH . ' fields.';
            } else {
                foreach ($match as $t => $l) {
                    if (!is_string($t) || !preg_match('/^[a-z][a-z0-9_]*$/', $t)) {
                        $errors[] = '"match" target ' . json_encode($t) . ' is not a valid REDCap field name.';
                        break;
                    }
                    if ($t === 'record' || $l === 'record') {
                        $errors[] = self::EXISTS_RECORD_FIELD;
                        break;
                    }
                    if (!is_string($l) || !preg_match('/^[a-z][a-z0-9_]*$/', $l)) {
                        $errors[] = '"match" value for "' . $t . '" must be one field reference such as "[site]".';
                        break;
                    }
                    if ($t === $in) {
                        $errors[] = '"match" target "' . $t . '" is the field named in "in" — it already holds the value looked up.';
                        break;
                    }
                }
            }
        }
        if (isset($frag['existsSurveys']) && $frag['existsSurveys'] !== true) {
            $errors[] = '"surveys" must be true or false (unquoted).';
        }
        if (isset($frag['existsProject'])) {
            if (!is_string($frag['existsProject']) || !preg_match(self::EXISTS_PROJECT_RE, $frag['existsProject'])) {
                $errors[] = '"project" must be a project id such as 123, or a project alias such as "lab" '
                    . '(letters, digits, _ and -, starting with a letter).';
            }
            // Groups and events belong to one project: this record's group or
            // event means nothing in another one.
            if ($scope === 'dag' || $scope === 'event') {
                $errors[] = '"scope":"' . $scope . '" cannot be used with "project" — Data Access Groups and events '
                    . 'are not shared between projects. Use "event" to name one event of the other project.';
            }
        }
        if (isset($frag['existsReturn'])) {
            $errors = array_merge($errors, self::existsReturnErrors($frag, $in));
        }
        if (array_key_exists('existsWhere', $frag) || array_key_exists('whereMessage', $frag)) {
            $errors = array_merge($errors, self::existsWhereErrors($frag, $opts));
        }
        // The lookup reads saved values of one project through one read; an
        // event/instance reference in "when" would need the extended pipeline,
        // which this tag does not run yet.
        if (isset($frag['when']) && is_string($frag['when']) && trim($frag['when']) !== ''
                && empty(Logic::parse($frag['when'])['ok']) && !empty(Logic::parse($frag['when'], $opts)['ok'])) {
            $errors[] = self::TAG_EXISTS . ' does not support event or instance references in "when" yet — use '
                . 'fields of this entry.';
        }
        return array_merge($errors, self::checkCommon($frag, $opts));
    }

    /**
     * The fields an exists "where" condition tests, in the order it names
     * them, each once; [] when the text does not parse (existsWhereErrors
     * says why). Parsed without options: "where" reads one entry of the place
     * searched, so it takes no event or instance references.
     */
    public static function existsWhereFieldsOf($where)
    {
        $p = is_string($where) ? Logic::parse($where) : ['ok' => false];
        if (empty($p['ok'])) return [];
        $out = [];
        foreach (Logic::referencedFields($p['ast']) as $ref) $out[$ref[0]] = true;
        return array_keys($out);
    }

    /**
     * Whether one entry's values ($values: field => stored value, a checkbox
     * as code => "0"/"1") meet a parsed "where" condition. A blank side of
     * < > <= >= gives no answer, so the comparison fails (Logic::BLANK_INERT);
     * text compares with $caseSensitive; a field listed in $commaFields holds
     * numbers with a decimal comma and is read by value ("3,5" is 3.5).
     * UniversalValidator::existsWherePasses gives it an entry of a lookup's
     * read; tests/docs_examples_php.php the entries the docs describe.
     */
    public static function existsWhereHolds(array $ast, array $values, $caseSensitive, array $commaFields = [])
    {
        foreach ($commaFields as $f) {
            if (!isset($values[$f]) || is_array($values[$f])) continue;
            $n = Logic::normalizeNumber($values[$f], true);
            if ($n !== null && $n !== '') $values[$f] = $n;
        }
        return Logic::evaluate($ast, $values, Logic::BLANK_INERT, (bool) $caseSensitive);
    }

    /**
     * The "where" part of an exists rule: a condition that parses without
     * event or instance references, tests at least one field and at most
     * MAX_EXISTS_WHERE, and never "record" (the record ID is what
     * "in":"record" looks up). "whereMessage" needs a "where". Whether the
     * fields exist where the lookup searches, can be read from the entry
     * found, and are compared with values of their kind is checked with the
     * dictionary in hand (UniversalValidator::existsWhereProblem).
     */
    private static function existsWhereErrors(array $frag, array $opts)
    {
        $errors = [];
        if (array_key_exists('whereMessage', $frag) && !array_key_exists('existsWhere', $frag)) {
            $errors[] = '"whereMessage" needs "where": it is the message shown when the value is saved but the entry '
                . 'holding it does not meet the "where" condition.';
        }
        if (array_key_exists('whereMessage', $frag) && (!is_string($frag['whereMessage']) || trim($frag['whereMessage']) === '')) {
            $errors[] = '"whereMessage" must be a non-empty string.';
        }
        if (!array_key_exists('existsWhere', $frag)) return $errors;
        $w = $frag['existsWhere'];
        $eg = ' — e.g. "where":"[consent]=\'1\' and [status]<>\'3\'".';
        if (!is_string($w) || trim($w) === '') return array_merge($errors, ['"where" must be a condition on fields of the entry found' . $eg]);
        $p = Logic::parse($w);
        if (empty($p['ok'])) {
            if (!empty(Logic::parse($w, ['qualified' => true] + $opts)['ok'])) {
                $errors[] = '"where" tests fields of the entry the lookup finds, so it takes no event or instance '
                    . 'references — write [field]. Use "event" to choose the event searched.';
            } else {
                $errors[] = 'the "where" condition ' . $p['error'];
            }
            return $errors;
        }
        $fields = self::existsWhereFieldsOf($w);
        if (!$fields) return array_merge($errors, ['"where" must test at least one field of the entry found' . $eg]);
        if (in_array('record', $fields, true)) {
            $errors[] = '"where" cannot test [record]: a field named "record" stands for the record ID, which "in":"record" '
                . 'looks up. Test fields of the entry found.';
        }
        if (count($fields) > self::MAX_EXISTS_WHERE) {
            $errors[] = '"where" may test at most ' . self::MAX_EXISTS_WHERE . ' fields — it names ' . count($fields) . '.';
        }
        // The fields come from the parser; a stored rule edited by hand must
        // not search fewer (or other) fields than its condition tests.
        if (!isset($frag['existsWhereFields']) || $frag['existsWhereFields'] !== $fields) {
            $errors[] = 'the fields of "where" do not match its condition — save the rule again.';
        }
        return $errors;
    }

    /**
     * The "return" part of an exists rule: pairs of field names under the cap,
     * one field here per value, and none of them a field the rule reads or
     * searches here. A survey never gets the values: a respondent holds no
     * rights that could be checked, so "return" and "surveys" cannot go
     * together. Whether the fields exist, are on this form and can hold the
     * values is checked with the dictionaries in hand
     * (UniversalValidator::existsReturnProblem).
     */
    private static function existsReturnErrors(array $frag, $in)
    {
        $ret = $frag['existsReturn'];
        if (!is_array($ret) || !$ret) return ['"return" must name at least one field.'];
        if (count($ret) > self::MAX_EXISTS_RETURN) return ['"return" is limited to ' . self::MAX_EXISTS_RETURN . ' fields.'];
        $errors = [];
        $local = isset($frag['existsProject']) ? [] : [(string) $in => true];
        foreach ((isset($frag['existsMatch']) && is_array($frag['existsMatch'])) ? $frag['existsMatch'] : [] as $t => $l) {
            $local[(string) $l] = true;
            if (!isset($frag['existsProject'])) $local[(string) $t] = true;
        }
        if (!isset($frag['existsProject'])) {
            foreach ((isset($frag['existsWhereFields']) && is_array($frag['existsWhereFields'])) ? $frag['existsWhereFields'] : [] as $t) {
                $local[(string) $t] = true;
            }
        }
        $seen = [];
        foreach ($ret as $s => $t) {
            if (!is_string($s) || ($s !== 'record' && !preg_match('/^[a-z][a-z0-9_]*$/', $s))) {
                $errors[] = '"return" names ' . json_encode($s) . ', which is not a field name (or "record" for the record ID found).';
                break;
            }
            if (!is_string($t) || !preg_match('/^[a-z][a-z0-9_]*$/', $t) || $t === 'record') {
                $errors[] = '"return" value for "' . $s . '" must be one field reference of this form, such as "[result_site]".';
                break;
            }
            if (isset($seen[$t])) {
                $errors[] = '"return" writes "' . $seen[$t] . '" and "' . $s . '" to the same field "[' . $t . ']" — give each its own.';
                break;
            }
            $seen[$t] = $s;
            if (isset($local[$t])) {
                $errors[] = '"return" writes to "[' . $t . ']", which this lookup reads or searches — write the value to a field of its own.';
                break;
            }
        }
        if (!empty($frag['existsSurveys'])) {
            $errors[] = '"return" cannot be used with "surveys": a survey respondent has no rights the module could check '
                . 'before showing values of another record. Drop "surveys" (the save still fills the fields when a person '
                . 'with rights saves the record).';
        }
        return $errors;
    }

    /**
     * Semantic validation for a range (@UVRANGE) fragment: at least one limit,
     * every limit an exact decimal string, low <= high within each tier, each
     * soft limit inside the hard range (a value the soft range calls usual must
     * never be implausible), the two enforcement keys and a short unit label.
     * A growth rule's keys are checked by checkGrowth: z limits within
     * MAX_GROWTH_Z, sex codes, exactly one way to read the axis, each input a
     * single field reference. Whether the field holds numbers, whether the
     * reference exists and whether each input field has the right kind are
     * checked in the channel glue, where the data dictionary is in hand
     * (UniversalValidator::annotateRangeField and annotateRangeDictionary).
     */
    public static function checkRange(array $frag, array $opts = [])
    {
        $errors = [];
        if (array_key_exists('blockSave', $frag)) {
            $errors[] = '"blockSave" does not apply to ' . self::TAG_RANGE . ' — use "softBlock" and "hardBlock".';
        }
        $b = [];
        foreach (['rangeSoftLo' => 'soft low', 'rangeSoftHi' => 'soft high', 'rangeHardLo' => 'hard low', 'rangeHardHi' => 'hard high'] as $k => $label) {
            if (!array_key_exists($k, $frag)) continue;
            if (!is_string($frag[$k]) || !preg_match(Logic::NUM_RE, $frag[$k])) {
                $errors[] = 'the ' . $label . ' limit must be a number — got ' . json_encode($frag[$k]) . '.';
                continue;
            }
            $b[$k] = $frag[$k];
        }
        foreach (['rangePlausibleLo' => 'plausible low', 'rangePlausibleHi' => 'plausible high'] as $k => $label) {
            if (!array_key_exists($k, $frag)) continue;
            if (!is_string($frag[$k]) || !preg_match(Logic::NUM_RE, $frag[$k])) {
                $errors[] = 'the ' . $label . ' limit must be a number — got ' . json_encode($frag[$k]) . '.';
                continue;
            }
            $b[$k] = $frag[$k];
        }
        if (isset($b['rangePlausibleLo'], $b['rangePlausibleHi']) && Logic::numCompare($b['rangePlausibleLo'], $b['rangePlausibleHi']) > 0) {
            $errors[] = 'the "plausible" low limit (' . $b['rangePlausibleLo'] . ') is above its high limit (' . $b['rangePlausibleHi'] . ').';
        }
        if (array_key_exists('rangeMeasureScale', $frag)) {
            $s = $frag['rangeMeasureScale'];
            if (!is_string($s) || !preg_match(Logic::NUM_RE, $s)
                    || Logic::numCompare($s, self::MIN_MEASURE_SCALE) < 0 || Logic::numCompare($s, self::MAX_MEASURE_SCALE) > 0) {
                $errors[] = '"measureScale" must be a number from ' . self::MIN_MEASURE_SCALE . ' to ' . self::MAX_MEASURE_SCALE
                    . ', such as 0.001 for grams typed into a reference in kg — got ' . (is_string($s) ? $s : json_encode($s)) . '.';
            }
        }
        // A rule with a reference that returns or shows values none of them
        // made from a limit (the z-score, the percentile, reference values,
        // a chart) judges nothing and needs no limits: what it does is give
        // those values. A value or a column made from a limit says so itself.
        $limitFree = isset($frag['rangeReference']) && GrowthOutputs::problem() === null
            && ((isset($frag['rangeReturn']) && is_array($frag['rangeReturn']) && $frag['rangeReturn']
                 && GrowthOutputs::limitFree(array_map('strval', array_keys($frag['rangeReturn']))))
                || (isset($frag['rangeShow']) && is_array($frag['rangeShow']) && $frag['rangeShow']
                    && (!isset($frag['rangeReturn']) || (is_array($frag['rangeReturn'])
                        && GrowthOutputs::limitFree(array_map('strval', array_keys($frag['rangeReturn'])))))));
        if (!array_key_exists('rangeSoftLo', $frag) && !array_key_exists('rangeSoftHi', $frag)
                && !array_key_exists('rangeHardLo', $frag) && !array_key_exists('rangeHardHi', $frag) && !$limitFree) {
            $errors[] = self::TAG_RANGE . ' needs "soft" or "hard" limits, e.g. "soft":[12,17.5],"hard":[3,25].';
        }
        $zLimits = array_intersect_key($b, array_flip(['rangeSoftLo', 'rangeSoftHi', 'rangeHardLo', 'rangeHardHi']));
        foreach (['Soft' => 'soft', 'Hard' => 'hard'] as $t => $label) {
            if (isset($b['range' . $t . 'Lo'], $b['range' . $t . 'Hi'])
                    && Logic::numCompare($b['range' . $t . 'Lo'], $b['range' . $t . 'Hi']) > 0) {
                $errors[] = 'the "' . $label . '" low limit (' . $b['range' . $t . 'Lo'] . ') is above its high limit ('
                    . $b['range' . $t . 'Hi'] . ').';
            }
        }
        foreach (['rangeSoftLo' => 'low', 'rangeSoftHi' => 'high'] as $k => $side) {
            if (!isset($b[$k])) continue;
            $below = isset($b['rangeHardLo']) && Logic::numCompare($b[$k], $b['rangeHardLo']) < 0;
            $above = isset($b['rangeHardHi']) && Logic::numCompare($b[$k], $b['rangeHardHi']) > 0;
            if ($below || $above) {
                $errors[] = 'the "soft" ' . $side . ' limit (' . $b[$k] . ') is outside the "hard" range — a value '
                    . '"soft" accepts would be refused as implausible. Keep both soft limits inside the hard ones.';
            }
        }
        if (isset($frag['rangeSoftBlock']) && !in_array($frag['rangeSoftBlock'], ['off', 'confirm'], true)) {
            $errors[] = '"softBlock" must be off or confirm.';
        }
        if (isset($frag['rangeHardBlock']) && !in_array($frag['rangeHardBlock'], ['confirm', 'hard'], true)) {
            $errors[] = '"hardBlock" must be confirm or hard.';
        }
        if (array_key_exists('rangeUnit', $frag)) {
            $u = $frag['rangeUnit'];
            if (!is_string($u) || $u === '' || preg_match('/[\x00-\x1f\x7f]/', $u)
                    || (function_exists('mb_strlen') ? mb_strlen($u, 'UTF-8') : strlen($u)) > self::MAX_RANGE_UNIT) {
                $errors[] = '"unit" must be a short label of up to ' . self::MAX_RANGE_UNIT . ' characters, such as "g/dL".';
            }
        }
        return array_merge($errors, self::checkGrowth($frag, $zLimits, $opts), self::checkCommon($frag, $opts));
    }

    /**
     * The growth-reference part of a range fragment. Without "reference" none
     * of its keys apply. With one, the limits are z-scores within
     * MAX_GROWTH_Z, "sex" with distinct "male" and "female" codes is required,
     * and so is exactly one of "age" (for a reference by age) and "by" (for one
     * by length or height). Each input is one field reference, checked the way
     * a @UVWINDOW "from" date is. Whether the reference exists, and whether its
     * axis wants "age" or "by", is checked with the data dictionary in hand
     * (UniversalValidator::annotateRangeDictionary).
     */
    private static function checkGrowth(array $frag, array $limits, array $opts)
    {
        $errors = [];
        $inputs = ['rangeSex' => '"sex"', 'rangeAgeDob' => '"age" "dob"', 'rangeAgeAt' => '"age" "at"',
                   'rangeAgeDays' => '"age" "days"', 'rangeAgeMonths' => '"age" "months"', 'rangeBy' => '"by"'];
        if (!array_key_exists('rangeReference', $frag)) {
            foreach (array_merge(array_keys($inputs), ['rangeMale', 'rangeFemale']) as $k) {
                if (array_key_exists($k, $frag)) {
                    return ['"sex", "male", "female", "age" and "by" only apply with a "reference", such as "reference":"who-wfa".'];
                }
            }
            foreach (['rangePlausibleLo', 'rangePlausibleHi', 'rangeMeasureScale'] as $k) {
                if (array_key_exists($k, $frag)) {
                    return ['"plausible" and "measureScale" only apply with a "reference" — without one, "soft" and "hard" '
                        . 'are already in the unit the field holds.'];
                }
            }
            foreach (['rangeReturn', 'rangeReturnLimits', 'rangeDecimals', 'rangePercentileDecimals'] as $k) {
                if (array_key_exists($k, $frag)) {
                    return ['"return", "returnLimits", "decimals" and "percentileDecimals" only apply with a "reference": they write '
                        . 'values worked out from a growth reference, such as "reference":"who-wfa".'];
                }
            }
            if (array_key_exists('rangeShow', $frag)) {
                return ['"show" only applies with a "reference": it draws the curves of a growth reference, or a table of its values, '
                    . 'such as "reference":"who-wfa".'];
            }
            return [];
        }
        $id = $frag['rangeReference'];
        if (!is_string($id) || !preg_match(GrowthReference::ID_RE, $id)) {
            $errors[] = '"reference" must be the id of a growth reference, such as "who-wfa" (lower-case letters, digits, ".", "_" or "-").';
        }
        // With "measureScale" the field holds another unit than the reference
        // (grams for a table in kg): "unit" names it, for a returned "range".
        if (array_key_exists('rangeUnit', $frag) && !array_key_exists('rangeMeasureScale', $frag)) {
            $errors[] = '"unit" does not apply with a "reference" unless "measureScale" is set — the reference sets the unit '
                . 'of the measurement, and "unit" names the unit the field holds when "measureScale" converts it.';
        }
        foreach ($limits as $k => $z) {
            if (Logic::numCompare($z, '-' . self::MAX_GROWTH_Z) < 0 || Logic::numCompare($z, (string) self::MAX_GROWTH_Z) > 0) {
                $errors[] = 'with a "reference" the limits are z-scores, which must lie between -' . self::MAX_GROWTH_Z
                    . ' and ' . self::MAX_GROWTH_Z . ' — got ' . $z . '.';
                break;
            }
        }
        if (!isset($frag['rangeSex'])) $errors[] = 'a "reference" needs "sex", the field holding the sex, e.g. "sex":"[sex]".';
        foreach (['rangeMale' => 'male', 'rangeFemale' => 'female'] as $k => $word) {
            if (!isset($frag[$k]) || !is_string($frag[$k]) || $frag[$k] === '') {
                $errors[] = 'a "reference" needs "' . $word . '", the code the "sex" field stores for ' . $word . ', e.g. "'
                    . $word . '":"' . ($word === 'male' ? '1' : '2') . '".';
            }
        }
        if (isset($frag['rangeMale'], $frag['rangeFemale']) && is_string($frag['rangeMale']) && is_string($frag['rangeFemale'])) {
            if ($frag['rangeMale'] === $frag['rangeFemale']) {
                $errors[] = '"male" and "female" are the same code ("' . $frag['rangeMale'] . '").';
            } elseif (empty($frag['caseSensitive']) && Logic::foldAscii($frag['rangeMale']) === Logic::foldAscii($frag['rangeFemale'])) {
                // sex codes are matched with letter case ignored (GrowthReference::sexOf)
                $errors[] = '"male" "' . $frag['rangeMale'] . '" and "female" "' . $frag['rangeFemale'] . '" differ only in letter case, '
                    . 'which this rule ignores. Add "caseSensitive": true to tell them apart.';
            }
        }
        $age = array_key_exists('rangeAgeDob', $frag) || array_key_exists('rangeAgeAt', $frag)
            || array_key_exists('rangeAgeDays', $frag) || array_key_exists('rangeAgeMonths', $frag);
        $by = array_key_exists('rangeBy', $frag);
        if ($age && $by) {
            $errors[] = 'give "age" or "by", not both — a reference is read by age, or by length or height.';
        } elseif (!$age && !$by) {
            $errors[] = 'a "reference" needs "age" (e.g. "age":{"dob":"[dob]","at":"[visit_date]"}) or, for a reference by '
                . 'length or height, "by" (e.g. "by":"[height_cm]").';
        }
        if (array_key_exists('rangeAgeDob', $frag) !== array_key_exists('rangeAgeAt', $frag)) {
            $errors[] = '"age" needs both "dob" and "at".';
        }
        foreach ($inputs as $k => $label) {
            if (!array_key_exists($k, $frag)) continue;
            if (ModeRegistry::operandRef($frag[$k], $opts) !== null) continue;
            $errors[] = (empty($opts['qualified']) && ModeRegistry::operandRef($frag[$k], ['qualified' => true]) !== null)
                ? 'a ' . $label . ' field in another event or instance needs event and instance references — enable them in project settings first.'
                : $label . ' must be exactly one field reference, e.g. "[' . self::growthExample($k) . ']" or "[baseline_arm_1]['
                  . self::growthExample($k) . ']" (not a checkbox code, a {binding} or a list of instances).';
        }
        if (array_key_exists('rangeShow', $frag)) $errors = array_merge($errors, self::checkShow($frag, $limits));
        return array_merge($errors, self::checkReturn($frag, $limits, array_keys($inputs), $opts));
    }

    /**
     * The "return" part of a growth rule: which values go to which fields.
     * Each key is an output of php/growth_outputs.json (GrowthOutputs::parse)
     * and each field one plain name (no
     * two values to one field, and none of them an input of this rule: the
     * rule would write what it reads). Bounds come from the "soft" limits
     * unless "returnLimits" says "hard", and the limits asked for must be
     * there: "lower" needs the low limit, "upper" the high one, and "range"
     * either (one-sided it reads "at least 7.7 kg"), as the catalog's "needs"
     * says. "decimals" is how many decimals the bounds and the values at a
     * z-score or percentile are written with; "percentileDecimals" is the
     * percentile's, and needs a "percentile" to apply to (returnKeyUnused).
     * Two keys for one value ("valueAtZ:2", "valueAtZ:2.0") are refused when
     * the tag is parsed. A catalog that cannot be read refuses the rule. That the fields exist, are Text
     * fields of the measured field's form, and take such a value is checked
     * with the data dictionary in hand (UniversalValidator::annotateRangeDictionary).
     */
    private static function checkReturn(array $frag, array $limits, array $inputKeys, array $opts)
    {
        $errors = [];
        $has = array_key_exists('rangeReturn', $frag);
        $columns = isset($frag['rangeShow']['table']['columns']) && is_array($frag['rangeShow']['table']['columns'])
            ? $frag['rangeShow']['table']['columns'] : [];
        if (!$has && !$columns) {
            foreach (['rangeReturnLimits' => '"returnLimits" only applies with "return" or a "show" "table" — it picks the limits the bounds are made from.',
                      'rangeDecimals' => '"decimals" only applies with "return" or a "show" "table" — it sets the decimals of the bounds and values they hold.'] as $k => $why) {
                if (array_key_exists($k, $frag)) $errors[] = $why;
            }
            if (array_key_exists('rangePercentileDecimals', $frag)) {
                $errors[] = '"percentileDecimals" only applies with "return" or a "show" "table" — it sets how the percentile is written.';
            }
            return $errors;
        }
        if (!$has) return self::returnShapingErrors($frag, [], $columns);
        $why = GrowthOutputs::problem();
        if ($why !== null) return ['"return" cannot be checked: ' . $why . '.'];
        $ret = $frag['rangeReturn'];
        if (!is_array($ret) || !$ret) {
            return ['"return" must say which field gets which value, e.g. {"z":"[wfa_z]","range":"[wfa_range]"}.'];
        }
        if (count($ret) > self::MAX_RANGE_RETURN) {
            $errors[] = '"return" is limited to ' . self::MAX_RANGE_RETURN . ' values.';
        }
        $seen = [];
        $needs = [];
        foreach ($ret as $k => $f) {
            $k = (string) $k;
            $known = GrowthOutputs::parse($k);
            if (isset($known['error'])) {
                $errors[] = $known['error'];
                continue;
            }
            $needs[$k] = GrowthOutputs::spec($known['of'])['needs'];
            if (!is_string($f) || !preg_match('/^[a-z][a-z0-9_]*$/', $f)) {
                $errors[] = '"return" "' . $k . '" must be one field name, such as "' . GrowthOutputs::exampleOf($k) . '".';
                continue;
            }
            if (isset($seen[$f])) {
                $errors[] = '"return" writes "' . $seen[$f] . '" and "' . $k . '" to the same field "[' . $f . ']" — give each its own.';
            }
            $seen[$f] = $k;
        }
        // not a field the rule reads
        foreach ($inputKeys as $ik) {
            if (!array_key_exists($ik, $frag)) continue;
            $op = ModeRegistry::operandRef($frag[$ik], ['qualified' => true]);
            if ($op !== null && isset($seen[(string) $op[1]])) {
                $errors[] = '"return" writes "' . $seen[(string) $op[1]] . '" to "[' . $op[1] . ']", which this rule reads — '
                    . 'give the value a field of its own.';
            }
        }
        $tier = isset($frag['rangeReturnLimits']) ? $frag['rangeReturnLimits'] : 'soft';
        if (!in_array($tier, self::RETURN_LIMITS, true)) {
            $errors[] = '"returnLimits" must be "soft" or "hard".';
            return $errors;
        }
        foreach ($needs as $k => $need) {
            $miss = self::missingLimit($need, $tier, $limits);
            if ($miss !== null) $errors[] = '"return" "' . $k . '" is made from ' . $miss[0] . ', which this rule does not have — ' . $miss[1] . '.';
        }
        return array_merge($errors, self::returnShapingErrors($frag, array_keys($ret), $columns));
    }

    /**
     * The checks of "decimals" and "percentileDecimals" as they reach the
     * fragment (the dialog channel, or a fragment the dictionary hook
     * completed): whole numbers in range, and a "percentileDecimals" with a
     * percentile to apply to among the values returned ($keys) or shown in
     * the page table ($columns).
     */
    private static function returnShapingErrors(array $frag, array $keys, array $columns)
    {
        $errors = [];
        if (array_key_exists('rangeDecimals', $frag)) {
            $d = $frag['rangeDecimals'];
            if (!is_int($d) || $d < 0 || $d > GrowthReference::MAX_DECIMALS) {
                $errors[] = '"decimals" must be a whole number from 0 to ' . GrowthReference::MAX_DECIMALS . '.';
            }
        }
        if (array_key_exists('rangePercentileDecimals', $frag)) {
            $pd = $frag['rangePercentileDecimals'];
            if (!is_int($pd) || $pd < 0 || $pd > GrowthReference::MAX_PERCENTILE_DECIMALS) {
                $errors[] = '"percentileDecimals" must be a whole number from 0 to ' . GrowthReference::MAX_PERCENTILE_DECIMALS . '.';
            } else {
                $why = self::returnKeyUnused(['percentileDecimals' => $pd], $keys, $columns);
                if ($why !== null) $errors[] = $why;
            }
        }
        return $errors;
    }

    private static function growthExample($key)
    {
        $ex = ['rangeSex' => 'sex', 'rangeAgeDob' => 'dob', 'rangeAgeAt' => 'visit_date', 'rangeAgeDays' => 'age_days',
               'rangeAgeMonths' => 'age_months', 'rangeBy' => 'height_cm'];
        return $ex[$key];
    }

    /**
     * Semantic validation for a unique (@UVUNIQUE) fragment: scope whitelist,
     * composite "with" list and "also" list shapes (valid field-name syntax,
     * no duplicates, capped, no field in both), "also" not under the record
     * scope, optional when/message/blockSave/surveys. Whether the listed
     * fields exist (and are scalar) is checked in the channel glue with the
     * data dictionary in hand.
     */
    public static function checkUnique(array $frag, array $opts = [])
    {
        $errors = [];
        if (isset($frag['uniqueScope'])
            && !in_array($frag['uniqueScope'], !empty($opts['qualified']) ? array_merge(self::UNIQUE_SCOPES, ['record']) : self::UNIQUE_SCOPES, true)) {
            $errors[] = '"scope" must be ' . implode(', ', self::UNIQUE_SCOPES) . '.';
        }
        $withOk = isset($frag['uniqueWith']) ? self::checkFieldNameList($frag['uniqueWith'], 'with', self::MAX_UNIQUE_WITH, $errors) : true;
        if (isset($frag['uniqueAlso'])) {
            $alsoOk = self::checkFieldNameList($frag['uniqueAlso'], 'also', self::MAX_UNIQUE_ALSO, $errors);
            if ($alsoOk && $withOk && isset($frag['uniqueWith'])) {
                foreach ($frag['uniqueAlso'] as $a) {
                    if (in_array(strtolower($a), array_map('strtolower', $frag['uniqueWith']), true)) {
                        $errors[] = '"' . strtolower($a) . '" is in both "with" and "also" — a field is either part of the key or a place the value is looked for.';
                        break;
                    }
                }
            }
            // The record scope compares the entries of one record, and "also"
            // the other records' fields: under it the value would be compared
            // with the same record's other fields, which @UVASSERT does.
            if (($frag['uniqueScope'] ?? null) === 'record') {
                $errors[] = '"also" cannot be used with "scope":"record" — to compare fields of one record, use @UVASSERT.';
            }
        }
        if (isset($frag['uniqueSurveys']) && !is_bool($frag['uniqueSurveys'])) {
            $errors[] = '"surveys" must be true or false (unquoted).';
        }
        return array_merge($errors, self::checkCommon($frag, $opts));
    }

    /**
     * Shape of a @UVUNIQUE field-name list ("with", "also"): a non-empty list
     * of at most $max valid REDCap field names, none twice. Appends to
     * $errors and returns whether the list is sound.
     */
    private static function checkFieldNameList($list, $key, $max, array &$errors)
    {
        if (!is_array($list) || !$list) {
            $errors[] = '"' . $key . '" must be a non-empty list of field names.';
            return false;
        }
        if (count($list) > $max) {
            $errors[] = '"' . $key . '" is limited to ' . $max . ' fields.';
            return false;
        }
        $seen = [];
        foreach ($list as $w) {
            if (!is_string($w) || !preg_match('/^[a-z][a-z0-9_]*$/', strtolower($w))) {
                $errors[] = '"' . $key . '" entry ' . json_encode($w) . ' is not a valid REDCap field name.';
                return false;
            }
            $lw = strtolower($w);
            if (isset($seen[$lw])) { $errors[] = '"' . $key . '" lists "' . $lw . '" twice.'; return false; }
            $seen[$lw] = true;
        }
        return true;
    }

    /**
     * Semantic validation for a choices (@UVCHOICES) fragment: exactly one of
     * choicesShow/choicesHide, a non-empty deduplicated code list under the
     * cap, optional when/message/blockSave. Whether the codes exist in the
     * field's own choice list is checked in the channel glue with the data
     * dictionary in hand (getAnnotationRules), which also attaches choicesAll.
     */
    public static function checkChoices(array $frag, array $opts = [])
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
        return array_merge($errors, self::checkCommon($frag, $opts));
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
