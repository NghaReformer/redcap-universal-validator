<?php

namespace INSPIRE\UniversalValidator;

/**
 * Turns a finding into one plain sentence.
 *
 * The wording lives in php/messages/catalog.json, as data, because two runtimes
 * need the same words — the browser tells the person filling in the form, and
 * the scan report tells whoever cleans the data afterwards. Re-implementing the
 * browser's strings in PHP would be drift by construction.
 *
 * Resolution ALWAYS terminates and never returns nothing:
 *
 *   1. the rule author's own message   — only they can word what a rule means
 *   2. catalog[type/reason]
 *   3. catalog[type/*]
 *   4. catalog[*&#47;*]                — names the rule and the raw reason
 *
 * A blank explanation on an unrecognised finding would be the silent hole this
 * module rejects everywhere else, so the last tier is not allowed to be empty:
 * it says outright that no wording is configured. That is also what makes a new
 * rule type safe to add — it is stored, counted, exported and truthfully
 * described from the day it exists, and a catalog entry later only improves the
 * sentence.
 *
 * Which tier answered is reported alongside, so a designer can see at a glance
 * which of their rules still lack authored wording.
 */
final class MessageCatalog
{
    /** @var array|null */
    private static $catalog = null;

    /** The parsed catalog, read once per request. */
    public static function catalog()
    {
        if (self::$catalog !== null) return self::$catalog;
        self::$catalog = [];
        try {
            $raw = @file_get_contents(__DIR__ . '/messages/catalog.json');
            if (is_string($raw) && $raw !== '') {
                $j = json_decode($raw, true);
                if (is_array($j)) {
                    foreach ($j as $k => $v) {
                        if ($k === '_comment' || !is_array($v)) continue;
                        self::$catalog[$k] = $v;
                    }
                }
            }
        } catch (\Throwable $e) {
        }
        return self::$catalog;
    }

    /**
     * @param array  $finding  a scan finding: type, reason, rule, field, value
     * @param array  $rule     the rule snapshot from ScanDimensions::rule()
     * @param string $audience 'staff' | 'survey'
     * @return array ['text' => string, 'source' => 'rule-message'|'catalog'|'fallback']
     */
    public static function explain(array $finding, array $rule, $audience = 'staff')
    {
        $type   = isset($finding['type']) ? (string) $finding['type'] : '';
        $reason = isset($finding['reason']) ? (string) $finding['reason'] : '';

        // 'assert:<the whole expression>' is a REASON carrying an argument. The
        // expression belongs to the rule, not to the finding — every finding of
        // that rule repeats it — so it is split off here and shown once, from
        // the rule snapshot.
        $code = $reason;
        $colon = strpos($reason, ':');
        if ($colon !== false) $code = substr($reason, 0, $colon);

        // 1. the author's wording always wins.
        if (isset($rule['message']) && trim((string) $rule['message']) !== '') {
            return ['text' => trim((string) $rule['message']), 'source' => 'rule-message'];
        }

        $cat = self::catalog();
        foreach ([$type . '/' . $code, $type . '/*', '*/*'] as $key) {
            if (!isset($cat[$key])) continue;
            $entry = $cat[$key];
            $text = isset($entry[$audience]) ? $entry[$audience]
                  : (isset($entry['staff']) ? $entry['staff'] : null);
            if (!is_string($text) || $text === '') continue;
            return [
                'text'   => self::fill($text, $finding),
                'source' => ($key === '*/*') ? 'fallback' : 'catalog',
            ];
        }
        // Only reachable if the catalog file itself is missing or unreadable.
        return [
            'text'   => 'Rule ' . (isset($finding['rule']) ? $finding['rule'] : '?')
                      . ' reported "' . $reason . '" for this value.',
            'source' => 'fallback',
        ];
    }

    /** The second sentence, when the rule carries detail worth showing. Staff only. */
    public static function detail(array $finding, array $rule)
    {
        $reason = isset($finding['reason']) ? (string) $finding['reason'] : '';
        if (strpos($reason, 'assert:') === 0) {
            $expr = isset($rule['assert']) && $rule['assert'] !== ''
                  ? $rule['assert'] : substr($reason, 7);
            return 'The rule requires: ' . $expr;
        }
        return '';
    }

    /** Substitute {placeholders}; anything with no value becomes empty, never raw. */
    private static function fill($text, array $f)
    {
        return preg_replace_callback('/\{(\w+)\}/', function ($m) use ($f) {
            $k = $m[1];
            if (!isset($f[$k]) || $f[$k] === null) return '';
            return is_scalar($f[$k]) ? (string) $f[$k] : '';
        }, $text);
    }
}
