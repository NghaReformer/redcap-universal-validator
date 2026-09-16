<?php

namespace INSPIRE\UniversalValidator\Scan;

/**
 * What the database said, with the participant data taken out and the
 * IDENTIFIERS left in.
 *
 * WHY THIS IS A CLASS AND NOT A PRIVATE METHOD. Three separate places need the
 * same answer and have historically invented three different ones: the batch
 * commit (which is where the pilot died), startRun() (which reported every
 * insert failure as "a scan is already running for this project"), and the
 * lease predicates (which reported a deadlock as "another worker took over").
 * A redaction rule that lives in one class's private section is a redaction
 * rule the next author re-implements, and the re-implementation is where the
 * disclosure or the lost diagnosis comes from.
 *
 * MYSQL DOES NOT BACKTICK IDENTIFIERS IN ERROR MESSAGES. It single-quotes them,
 * which are the same quotes it puts round the offending VALUE, and two previous
 * attempts at this assumed otherwise. Measured against MySQL 8.0.46, a blanket
 * "redact every single-quoted run" erases the diagnosis from every shape the
 * server produces:
 *
 *     Duplicate entry 'AB12-9' for key 'uv_finding.uq_active_identity'
 *     Data too long for column 'reason_code' at row 1
 *     Column 'host_form' cannot be null
 *     Field 'run_id' doesn't have a default value
 *     Table 'redcap.uv_finding' doesn't exist
 *
 * Every one loses the only word worth reading. 1.9.9 promised to name the
 * column and never once did, and the test that was supposed to guard it was an
 * `||` that passed on its other half.
 *
 * REBUILT, NOT FILTERED, and that is the security property. On a recognised
 * shape this returns a TEMPLATE assembled from structural captures only, so the
 * failing statement and its bound parameters - which the External Modules
 * wrapper appends to the message, and which are the largest disclosure risk in
 * the whole string - are dropped rather than trimmed. Filtering keeps whatever
 * it failed to recognise; rebuilding keeps only what it did.
 *
 * PHP 7.4: no arrow functions with statements, no match, no enums.
 */
final class DbError
{
    /** Two windows of this size are searched: the front and the back. */
    const WINDOW = 2000;

    /** The fall-through answer is bounded; an error is not a place for a paragraph. */
    const CAP = 200;

    /**
     * A message safe to put on a page, and useful enough to act on.
     *
     * @return string never empty, never null
     */
    public static function safe(\Throwable $e)
    {
        $raw = (string) $e->getMessage();
        // THE ERRNO SURVIVES EVEN WHEN THE TEXT DOES NOT. 1062, 1406, 1048,
        // 1054, 1364, 1146, 1213 and 1205 each name a different fix, and a
        // number cannot carry a participant's data.
        $code = (int) $e->getCode();
        $tag  = ($code > 0) ? '[' . $code . '] ' : '';

        if ($raw === '') return $tag === '' ? get_class($e) : $tag . get_class($e);

        // CUT FIRST, THEN MATCH. The framework puts the failing STATEMENT in the
        // message, and a findings batch is one multi-row INSERT holding
        // thousands of placeholders, so what arrives here can be megabytes.
        // Running a backtracking pattern over all of it is how 1.9.9 turned a
        // reported error into an empty 200: PCRE gives up, preg_replace returns
        // null, and the null travels into the next string call inside a catch
        // that is already handling a failure.
        //
        // BOTH ENDS, not just the front. The head-only cut assumed the server's
        // own text leads; a wrapper that puts the statement first pushes the
        // diagnosis past 2,000 characters and the old code then found nothing
        // at all. Searching the tail as well costs one more pass over a bounded
        // string and is the difference between a diagnosis and a shrug.
        $windows = [substr($raw, 0, self::WINDOW)];
        if (strlen($raw) > self::WINDOW) $windows[] = substr($raw, -self::WINDOW);

        foreach ($windows as $w) {
            $hit = self::template($w, $tag);
            if ($hit !== null) return $hit;
        }

        // UNRECOGNISED. Fall back to what shipped before: blanket redaction of
        // quoted runs, whitespace collapsed, bounded. Less useful, never less
        // safe - and it still names the errno.
        $m = $windows[0];
        $r = preg_replace("/'[^']*'/", "'...'", $m);
        if (is_string($r)) $m = $r;              // null = PCRE gave up; keep the cut original
        $r = preg_replace('/\s+/', ' ', $m);
        if (is_string($r)) $m = $r;
        $m = $tag . $m;
        if (strlen($m) > self::CAP) $m = substr($m, 0, self::CAP - 3) . '...';
        return $m;
    }

