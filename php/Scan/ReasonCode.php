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
 * `pooled:a;b;c` becomes the code `pooled` plus a BITMASK over a closed set,
 * because that set is fixed by php/CheckCharacter.php and a string of joined
 * problems is a row of booleans wearing a comma. NOTHING EMITS THAT PREFIX: see
 * $pooled below for what the engine sends instead, and what that means for a
 * reader of reason_bits.
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
     * What the column holds for a finding the engine gave no reason. A STORAGE
     * value: the column is NOT NULL and an empty code would group with nothing.
     * Scan\FindingRowMapper turns it back into no reason on the way out.
     */
    const UNSPECIFIED = 'unspecified';

    /**
     * The closed set behind a pooled reason's bitmask, in bit order.
     *
     * Order is the wire format: appending is safe, reordering rewrites the
     * meaning of every stored row.
     *
     * THE FIRST FIVE WERE WRITTEN FROM A DESCRIPTION, NOT FROM THE ENGINE, and
     * four of them are names CheckCharacter::validatePooledField() has never
     * emitted. What it emits is `no-id`, `check-character`, `junk`, `duplicate`
     * and `count`, joined with `;` and with no `pooled:` prefix - so split()
     * never produced a non-zero mask, and every row stored before wave 11 has
     * reason_bits = 0 with the joined names in reason_code. The real vocabulary
     * is APPENDED rather than substituted, because bit positions are the wire
     * format and an append cannot change the meaning of a row. split() now
     * builds the mask from the joined form (W7-R1) when it is told the finding
     * is a pooled one; a reader must still take a mask of 0 as "not recorded",
     * never as "no problems", because older rows say nothing else.
     */
    private static $pooled = ['length', 'charset', 'checkdigit', 'prefix', 'duplicate',
                              'no-id', 'check-character', 'junk', 'count'];

    /**
     * The order CheckCharacter::validatePooledField() emits its problems in.
     * A mask has no order, and the bit order is not this one (`duplicate` is
     * bit 4, before `no-id`), so a mask read back in bit order told the
     * wording layer the problems in another order than the form and the
     * legacy report give them. pooledReason() answers in this order.
     */
    const POOLED_ORDER = ['no-id', 'check-character', 'junk', 'duplicate', 'count'];

    /**
     * Under the code `pooled`, reason_bits holds the mask below this bit and
     * the judging branch above it (see bits()). Every other code has no mask,
     * and its reason_bits is the branch alone.
     */
    const BRANCH_SHIFT = 24;

    /** The most branch + 1 that fits above the mask. A branch past it is stored as none. */
    const BRANCH_MAX = 255;

    /**
     * Split a raw engine reason into what a column can hold.
     *
     * `identity` is the code the finding's identity is computed from, which is
     * what `code` was before the mask had a producer. Splitting once and
     * keeping both is what lets reason_code group a pooled field's problems
     * under one code while no finding's identity moves (W7-R1).
     *
     * @param ?string $type the finding's check type. Only a `pooled` finding's
     *                      joined reason becomes a mask: `check-character` on
     *                      its own is also what a single-value check says.
     * @return array{code:string, bits:int, detail:?string, identity:string}
     */
    public static function split($reason, $type = null)
    {
        $reason = (string) $reason;
        if ($reason === '') {
            return ['code' => self::UNSPECIFIED, 'bits' => 0, 'detail' => null, 'identity' => self::UNSPECIFIED];
        }
        $colon = strpos($reason, ':');
        if ($colon === false) {
            $code = self::fit($reason);
            if ($type === 'pooled') {
                // The engine's joined form. Every part must be a name the mask
                // can hold, or the joined code stands: a part dropped on the
                // way into a mask is a problem nobody is told about.
                $bits = 0;
                foreach (explode(';', $reason) as $part) {
                    $i = array_search(trim($part), self::$pooled, true);
                    if ($i === false || $i >= self::BRANCH_SHIFT) { $bits = 0; break; }
                    $bits |= (1 << $i);
                }
                if ($bits > 0) {
                    return ['code' => 'pooled', 'bits' => $bits, 'detail' => null, 'identity' => $code];
                }
            }
            return ['code' => $code, 'bits' => 0, 'detail' => null, 'identity' => $code];
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
            return ['code' => 'pooled', 'bits' => $bits, 'detail' => null, 'identity' => 'pooled'];
        }

        // Everything else keeps its kind and hands the detail back to the
        // caller, which stores it against the RULE. The finding keeps the code.
        $code = self::fit($head);
        return ['code' => $code, 'bits' => 0, 'detail' => $tail, 'identity' => $code];
    }

    /**
     * What reason_bits holds for one split reason and the branch that judged
     * it (R3G-2).
     *
     * A durable finding did not carry its branch, so the report could not
     * speak for it: a branched @UVRANGE or @UVWINDOW finding in a stored run
     * lost its branch's limits and custom message, an @UVASSERT branch's "The
     * rule requires" went empty, and the stored and legacy reports disagreed.
     * There is no column for it and the plan allows no DDL, but reason_bits is
     * read as a mask only under the code `pooled`, so everywhere else it is
     * free: it holds branch + 1, and 0 is "no branch". Under `pooled` the
     * branch + 1 goes above the mask, at BRANCH_SHIFT; the mask's names fit
     * well below it. The identity does not read reason_bits, so no finding is
     * renamed. A branch index that is not a whole number from 0 to
     * BRANCH_MAX - 1 is stored as none: the rule's own wording is then shown,
     * as it was before.
     *
     * @param mixed $branch the finding's `branch`, or null
     */
    public static function bits(array $split, $branch = null)
    {
        $mask = isset($split['bits']) ? max(0, (int) $split['bits']) : 0;
        $b = 0;
        if (is_int($branch) || (is_string($branch) && ctype_digit($branch))) {
            $b = (int) $branch + 1;
            if ($b > self::BRANCH_MAX) $b = 0;
        }
        if (isset($split['code']) && $split['code'] === 'pooled') {
            return $mask | ($b << self::BRANCH_SHIFT);
        }
        return $b;
    }

    /** The branch a stored row's reason_bits names, or null for none. See bits(). */
    public static function branchOf($code, $bits)
    {
        $bits = max(0, (int) $bits);
        $b = ((string) $code === 'pooled') ? ($bits >> self::BRANCH_SHIFT) : $bits;
        return ($b >= 1 && $b <= self::BRANCH_MAX) ? $b - 1 : null;
    }

    /** The mask a stored row's reason_bits carries: only under the code `pooled`. */
    public static function maskOf($code, $bits)
    {
        if ((string) $code !== 'pooled') return 0;
        return ((int) $bits) & ((1 << self::BRANCH_SHIFT) - 1);
    }

    /**
     * A stored mask as the engine's own reason: its names joined with `;`, in
     * the order the engine emits them (POOLED_ORDER), then any name the engine
     * no longer emits in bit order, then each unnamed bit as `bit-N`. '' for
     * an empty mask.
     */
    public static function pooledReason($mask)
    {
        $names = self::pooledNames((int) $mask);
        $out = [];
        foreach (self::POOLED_ORDER as $n) if (in_array($n, $names, true)) $out[] = $n;
        foreach ($names as $n) if (!in_array($n, $out, true)) $out[] = $n;
        return implode(';', $out);
    }

    /** Just the code. The common call, and the one the finding row wants. */
    public static function code($reason)
    {
        $r = self::split($reason);
        return $r['code'];
    }

    /**
     * Which pooled problems a stored mask names. For the report, later.
     *
     * A BIT WITH NO NAME IS NAMED BY ITS POSITION, as `bit-N`. It was dropped,
     * so a mask written by a later release read as fewer problems than it
     * held; MessageCatalog::detail() names a part it has no sentence for, and
     * now has a part to name.
     */
    public static function pooledNames($bits)
    {
        $bits = (int) $bits;
        $out = [];
        foreach (self::$pooled as $i => $name) {
            if ($bits & (1 << $i)) $out[] = $name;
        }
        for ($i = count(self::$pooled); $i < PHP_INT_SIZE * 8; $i++) {
            if ($bits & (1 << $i)) $out[] = 'bit-' . $i;
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
        if ($code === '') return self::UNSPECIFIED;
        if (strlen($code) <= self::MAX) return $code;
        return substr($code, 0, self::MAX - 1) . '~';
    }
}
