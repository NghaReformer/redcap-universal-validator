<?php
namespace INSPIRE\UniversalValidator;
require_once __DIR__.'/Logic.php';

/**
 * One saved collection, sorted once, so that "any" and "all" against one value
 * cost a few binary searches instead of one comparison per member. Every host
 * context of a record asks about the same saved collection; comparing each of
 * n contexts with each of n members was quadratic (wargame P3).
 *
 * count() answers how many members satisfy "member OP value" exactly as
 * Logic::compare() would for each one: blank, number and text members are
 * kept apart, numbers in decCmp order and text in byte order, folded or not.
 * tests/collection_index_php.php checks it against the member-by-member loop.
 */
final class CollectionIndex
{
    const OPS = ['=','<>','<','<=','>','>='];
    private $values;
    /** Trimmed member => how many: equality for the "exact" text mode. */
    private $exact = [];
    private $blanks = 0;
    /** Number members, sorted by Logic::numCompare. */
    private $numbers = [];
    /** Text members, trimmed; sorted per case mode on first use. */
    private $text = [];
    private $sorted = [];

    public function __construct(array $values)
    {
        $this->values = array_values($values);
        foreach ($this->values as $value) {
            $t = trim((string)$value, " \t\r\n");
            $this->exact[$t] = ($this->exact[$t] ?? 0) + 1;
            if ($t === '') $this->blanks++;
            elseif (preg_match(Logic::NUM_RE, $t)) $this->numbers[] = $t;
            else $this->text[] = $t;
        }
        usort($this->numbers, [Logic::class, 'numCompare']);
    }

    public function size() { return count($this->values); }

    /** The member at $position, as the collection holds it. */
    public function value($position) { return $this->values[$position]; }

    /** Every member but the one at $skip (null: every member). */
    public function values($skip = null)
    {
        if ($skip === null) return $this->values;
        $out = $this->values;
        array_splice($out, (int)$skip, 1);
        return $out;
    }

    /**
     * How many members satisfy "member $op $x" with Logic::compare()'s blank
     * polarity and case mode, or null for an operator or value it does not
     * cover (the caller then compares member by member).
     */
    public function count($op, $x, $blank, $case)
    {
        if (!in_array($op, self::OPS, true) || !is_scalar($x)) return null;
        $t = trim((string)$x, " \t\r\n");
        $n = count($this->values);
        if ($case === Logic::EXACT && ($op === '=' || $op === '<>')) {
            $same = $this->exact[$t] ?? 0;
            return $op === '=' ? $same : $n - $same;
        }
        $kind = $t === '' ? 'blank' : (preg_match(Logic::NUM_RE, $t) ? 'number' : 'text');
        // A member and the value from different classes: = never holds, <> always does,
        // and an ordered comparison is the blank polarity against a blank, else false.
        $apart = function ($size, $either) use ($op, $blank) {
            if ($op === '=') return 0;
            if ($op === '<>') return $size;
            return $either && $blank ? $size : 0;
        };
        $count = 0;
        if ($this->blanks) {
            if ($op === '=' || $op === '<>') $count += ($op === '=') === ($kind === 'blank') ? $this->blanks : 0;
            else $count += $blank ? $this->blanks : 0;
        }
        if ($this->numbers) {
            $count += $kind === 'number'
                ? self::within($this->numbers, $t, $op, [Logic::class, 'numCompare'])
                : $apart(count($this->numbers), $kind === 'blank');
        }
        if ($this->text) {
            if ($kind === 'text') {
                $fold = !$case;
                $count += self::within($this->sortedText($fold), $fold ? Logic::foldAscii($t) : $t, $op, 'strcmp');
            } else $count += $apart(count($this->text), $kind === 'blank');
        }
        return $count;
    }

    private function sortedText($fold)
    {
        $key = $fold ? 'fold' : 'case';
        if (!isset($this->sorted[$key])) {
            $list = $fold ? array_map([Logic::class, 'foldAscii'], $this->text) : $this->text;
            sort($list, SORT_STRING);
            $this->sorted[$key] = $list;
        }
        return $this->sorted[$key];
    }

    /** How many of the sorted $list satisfy "item $op $x" under $cmp. */
    private static function within(array $list, $x, $op, callable $cmp)
    {
        $below = self::bound($list, $x, $cmp, false);
        $upTo = self::bound($list, $x, $cmp, true);
        $size = count($list);
        switch ($op) {
            case '<':  return $below;
            case '<=': return $upTo;
            case '>':  return $size - $upTo;
            case '>=': return $size - $below;
            case '=':  return $upTo - $below;
            default:   return $size - ($upTo - $below);
        }
    }

    /** The number of items below $x, or with $equal not above it. */
    private static function bound(array $list, $x, callable $cmp, $equal)
    {
        $lo = 0; $hi = count($list);
        while ($lo < $hi) {
            $mid = ($lo + $hi) >> 1;
            $c = $cmp($list[$mid], $x);
            if ($c < 0 || ($equal && $c === 0)) $lo = $mid + 1; else $hi = $mid;
        }
        return $lo;
    }
}
