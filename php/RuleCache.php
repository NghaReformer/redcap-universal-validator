<?php
/**
 * What a field's action tags parse to, kept from one request to the next
 * (FRM-4, VAL-3, AST-3).
 *
 * Every form view, survey page and save parses every tagged annotation of the
 * project again. For almost all of them that costs microseconds. A few
 * accepted annotations cost far more: a set of alternates whose patterns must
 * be proved not to overlap took 1.1 s, on every request, a public survey page
 * included. Those few are what this keeps.
 *
 * A STALE ENTRY CANNOT ENFORCE AN OLD RULE, because nothing here is keyed on
 * time or on the project:
 *
 *   - The key is a hash of everything the parse reads: the annotation text,
 *     the options it is parsed with, the build of the module (its folder name
 *     and the size and time of each file under php/), and what of the PHP
 *     installation a pattern check depends on. An edited annotation, an
 *     upgraded module or a changed setting is another key. The old entry is
 *     not found, and the next full build of the rules drops it.
 *   - Only AnnotationRules::parseAllTags() is cached, which reads nothing but
 *     its arguments and the module's own files. Everything that depends on the
 *     data dictionary, the project's events, saved values or the person
 *     looking is worked out on every request as before, from the parse.
 *   - A parse with an error in it is never stored: a refusal can come from a
 *     work budget, and that must be asked again.
 *   - An entry is stored only when decoding its JSON gives back the identical
 *     value, types and order included.
 *   - The stored text carries a keyed hash made with the module's secret
 *     (the system setting the audit log's hashes use). A person who can write
 *     project settings cannot write an entry the module will accept, so the
 *     cache cannot be used to plant a rule that skipped validation.
 *
 * The text lives in one project setting that no dialog shows. It is bounded:
 * a parse cheaper than MIN_MS is not stored, and at most MAX_ENTRIES entries
 * or MAX_BYTES of JSON are kept.
 */

namespace INSPIRE\UniversalValidator;

final class RuleCache
{
    /** Changes when the meaning of a stored entry does. */
    const SHAPE = 1;

    /** The project setting that holds the sealed text. */
    const SETTING = 'uv-rule-cache';

    /** A parse that took less than this many milliseconds is not stored. */
    const MIN_MS = 20.0;

    /** Most entries kept for one project. */
    const MAX_ENTRIES = 64;

    /** Most bytes of JSON kept for one project (the settings column holds 65,535). */
    const MAX_BYTES = 49152;

    /** How fragments are written: 1.0 stays a float, so that decoding gives back the identical value. */
    const JSON_FLAGS = JSON_PRESERVE_ZERO_FRACTION;

    /** The build signature of this process, worked out once. */
    private static $signature = null;

    /**
     * What identifies this build of the module and the parts of PHP a parse
     * depends on. Two requests with the same signature parse the same text to
     * the same value.
     */
    public static function signature()
    {
        if (self::$signature !== null) return self::$signature;
        $parts = ['shape=' . self::SHAPE, 'folder=' . basename(dirname(__DIR__)), 'php=' . PHP_VERSION,
            'pcre=' . (defined('PCRE_VERSION') ? PCRE_VERSION : ''),
            'int=' . PHP_INT_SIZE];
        foreach (['pcre.backtrack_limit', 'pcre.recursion_limit', 'pcre.jit', 'precision', 'serialize_precision'] as $ini) {
            $parts[] = $ini . '=' . (string) ini_get($ini);
        }
        foreach (['mbstring', 'ctype', 'intl', 'bcmath', 'gmp'] as $ext) {
            $parts[] = $ext . '=' . (extension_loaded($ext) ? '1' : '0');
        }
        $files = array_merge(glob(__DIR__ . '/*.php') ?: [], glob(__DIR__ . '/*.json') ?: [],
            glob(__DIR__ . '/Scan/*.php') ?: [], glob(__DIR__ . '/messages/*.json') ?: []);
        sort($files);
        foreach ($files as $f) {
            $parts[] = basename(dirname($f)) . '/' . basename($f) . ':' . (string) @filesize($f) . ':' . (string) @filemtime($f);
        }
        return self::$signature = hash('sha256', implode("\n", $parts));
    }

    /** The key of one annotation parsed with one set of options under one build. */
    public static function key($signature, array $options, $annotation)
    {
        ksort($options);
        return hash('sha256', (string) $signature . "\n" . (string) json_encode($options) . "\n" . (string) $annotation);
    }

    /**
     * Whether a parse may be stored: a list of fragments with no error in it,
     * that JSON gives back identical.
     */
    public static function storable($frags)
    {
        if (!is_array($frags) || !$frags) return false;
        foreach ($frags as $frag) {
            if (!is_array($frag) || array_key_exists('error', $frag) || array_key_exists('configError', $frag)) return false;
        }
        $json = json_encode($frags, self::JSON_FLAGS);
        if ($json === false) return false;
        return json_decode($json, true) === $frags;
    }

    /**
     * The sealed text for $entries (key => fragments), or null when there is
     * no secret to seal with or the entries cannot be encoded. Entries past
     * MAX_ENTRIES or MAX_BYTES are left out, the first ones kept.
     */
    public static function seal(array $entries, $secret, $pid)
    {
        if (!is_string($secret) || $secret === '') return null;
        $kept = [];
        $bytes = 0;
        foreach ($entries as $key => $frags) {
            if (count($kept) >= self::MAX_ENTRIES) break;
            if (!is_string($key) || preg_match('/^[0-9a-f]{64}$/D', $key) !== 1 || !self::storable($frags)) continue;
            $size = strlen((string) json_encode($frags, self::JSON_FLAGS)) + 70;
            if ($bytes + $size > self::MAX_BYTES) continue;
            $bytes += $size;
            $kept[$key] = $frags;
        }
        $body = json_encode($kept, self::JSON_FLAGS);
        if ($body === false) return null;
        return json_encode(['v' => self::SHAPE, 'body' => $body, 'mac' => self::mac($body, $secret, $pid)]);
    }

    /**
     * The entries of a sealed text, or [] when it is absent, damaged, of
     * another shape, or not sealed with this secret for this project.
     */
    public static function open($stored, $secret, $pid)
    {
        if (!is_string($stored) || $stored === '' || !is_string($secret) || $secret === '') return [];
        $box = json_decode($stored, true);
        if (!is_array($box) || !isset($box['v'], $box['body'], $box['mac']) || $box['v'] !== self::SHAPE
                || !is_string($box['body']) || !is_string($box['mac'])) return [];
        if (!hash_equals(self::mac($box['body'], $secret, $pid), $box['mac'])) return [];
        $entries = json_decode($box['body'], true);
        if (!is_array($entries)) return [];
        $out = [];
        foreach ($entries as $key => $frags) {
            if (is_string($key) && preg_match('/^[0-9a-f]{64}$/D', $key) === 1 && self::storable($frags)) $out[$key] = $frags;
        }
        return $out;
    }

    /** The keyed hash of a body, bound to the project it was stored for. */
    private static function mac($body, $secret, $pid)
    {
        return hash_hmac('sha256', 'uv-rule-cache|' . self::SHAPE . '|' . (string) (int) $pid . '|' . $body, $secret);
    }
}
