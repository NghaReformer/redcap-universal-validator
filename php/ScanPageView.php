<?php
/**
 * ScanPageView — the validation scan page's output helpers.
 *
 * These lived as namespace-level functions inside pages/scan.php. That made the
 * page impossible to test with more than one scenario in a process: PHP has no
 * function_exists guard on a bare declaration, so a second include is a fatal
 * redeclare, and pages/scan.php has never had a single test. Hosting them on a
 * class in php/ — require_once'd from UniversalValidator.php alongside the other
 * four helpers — makes the page includable as many times as a test needs, and
 * makes the escaping and the CSV quoting directly testable on their own.
 *
 * Presentation only. Nothing here reads data, settings, or rights.
 */

namespace INSPIRE\UniversalValidator;

class ScanPageView
{
    /**
     * Most violation rows the page will RENDER. The count beside it is never
     * capped, so a truncated table can never read as a smaller problem than the
     * scan actually found; the CSV always carries every row.
     */
    const TABLE_MAX = 1000;

    /** HTML-escape for interpolation into the page. */
    public static function h($s)
    {
        return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
    }

    /**
     * One CSV cell: always quoted, and defused against spreadsheet formula
     * injection. Quoting is UNCONDITIONAL — README.md advertises that property,
     * so it is part of the file format, not an implementation detail. A leading
     * =, +, - or @ is what Excel and Sheets treat as a formula; prefixing an
     * apostrophe keeps the value visible and inert.
     */
    public static function csv($s)
    {
        $s = (string) $s;
        if ($s !== '' && strpos('=+-@', $s[0]) !== false) $s = "'" . $s;
        return '"' . str_replace('"', '""', $s) . '"';
    }

    /**
     * The page's single refusal wording. Every early return goes through here so
     * a test can pin the exact string and the page cannot drift into two
     * different ways of saying "no".
     */
    public static function refuse($why)
    {
        echo '<div class="red" style="margin:20px;padding:10px">' . self::h($why) . '</div>';
    }
}
