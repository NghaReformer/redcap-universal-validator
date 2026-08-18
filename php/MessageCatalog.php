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
        $t = self::template($finding, $rule, $audience);
        // An AUTHORED message is returned verbatim, exactly as before the
        // template split: {braces} an author typed are their own text, and
        // silently emptying them because they happen to look like a placeholder
        // would rewrite wording only they can write.
        $text = ($t['source'] === 'rule-message') ? $t['text'] : self::fill($t['text'], $finding);
        return ['text' => $text, 'source' => $t['source']];
    }

    /**
     * The resolution itself, without the per-finding substitution.
     *
     * Split out so it can be memoised. Two report columns - 'What is wrong' and
     * 'Wording from' - are two views of ONE resolution, so every exported row
     * walked the wildcard chain twice for the same answer.
     *
     * The memo is on the TEMPLATE, never on the finished sentence, and that is
     * the whole point of the split. A cache keyed on a hand-picked subset of the
     * finding would serve a stale sentence the day someone adds {value} or
     * {record} to catalog.json - a data file, edited without touching this
     * class, silently producing the previous row's value on this row. The
     * template depends only on the things keyed below; fill() still runs fresh
     * for every finding.
     *
     * ONE slot, not a map: the two calls are back to back, so a single entry
     * hits every time, and a keyed cache over a 100k-row export would be a
     * second copy of the report held for the life of the request.
     */
    private static function template(array $finding, array $rule, $audience)
    {
        $type   = isset($finding['type']) ? (string) $finding['type'] : '';
        $reason = isset($finding['reason']) ? (string) $finding['reason'] : '';

        // 'assert:<the whole expression>' is a REASON carrying an argument. The
        // expression belongs to the rule, not to the finding - every finding of
        // that rule repeats it - so it is split off here and shown once, from
        // the rule snapshot.
        $code = $reason;
        $colon = strpos($reason, ':');
        if ($colon !== false) $code = substr($reason, 0, $colon);

        $msg = isset($rule['message']) ? trim((string) $rule['message']) : '';

        // The key carries the FULL reason and the rule ordinal, not just the
        // code, because the last-resort branch below quotes both verbatim. Key
        // on less than the template reads and the memo starts answering one
        // finding with another finding's sentence.
        static $lastKey = null, $lastVal = null;
        $rid = isset($finding['rule']) ? (string) $finding['rule'] : '?';
        $key = $audience . "\0" . $type . "\0" . $reason . "\0" . $rid . "\0" . $msg;
        if ($key === $lastKey) return $lastVal;

        $out = null;
        // 1. the author's wording always wins.
        if ($msg !== '') {
            $out = ['text' => $msg, 'source' => 'rule-message'];
        } else {
            $cat = self::catalog();
            foreach ([$type . '/' . $code, $type . '/*', '*/*'] as $ck) {
                if (!isset($cat[$ck])) continue;
                $entry = $cat[$ck];
                $text = isset($entry[$audience]) ? $entry[$audience]
                      : (isset($entry['staff']) ? $entry['staff'] : null);
                if (!is_string($text) || $text === '') continue;
                $out = ['text' => $text, 'source' => ($ck === '*/*') ? 'fallback' : 'catalog'];
                break;
            }
        }
        // Only reachable if the catalog file itself is missing or unreadable.
        // Still a sentence, and still one that names the rule and the raw
        // reason: a blank explanation is the silent hole this class exists to
        // refuse.
        if ($out === null) {
            $out = ['text' => 'Rule ' . $rid . ' reported "' . $reason . '" for this value.',
                    'source' => 'fallback'];
        }
        $lastKey = $key;
        $lastVal = $out;
        return $out;
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
