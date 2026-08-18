<?php

namespace INSPIRE\UniversalValidator\Scan;

/**
 * A reason, bounded so it can be a column.
 *
 * WHY THIS EXISTS AT ALL. The engine emits reasons as free text, and two of them
 * carry the rule inside the reason: `assert:<expression>` embeds the whole
 * assertion, up to `Logic::MAX_EXPR_LEN` of it, in EVERY finding that rule
 * produces. That is a property of the rule, not of the finding, and storing it
 * per finding is up to a gigabyte of the same sentence repeated - which also
 * destroys the two things the column is for: an index, and a `GROUP BY reason`
 * that produces a summary rather than one bucket per rule.
 *
 * SO THE CODE IS THE KIND, AND THE DETAIL LIVES WHERE IT BELONGS. `assert:x > 3`
 * becomes the code `assert`; the expression is stored once against the rule.
 * `pooled:a;b;c` becomes the code `pooled` plus a BITMASK over a closed
 * five-element set, because that set is fixed by php/CheckCharacter.php and a
 * string of joined problems is five booleans wearing a comma.
 *
 * ONE FUNCTION AND ONE `if`, DELIBERATELY. An earlier draft of the plan proposed
 * a registry of codecs with a resolution chain; there is exactly one reason
 * shape that needs special handling, and the review's C6 was right that a
 * registry for one override is a mechanism with nothing to dispatch.
 *
 * UNKNOWN REASONS PASS THROUGH, TRUNCATED, NEVER DROPPED. A future rule type
 * emitting a reason nobody here has seen is stored, counted, filtered and
 * rolled up under its own code with truthful generic wording. That is the
 * property the report layer depends on: an unknown kind must degrade to generic
 * wording, never to a silent hole.
 */
final class ReasonCode
{
    /** The column is VARCHAR(64). Codes are cut to fit rather than rejected. */
    const MAX = 64;

    /**
     * The closed set behind `pooled:`, in bit order.
     *
     * From php/CheckCharacter.php's pooled reporting. Order is the wire format:
     * appending is safe, reordering rewrites the meaning of every stored row.
     */
    private static $pooled = ['length', 'charset', 'checkdigit', 'prefix', 'duplicate'];

    /**
     * Split a raw engine reason into what a column can hold.
     *
     * @return array{code:string, bits:int, detail:?string}
     */
    public static function split($reason)
    {
        $reason = (string) $reason;
        if ($reason === '') {
            return ['code' => 'unspecified', 'bits' => 0, 'detail' => null];
        }
        $colon = strpos($reason, ':');
        if ($colon === false) {
            return ['code' => self::fit($reason), 'bits' => 0, 'detail' => null];
        }
        $head = substr($reason, 0, $colon);
        $tail = substr($reason, $colon + 1);

        if ($head === 'pooled') {
            // Five booleans wearing a comma. A mask indexes and groups; a joined
            // string does neither, and the set it draws from cannot grow without
            // a code change anyway.
            $bits = 0;
            foreach (explode(';', $tail) as $part) {
                $part = trim($part);
                $i = array_search($part, self::$pooled, true);
                if ($i !== false) $bits |= (1 << $i);
            }
            return ['code' => 'pooled', 'bits' => $bits, 'detail' => null];
        }

        // Everything else keeps its kind and hands the detail back to the
        // caller, which stores it against the RULE. The finding keeps the code.
        return ['code' => self::fit($head), 'bits' => 0, 'detail' => $tail];
    }

    /** Just the code. The common call, and the one the finding row wants. */
    public static function code($reason)
    {
        $r = self::split($reason);
        return $r['code'];
    }

    /** Which pooled problems a stored mask names. For the report, later. */
    public static function pooledNames($bits)
    {
        $out = [];
        foreach (self::$pooled as $i => $name) {
            if (((int) $bits) & (1 << $i)) $out[] = $name;
        }
        return $out;
    }

    /**
     * Cut to the column, and say so in the value.
     *
     * A code silently truncated to something that collides with a different code
     * would merge two kinds of problem in every count. The marker makes an
     * over-long code visibly odd instead of quietly wrong.
     */
    private static function fit($code)
    {
        $code = trim($code);
        if ($code === '') return 'unspecified';
        if (strlen($code) <= self::MAX) return $code;
        return substr($code, 0, self::MAX - 1) . '~';
    }
}