    /**
     * One window against the known shapes.
     *
     * Unanchored on purpose, so a shape is still found inside a wrapper's
     * prose. Order matters only where one pattern could match inside another;
     * the value-bearing shapes are listed before their value-free cousins.
     *
     * @return ?string null when nothing matched
     */
    private static function template($w, $tag)
    {
        $m = [];

        // THE TWO SHAPES THAT CARRY A VALUE. Both are withheld by construction:
        // the capture group for the value is not even referenced.
        if (preg_match("/Duplicate entry '.*?' for key '([^']*)'/s", $w, $m)) {
            return $tag . 'Duplicate entry (value withheld) for key ' . self::ident($m[1]);
        }
        if (preg_match("/Incorrect ([a-z ]+) value: '.*?' for column '([^']*)' at row (\\d+)/s", $w, $m)) {
            return $tag . 'Incorrect ' . self::ident($m[1]) . ' value (withheld) for column '
                 . self::ident($m[2]) . ' at row ' . (int) $m[3];
        }

        // SHAPES WHOSE QUOTED PARTS ARE ALL IDENTIFIERS.
        if (preg_match("/Data too long for column '([^']*)' at row (\\d+)/", $w, $m)) {
            return $tag . 'Data too long for column ' . self::ident($m[1]) . ' at row ' . (int) $m[2];
        }
        if (preg_match("/Out of range value for column '([^']*)' at row (\\d+)/", $w, $m)) {
            return $tag . 'Out of range value for column ' . self::ident($m[1]) . ' at row ' . (int) $m[2];
        }
        if (preg_match("/Column '([^']*)' cannot be null/", $w, $m)) {
            return $tag . 'Column ' . self::ident($m[1]) . ' cannot be null';
        }
        if (preg_match("/Field '([^']*)' doesn't have a default value/", $w, $m)) {
            return $tag . 'Field ' . self::ident($m[1]) . ' has no default value';
        }
        if (preg_match("/Unknown column '([^']*)' in '([^']*)'/", $w, $m)) {
            return $tag . 'Unknown column ' . self::ident($m[1]) . ' in ' . self::ident($m[2]);
        }
        if (preg_match("/Key '([^']*)' doesn't exist in table '([^']*)'/", $w, $m)) {
            return $tag . 'Key ' . self::ident($m[1]) . ' does not exist in table ' . self::ident($m[2]);
        }
        if (preg_match("/Table '([^']*)' doesn't exist/", $w, $m)) {
            return $tag . 'Table ' . self::ident($m[1]) . ' does not exist';
        }
        if (preg_match("/Unknown table '([^']*)'/", $w, $m)) {
            return $tag . 'Unknown table ' . self::ident($m[1]);
        }

        // SHAPES WITH NO QUOTED PARTS AT ALL. These are the ones the lease
        // predicates need: a deadlock is not a takeover, and saying so is the
        // whole of H12.
        if (preg_match('/Deadlock found when trying to get lock/', $w)) {
            return $tag . 'Deadlock found when trying to get lock; the work was rolled back';
        }
        if (preg_match('/Lock wait timeout exceeded/', $w)) {
            return $tag . 'Lock wait timeout exceeded; the work was rolled back';
        }
        if (preg_match('/(MySQL server has gone away|Lost connection to (?:the )?MySQL server)/', $w, $m)) {
            return $tag . $m[1];
        }
        return null;
    }

    /**
     * An identifier, or a refusal to print one.
     *
     * The guard is not decoration. Every capture above sits between quotes the
     * server wrote, and a VALUE containing an apostrophe can re-frame the text
     * so that a fragment of it lands in a capture group. Requiring identifier
     * shape means such a fragment is withheld rather than echoed, so the worst
     * case of a crafted value is a less useful message and never a disclosure.
     *
     * 140 characters: twice MySQL's 64-character identifier maximum, which is
     * enough for `schema.table` and `table.index` and nothing like enough for a
     * field value.
     */
    private static function ident($s)
    {
        $s = (string) $s;
        if ($s === '' || strlen($s) > 140) return '(name withheld)';
        if (!preg_match('/^[0-9A-Za-z_$. -]+\z/', $s)) return '(name withheld)';
        return "'" . $s . "'";
    }
}
