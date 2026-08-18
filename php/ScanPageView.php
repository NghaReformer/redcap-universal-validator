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
        return htmlspecialchars(self::scrub($s), ENT_QUOTES, 'UTF-8');
    }

    /**
     * Control bytes out, in ONE place, because the screen and the file are two
     * views of the same stored value and a byte dangerous in one is dangerous in
     * the other.
     *
     * 1.8.5 added this to csv() only. The same value was therefore sanitised on
     * its way into the download and passed through RAW into the HTML table - and
     * ESC into a terminal, which is the case that motivated the CSV fix, is
     * reached just as easily by viewing the page source or copying a cell out of
     * it. Splitting the rule across two functions meant one of them was always
     * going to be forgotten; there is now nothing to forget.
     *
     * A NUL truncates the cell in several readers, SUB is end-of-file to some
     * importers, and ESC begins a terminal escape sequence. TAB, CR and LF are
     * KEPT: they are legitimate inside a quoted CSV field and inside HTML, and
     * the quoting and escaping around them contain them.
     *
     * NUL is removed by str_replace and not by the pattern: a literal \x00 in a
     * PCRE pattern is a fatal "Null byte in regex" on PHP 7.4, which is the
     * matrix leg that caught it.
     */
    public static function scrub($s)
    {
        $s = str_replace([chr(0), chr(26)], '', (string) $s);
        return preg_replace('/[\x01-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $s);
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
        // Excel and Sheets strip leading whitespace, tabs, carriage returns and a
        // BOM BEFORE deciding whether a cell is a formula, so inspecting byte zero
        // alone is not enough: " =cmd|'/c calc'", a tab- or CR-prefixed payload,
        // and a BOM-prefixed one all reach the formula parser. Find the first byte
        // the spreadsheet would actually look at.
        $skip = [' ', "\t", "\r", "\n", "\0", "\x0B", "\x0C"];
        $i = 0;
        $len = strlen($s);
        while ($i < $len) {
            if (in_array($s[$i], $skip, true)) { $i++; continue; }
            if (substr($s, $i, 3) === "\xEF\xBB\xBF") { $i += 3; continue; }   // UTF-8 BOM
            break;
        }
        if ($i < $len && strpos('=+-@', $s[$i]) !== false) $s = "'" . $s;
        // Control bytes are removed, not quoted around - see scrub(), which the
        // page shares, so one stored value cannot be sanitised in the download
        // and passed through raw into the HTML table.
        //
        // The ORDER matters: the formula scan above runs on the bytes as stored,
        // because that is what a spreadsheet's own parser sees. Scrubbing first
        // could delete a control byte and expose a '=' that the pre-scrub scan
        // had already accounted for.
        return '"' . str_replace('"', '""', self::scrub($s)) . '"';
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

    /**
     * Why no scan runs, stated once and shared by the page and the exporter.
     *
     * The synchronous whole-project scan is WITHDRAWN, not merely discouraged.
     * It read every record id, every finding and every unique candidate into one
     * request's memory, and both entry points started that work from a GET - so
     * a refresh, a second tab or a download each launched another full pass over
     * the project with nothing serialising them. That is an availability problem
     * before it is a performance one, and no amount of guarding inside the loop
     * changes the shape.
     *
     * The rebuild plan's Task 1 requires exactly this: disable the production
     * synchronous scan and the export-by-rerun control, and say plainly that the
     * durable scan is unavailable until the worker exists. A notice is the
     * honest interface for a feature that cannot keep its promise; a scan that
     * sometimes finishes is worse, because the times it does teach people to
     * trust the times it does not.
     */
    const UNAVAILABLE = 'The project-wide validation scan is temporarily unavailable. It is being '
        . 'rebuilt to run as a resumable background job that can cover a project of any size and '
        . 'record exactly what it covered; until that lands, this page will not start a scan. '
        . 'Live form validation and the post-save audit are unaffected and continue to run.';

    /**
     * What a scan result may CLAIM, decided once.
     *
     * Three axes that are routinely confused: STATUS says whether the sweep
     * finished, COVERAGE says what finishing is worth on this installation, and
     * CLEAN is the only one that is a statement about the PROJECT. A run that
     * finished, found nothing, and could not prove the project stayed still is
     * complete and not clean.
     *
     * It lived inside pages/scan.php as three local variables, so the only way
     * to test it was to render a page and grep the HTML - and when the page
     * stopped rendering, the predicate lost its coverage with it. The durable
     * report (plan Task 7) needs the same answer, so it belongs here.
     */
    public static function verdict($result)
    {
        $complete = is_array($result) && isset($result['status']) && $result['status'] === 'complete';
        $coverage = (is_array($result) && isset($result['coverage'])) ? $result['coverage'] : 'partial';
        $fenced   = ($coverage === 'complete-through-fence');
        // A clean bill of health needs all four: the sweep finished, the server
        // could prove the project did not move under it, nothing was found, and
        // no rule was left unevaluated. A project whose every rule is broken has
        // zero violations and is not clean (M-02).
        $clean = $complete && $fenced
              && empty($result['violations']) && empty($result['unconfigurable']);
        return ['complete' => $complete, 'coverage' => $coverage,
                'fenced' => $fenced, 'clean' => $clean];
    }

    /** The notice, rendered wherever a scan would otherwise have been started. */
    public static function unavailable($extra = '')
    {
        echo '<div style="margin:12px 0;padding:10px 14px;border:1px solid #d9a441;background:#fdf6e3;'
           . 'color:#7a5c00;border-radius:4px;max-width:760px">'
           . '<b>&#9888; Scan unavailable</b><p style="margin:6px 0 0">' . self::h(self::UNAVAILABLE) . '</p>'
           . ($extra !== '' ? '<p style="margin:6px 0 0">' . self::h($extra) . '</p>' : '')
           . '</div>';
    }

    /**
     * Who this user may scan, decided ONCE and shared by every page that scans.
     *
     * Extracted so pages/export.php cannot drift from pages/scan.php. An export
     * route is reached by URL and is not a configured project link, so it never
     * passes through redcap_module_link_check_display — it has to re-derive
     * rights itself, and "re-derive" must not mean "a second copy that ages
     * differently from the first".
     *
     * @return array{ok: bool, dag: ?string, why: ?string}
     *         ok=false means REFUSE and say why. dag=null means unconfined.
     */
    public static function scanScope($module, $pid)
    {
        $no = function ($why) {
            return ['ok' => false, 'dag' => null, 'why' => $why, 'valueCeiling' => 'locations',
                    'mayExport' => false];
        };
        try {
            $user = $module->getUser();
            // is_callable, NEVER method_exists. The framework serves some methods
            // through __call(), for which method_exists() answers false — and
            // gating a SECURITY decision on it makes that decision fail OPEN.
            if (!$user || !is_callable([$user, 'hasDesignRights']) || !$user->hasDesignRights()) {
                return $no('You need project design rights to run the validation scan.');
            }
            // Whether this user is confined to a Data Access Group decides what
            // may be read, so an answer we cannot read must never be taken to
            // mean "not confined" — that is the fail-open direction.
            if (!is_callable([$user, 'getRights'])) {
                return $no('Your Data Access Group could not be established, so the validation scan was not run.');
            }
            $rights = $user->getRights($pid);
            // Some framework builds key rights by project id. Read THROUGH that
            // shape, not past it: $rights['group_id'] on a pid-keyed array is
            // simply unset, which reads as "no DAG" and confines nothing.
            if (is_array($rights) && isset($rights[$pid]) && is_array($rights[$pid])) $rights = $rights[$pid];
            if (!is_array($rights)) {
                return $no('Your Data Access Group could not be established, so the validation scan was not run.');
            }
            $ceiling = self::valueCeilingFor($rights);
            $mayExport = self::mayExportFor($rights);
            if (empty($rights['group_id'])) {
                return ['ok' => true, 'dag' => null, 'why' => null, 'valueCeiling' => $ceiling,
                        'mayExport' => $mayExport];
            }

            $gd = null;
            try {
                if (is_callable(['\REDCap', 'getGroupNames'])) {
                    $g = \REDCap::getGroupNames(true, $rights['group_id']);
                    if (is_string($g) && $g !== '') $gd = $g;
                }
            } catch (\Throwable $e) {
            }
            if ($gd === null) {
                // This used to set an '__unresolvable__' sentinel and scan on.
                // The sentinel matched no record, so the scan read nothing,
                // reported 'complete', and rendered a green tick over zero
                // records. Refusing is the only honest answer: no scope, nothing
                // to certify.
                return $no('Your Data Access Group could not be resolved, so there is no scope to scan. '
                         . 'The validation scan was not run.');
            }
            return ['ok' => true, 'dag' => $gd, 'why' => null, 'valueCeiling' => $ceiling,
                    'mayExport' => $mayExport];
        } catch (\Throwable $e) {
            return $no('Could not verify your rights — scan not run.');
        }
    }

    /** One CSV line from a list of values, each quoted and formula-defused. */
    public static function csvRow(array $vals)
    {
        $out = [];
        foreach ($vals as $v) $out[] = self::csv($v);
        return implode(',', $out);
    }

    /**
     * The most a reader may be shown, from their own REDCap export rights.
     *
     * Design rights are INDEPENDENT of form-level access and of export rights.
     * Before the report carried values that did not matter; it does now. A user
     * with design rights, No Access on an instrument and De-Identified export
     * rights would otherwise download every field's raw value for every record
     * from one URL, because the scan reads through \REDCap::getData() with a
     * project id and no user, which bypasses per-user rights entirely.
     *
     * REDCap's data_export_tool: 0 no access, 1 full data set, 2 de-identified,
     * 3 remove identifiers. Only a full-data-set user may see a raw value; an
     * unreadable or absent right is treated as no export right at all, because
     * the direction that fails safe is the restrictive one.
     */
    public static function valueCeilingFor($rights)
    {
        if (!is_array($rights) || !array_key_exists('data_export_tool', $rights)) return 'locations';
        $dx = (string) $rights['data_export_tool'];
        if ($dx === '1') return 'raw';
        if ($dx === '2' || $dx === '3') return 'identifier-redacted';
        return 'locations';                      // '0', '', or anything unrecognised
    }

    /**
     * Whether this reader may DOWNLOAD the report at all.
     *
     * data_export_tool = 0 is REDCap for "No Access to the data export tool".
     * The value ceiling downgraded what such a file contained and nothing
     * withheld the file, so a user REDCap bars from its own exporter could still
     * pull a project-wide findings file from this module - every record id, every
     * instrument, every field, in one request.
     *
     * The SCREEN stays available to them, at whatever the ceiling allows. The
     * distinction is deliberate: reading a report inside REDCap is not the same
     * act as walking out with the file, which is the distinction the export
     * right exists to draw in the first place.
     *
     * Unreadable or absent rights are no rights, because the safe direction is
     * the restrictive one.
     */
    public static function mayExportFor($rights)
    {
        if (!is_array($rights) || !array_key_exists('data_export_tool', $rights)) return false;
        return (string) $rights['data_export_tool'] !== '0';
    }
}
