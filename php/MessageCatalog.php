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
 * js/engine.js does not read the file yet: it carries its own sentences, and
 * most of them differ from the catalogue's. tests/messages_fixture.json holds
 * both sides for every key, and the two tests that read it fail when either
 * side changes without it.
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
        // THE NUMBER A SENTENCE NAMES IS THE ONE THE RULE COLUMN SHOWS. A stored
        // finding names its rule by source id, and ScanDimensions::ruleOf()
        // may settle on another ordinal than the one the row carries; the
        // sentence quoted the row's, so one row named two rules. A rule shape
        // that carries a resolved `ordinal` wins. Below 1 there is no rule,
        // and the sentence says '?' - the word template() already uses for a
        // finding with no number - never "Rule 0". A rule shape without
        // `ordinal` (the legacy sink's) keeps the finding's own number.
        if (array_key_exists('ordinal', $rule)) {
            $ord = (int) $rule['ordinal'];
            $finding['rule'] = $ord > 0 ? $ord : '?';
        }
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

        // A reason with wording of its own (scan.reasonMessages of the mode,
        // which ScanDimensions copies into the snapshot as 'messages') speaks
        // with that text, or else the catalog's, never the rule's "message":
        // an @UVEXISTS value saved only in entries that fail "where" is
        // explained by "whereMessage", as the page explains it.
        $msg = (isset($rule['messages']) && is_array($rule['messages']) && array_key_exists($code, $rule['messages']))
            ? trim((string) $rule['messages'][$code])
            : (isset($rule['message']) ? trim((string) $rule['message']) : '');

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

    /**
     * The second sentence, when the rule carries detail worth showing. Staff only.
     *
     * THREE KINDS OF DETAIL.
     *
     * An assertion. The engine emits `assert:<expression>`; a STORED finding
     * holds the code `assert` alone, because the expression belongs to the rule
     * and Scan\ReasonCode strips it before it becomes a column. Both forms get
     * the expression from the rule snapshot. The bare form used to get nothing,
     * so a stored finding said "does not satisfy this rule's condition" and
     * never said what the condition was.
     *
     * A pooled field with several problems. The engine joins them with `;`
     * (`check-character;junk`) - there is no `pooled:` prefix, whatever the
     * comment on ReasonCode once said - so no single catalogue key matches and
     * explain() answers with the `pooled/*` line: "one or more IDs are not
     * valid". That is true and tells nobody what to fix. Each part has wording
     * of its own, and they are given here, one sentence per part. A part with
     * no wording is NAMED rather than dropped: an unknown problem is still a
     * problem.
     *
     * A detail template. Besides the assert expression, a catalog entry may
     * carry a "detail" template filled from the rule snapshot's 'detail' map
     * (the keys its mode lists under scan.detailKeys in php/modes.json). A
     * template with any
     * placeholder the rule does not set is left out whole: half a sentence
     * about a window bound that does not exist would be wrong, not short.
     * "detail" may also be a list of templates, for rules of one kind that set
     * different keys: the first one the rule fills in whole is used.
     *
     * A finding judged against the day its value was saved (a today-relative
     * @UVWINDOW part) carries that day as 'asOf'; an "asOf" template on the
     * entry, or on its type's wildcard entry, adds a sentence naming it. A
     * growth-reference finding carries its z-score as 'z' and its reference
     * as 'reference' ('z_score' and 'growth_reference' as the durable store
     * reads them back; else the rule's "rangeReference"); a "zScore" template
     * adds a sentence with both.
     */
    public static function detail(array $finding, array $rule)
    {
        $reason = isset($finding['reason']) ? (string) $finding['reason'] : '';
        if ($reason === 'assert' || strpos($reason, 'assert:') === 0) {
            $expr = (isset($rule['assert']) && $rule['assert'] !== '')
                  ? (string) $rule['assert'] : (string) substr($reason, 7);
            return $expr === '' ? '' : 'The rule requires: ' . $expr;
        }
        if (strpos($reason, ';') !== false) return self::pooledDetail($reason, $finding);

        $type = isset($finding['type']) ? (string) $finding['type'] : '';
        $code = $reason;
        $colon = strpos($reason, ':');
        if ($colon !== false) $code = substr($reason, 0, $colon);
        $vals = (isset($rule['detail']) && is_array($rule['detail'])) ? $rule['detail'] : [];
        $cat = self::catalog();
        $text = '';
        foreach ([$type . '/' . $code, $type . '/*'] as $ck) {
            if (!isset($cat[$ck]['detail'])) continue;
            $templates = is_array($cat[$ck]['detail']) ? $cat[$ck]['detail'] : [$cat[$ck]['detail']];
            foreach ($templates as $t) {
                if (!is_string($t) || $t === '') continue;
                $filled = self::fillAll($t, $vals);
                if ($filled !== null) { $text = $filled; break; }
            }
            break;
        }
        $asOf = isset($finding['asOf']) && is_scalar($finding['asOf']) ? (string) $finding['asOf'] : '';
        // A growth finding's z-score ('z_score' as the durable store reads it
        // back); its reference is the finding's, else the rule's. A rule with
        // one branch per reference has no one reference of its own.
        $z = isset($finding['z']) && is_scalar($finding['z']) ? (string) $finding['z']
           : (isset($finding['z_score']) && is_scalar($finding['z_score']) ? (string) $finding['z_score'] : '');
        $ref = isset($finding['reference']) && is_scalar($finding['reference']) ? (string) $finding['reference']
             : (isset($finding['growth_reference']) && is_scalar($finding['growth_reference']) ? (string) $finding['growth_reference']
             : (isset($rule['rangeReference']) && is_string($rule['rangeReference']) ? $rule['rangeReference'] : ''));
        $notes = [];
        if ($asOf !== '') $notes['asOf'] = ['asOf' => $asOf];
        if ($z !== '' && $ref !== '') $notes['zScore'] = ['z' => $z, 'reference' => $ref];
        foreach ($notes as $key => $fill) {
            foreach ([$type . '/' . $code, $type . '/*'] as $ck) {
                if (!isset($cat[$ck][$key]) || !is_string($cat[$ck][$key])) continue;
                $note = self::fillAll($cat[$ck][$key], $fill);
                if ($note !== null) $text = $text === '' ? $note : $text . ' ' . $note;
                break;
            }
        }
        return $text;
    }

    /** $text with every {placeholder} filled from $vals, or null when any of them has no value. */
    private static function fillAll($text, array $vals)
    {
        $missing = false;
        $out = preg_replace_callback('/\{(\w+)\}/', function ($m) use ($vals, &$missing) {
            if (!isset($vals[$m[1]]) || !is_scalar($vals[$m[1]]) || (string) $vals[$m[1]] === '') {
                $missing = true;
                return '';
            }
            return (string) $vals[$m[1]];
        }, $text);
        return $missing ? null : $out;
    }

    /** One sentence per part of a `;`-joined pooled reason; parts with no wording are named. */
    private static function pooledDetail($reason, array $finding)
    {
        $type = isset($finding['type']) ? (string) $finding['type'] : '';
        $cat = self::catalog();
        $said = [];
        $unnamed = [];
        foreach (explode(';', $reason) as $part) {
            $part = trim($part);
            if ($part === '') continue;
            $k = $type . '/' . $part;
            if (isset($cat[$k]['staff']) && is_string($cat[$k]['staff']) && $cat[$k]['staff'] !== '') {
                $said[$k] = self::fill($cat[$k]['staff'], $finding);
            } else {
                $unnamed[$part] = $part;
            }
        }
        $out = implode(' ', $said);
        if ($unnamed) {
            $out .= ($out === '' ? '' : ' ') . 'Also reported: ' . implode(', ', $unnamed) . '.';
        }
        return $out;
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
