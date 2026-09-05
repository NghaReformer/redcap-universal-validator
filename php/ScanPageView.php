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
        // SCRUB FIRST, THEN LOOK. This order is the fix, and the order it
        // replaces was defended by a comment that had the causality backwards:
        // "scrubbing first could delete a control byte and expose a '=' that
        // the pre-scrub scan had already accounted for". The pre-scrub scan did
        // not account for it. It walked the leading bytes, stopped at the first
        // one not in $skip, and if that byte was a control byte it concluded
        // the cell was not a formula and added no apostrophe - and then scrub()
        // deleted exactly that byte, leaving '=' as the emitted cell's first
        // content byte. So "\x01=1+1" left here as a live =1+1, as did a
        // leading \x1B, \x1A or \x7F, and " \x01 =cmd|'/c calc'" survived the
        // leading-space handling the same way. One byte, chosen from the set
        // this module's own sanitiser is guaranteed to remove, walked through
        // the defence.
        //
        // Scrubbing first cannot hide a formula start, only reveal one: scrub()
        // removes control bytes and nothing else, so it never removes =, +, -
        // or @, and every byte it does remove is one the scan below would have
        // had to decide about anyway.
        $s = self::scrub((string) $s);
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
        // Control bytes were removed above, not quoted around - see scrub(),
        // which the page shares, so one stored value cannot be sanitised in the
        // download and passed through raw into the HTML table.
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

    /**
     * How a phase reads to somebody who did not design this.
     *
     * Deliberately not the stored value. "unique-finalize" is a correct name for
     * a state machine and an alarming one for a data manager watching a progress
     * bar.
     *
     * THIS TABLE LIVES HERE AND NOWHERE ELSE. It was a literal inside
     * js/scan.js, which meant PHP and JavaScript each held half of the same
     * vocabulary and neither knew when the other changed. The page now renders
     * from this table and hands the same table to the client, so a new phase is
     * one entry rather than two edits a reviewer has to notice are a pair. The
     * recorded lesson is the v1.6.0 rounds, where the reviews kept failing on
     * callers of a shared helper that had not been converted with it.
     */
    public static function phaseLabels()
    {
        return [
            Scan\ScanPhase::PLANNING   => 'Listing the records to check',
            Scan\ScanPhase::SCANNING   => 'Checking records',
            Scan\ScanPhase::CATCH_UP   => 'Checking what changed while it ran',
            Scan\ScanPhase::UNIQUE     => 'Looking for duplicate values',
            Scan\ScanPhase::ROLLUP     => 'Building the summary',
            Scan\ScanPhase::CANCELLING => 'Stopping',
            Scan\ScanPhase::TERMINAL   => 'Finished',
        ];
    }

    /**
     * One phase, in words. An unrecognised phase falls back to its stored name:
     * a state-machine value on screen is ugly, and an empty label where the
     * phase should be is a page that looks broken.
     */
    public static function phaseLabel($phase)
    {
        $m = self::phaseLabels();
        $k = (string) $phase;
        return isset($m[$k]) ? $m[$k] : $k;
    }

    /**
     * What a finished run may be said to have achieved.
     *
     * Every one of these is a different sentence on purpose. The whole rebuild
     * exists because one word - "complete" - was used for a run that examined
     * everything and a run that examined nothing.
     */
    public static function coverageSentences()
    {
        return [
            Scan\ScanOutcome::FENCED      => 'Every record was checked, including changes made while it ran.',
            Scan\ScanOutcome::MANIFEST    => 'Every record on the opening list was checked. This server '
                                           . 'cannot prove the project did not change during the scan.',
            // NO APOSTROPHE, AND THAT IS A CONSTRAINT RATHER THAN A STYLE.
            // pages/scan.php prints this table through json_encode with
            // JSON_HEX_APOS, so an apostrophe leaves here as an escape sequence -
            // and tests/scan_page_php.php checks that the first 40 characters of
            // each sentence appear literally in the HTML. Every existing
            // sentence happens to avoid one; this one avoids it on purpose.
            Scan\ScanOutcome::EMPTY_SCOPE => 'No records were in scope for this scan, so nothing was '
                                           . 'checked and this result says nothing about the project.',
            Scan\ScanOutcome::COV_PARTIAL => 'Some records could not be checked. This is not a complete '
                                           . 'picture of the project.',
            Scan\ScanOutcome::COV_FAILED  => 'The scan failed, so it describes nothing.',
        ];
    }

    /**
     * The sentence for a coverage value this build does not know.
     *
     * Saying nothing would be a blank certificate over a finished run, and that
     * is the one outcome this module refuses. Naming the unrecognised value
     * gives whoever has to fix it a thread to pull. {value} is substituted by
     * both halves of the page - PHP here, JavaScript in js/scan.js - so the two
     * cannot word it differently.
     */
    const COVERAGE_UNKNOWN = 'This scan finished, but this page does not recognise the result it '
        . 'recorded ({value}). Treat it as incomplete and run it again.';

    /**
     * Stated even on a run whose coverage was complete: the report the reader
     * holds is not the report the run produced.
     */
    const DETAIL_TRUNCATED = 'Some findings were not kept, because the scan reached the limit this '
        . 'project allows.';

    /**
     * The completion sentence for one finished run, built as ONE string.
     *
     * Built rather than appended to whatever is already on screen. The client
     * used to write the coverage sentence into the node and then concatenate the
     * truncation note onto its text, so an unmapped coverage produced a sentence
     * that began with a space and had no subject.
     */
    public static function coverageSentence($coverage, $detail = null)
    {
        $m = self::coverageSentences();
        $k = (string) $coverage;
        $s = isset($m[$k]) ? $m[$k] : str_replace('{value}', $k, self::COVERAGE_UNKNOWN);
        if ((string) $detail === Scan\ScanOutcome::DETAIL_TRUNCATED) {
            $s .= ' ' . self::DETAIL_TRUNCATED;
        }
        return $s;
    }

    /** The vocabulary the browser half renders from, so it holds no copy of its own. */
    public static function labels()
    {
        return [
            'phase'           => self::phaseLabels(),
            'coverage'        => self::coverageSentences(),
            'coverageUnknown' => self::COVERAGE_UNKNOWN,
            'truncated'       => self::DETAIL_TRUNCATED,
        ];
    }

    /**
     * Percentage, or null when the total is not knowable yet.
     *
     * Null is not zero, and the distinction is the whole point: a bar sitting at
     * 0% for the length of a planning phase reads as a scan that has stalled,
     * and people stop scans that look stalled.
     */
    public static function pct($done, $total)
    {
        $total = (int) $total;
        if ($total <= 0) return null;
        $p = (int) floor(((int) $done / $total) * 100);
        if ($p < 0) return 0;
        return $p > 100 ? 100 : $p;
    }

    /**
     * The panel's text BEFORE any script runs.
     *
     * pages/scan.php has claimed since it was written that it "renders the state
     * before any script runs, so somebody with scripting disabled still sees
     * whether their scan is going rather than an empty box". It did not: every
     * value span was emitted empty and the bar was hardcoded to zero, so a
     * reader without JavaScript got exactly the empty box the comment promised
     * they would not, under a progress bar that read as a stalled run. This
     * produces the same four strings js/scan.js produces, from the same status
     * array, so the first paint and the first poll agree.
     *
     * @param  ?array $status a ScanService::status() array, or null when there is
     *                no run or its status could not be read
     * @return array{phase:string, counts:string, found:string, pct:?int, done:?string, active:bool}
     */
    public static function panelPrefill($status)
    {
        if (!is_array($status) || empty($status['ok'])) {
            return ['phase' => '', 'counts' => '', 'found' => '', 'pct' => null,
                    'done' => null, 'active' => false];
        }

        $total = isset($status['total']) ? (int) $status['total'] : 0;
        $done  = isset($status['done']) ? (int) $status['done'] : 0;
        $pct   = self::pct($done, $total);
        $found = isset($status['findings']) ? (int) $status['findings'] : 0;

        return [
            'phase'  => self::phaseLabel(isset($status['phase']) ? $status['phase'] : ''),
            'counts' => $total > 0
                ? ($done . ' of ' . $total . ' records' . ($pct === null ? '' : '  (' . $pct . '%)'))
                : 'Preparing',
            'found'  => $found === 0 ? 'Nothing found yet'
                : ($found . ' finding' . ($found === 1 ? '' : 's') . ' so far'),
            'pct'    => $pct,
            'done'   => empty($status['terminal']) ? null
                : self::coverageSentence(isset($status['coverage']) ? $status['coverage'] : '',
                                         isset($status['detail']) ? $status['detail'] : null),
            'active' => !empty($status['active']),
        ];
    }

    /**
     * Is this a name the page may print inside a <script> block?
     *
     * The framework's JavaScript module object name is not project data and not
     * user input - External Modules derives it from the module's installed
     * directory - so this is not an escaping problem, and escaping cannot solve
     * it: htmlspecialchars on an expression leaves an expression that no longer
     * evaluates. It is a missing input contract. A name that is not a dotted
     * identifier becomes a syntax error inside the block that prints it, and a
     * panel that fails with a syntax error explains nothing to the person who
     * has to fix it - which is how the first pilot's Start button failed, by a
     * different route.
     *
     * The length cap is there so a pathological value cannot bloat the page.
     */
    public static function isJsIdentifierPath($name)
    {
        if (!is_string($name) || $name === '' || strlen($name) > 200) return false;
        return (bool) preg_match('/^[A-Za-z_$][A-Za-z0-9_$]*(?:\.[A-Za-z_$][A-Za-z0-9_$]*)*$/', $name);
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
                    'mayExport' => false, 'rights' => null];
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
            // Resolve an administrator's entitlement before any gate reads it.
            // A super-user has no rights row to read, and every gate downstream
            // treated that absence as "no rights" - see normalizeRights().
            $rights = self::normalizeRights($rights, $user);
            if (!is_array($rights)) {
                return $no('Your Data Access Group could not be established, so the validation scan was not run.');
            }
            $ceiling = self::valueCeilingFor($rights);
            $mayExport = self::mayExportFor($rights);
            if (empty($rights['group_id'])) {
                // The rights array travels with the answer, because the durable
                // scan re-checks the SAME array against its entitlement form set
                // and a second read of getRights() could legitimately differ
                // from this one - which would mean the scope and the entitlement
                // were decided from two different readings of the same user.
                return ['ok' => true, 'dag' => null, 'why' => null, 'valueCeiling' => $ceiling,
                        'mayExport' => $mayExport, 'rights' => $rights];
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
                    'mayExport' => $mayExport, 'rights' => $rights];
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
        $dx = self::exportLevel($rights);
        if ($dx === null) return 'locations';
        $dx = (string) $dx;
        if ($dx === '1') return 'raw';
        if ($dx === '2' || $dx === '3') return 'identifier-redacted';
        return 'locations';                      // '0', '', or anything unrecognised
    }

    /**
     * The reader's export level, under whichever key this build supplies it.
     *
     * ONE READER, THREE CALLERS, and that is the point. The export level decides
     * the value ceiling, whether the file may be downloaded, and whether a run
     * may start at all; three copies of `$rights['data_export_tool']` is three
     * chances for one of them to be looking at the wrong key. The recorded
     * lesson from the v1.6.0 rounds is that a shared helper has to arrive with
     * every caller already converted.
     *
     * `data_export_tool` is the column in `redcap_user_rights` and what the
     * framework's User::getRights() returns. `data_export` is the name the same
     * value carries in REDCap's own API payloads. A build that hands back the
     * API shape would otherwise read as no export rights at all - a denial
     * indistinguishable from a correctly refused user, which is exactly how the
     * first live pilot got refused by an account REDCap's own User Rights page
     * showed as Full Data Set.
     *
     * Null when neither key is present, and every caller treats null as the
     * restrictive answer: guessing a level from an absent one fails open.
     */
    public static function exportLevel($rights)
    {
        if (!is_array($rights)) return null;
        foreach (['data_export_tool', 'data_export'] as $k) {
            if (array_key_exists($k, $rights) && $rights[$k] !== null && $rights[$k] !== '') {
                return $rights[$k];
            }
        }
        return null;
    }

    /**
     * Is this the account of a REDCap administrator?
     *
     * ASKED, NEVER INFERRED FROM ABSENCE. This is the distinction that keeps the
     * fix from being a hole: an account with no export level in its rights is
     * still refused, exactly as before. What changes is that we now ASK whether
     * the framework considers this user an administrator, and only an
     * affirmative answer counts.
     *
     * is_callable, never method_exists - the framework serves methods through
     * __call() and method_exists answers false for those, which is how v1.4.0
     * shipped a production-inert @UVUNIQUE.
     *
     * The REDCap global is a fallback and is consulted ONLY when a user object
     * exists, so it cannot be read in a cron or survey context where it might
     * describe somebody else.
     */
    public static function isAdministrator($user)
    {
        if (!$user) return false;
        try {
            if (is_callable([$user, 'isSuperUser'])) return (bool) $user->isSuperUser();
        } catch (\Throwable $e) {
        }
        if (isset($GLOBALS['super_user'])) return ((string) $GLOBALS['super_user'] === '1');
        if (defined('SUPER_USER')) return ((string) constant('SUPER_USER') === '1');
        return false;
    }

    /**
     * The framework's rights array, resolved into the entitlement this module
     * reasons about.
     *
     * WHY THIS EXISTS. REDCap administrators have no row in `redcap_user_rights`
     * for a project they were never added to - they do not need one, because a
     * super-user bypasses project rights entirely. The framework therefore hands
     * back an array with design rights and NO export level, and every gate here
     * read that as "no export rights", refusing a scan to the one category of
     * user who can already export the whole project from REDCap's own exporter.
     * The first live pilot was refused exactly this way.
     *
     * Resolution, not invention. An administrator's export level is filled in
     * only when the framework supplied none: an administrator who DOES have an
     * explicit rights row keeps whatever that row says, because a deliberate
     * restriction someone typed is worth more than an inference we made.
     *
     * The returned array is the MODULE's shape, which is why `superUser` is a
     * first-class key in it rather than something smuggled in - the gates read
     * it by name, and it is greppable.
     */
    public static function normalizeRights($rights, $user = null)
    {
        $admin = self::isAdministrator($user);
        if (!is_array($rights)) {
            // An unreadable rights shape stays unreadable. Manufacturing an
            // entitlement out of one is the fail-open direction, administrator
            // or not.
            return null;
        }
        $rights['superUser'] = $admin;
        if ($admin) {
            $rights['design'] = true;
            if (self::exportLevel($rights) === null) $rights['data_export_tool'] = '1';
        }
        return $rights;
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
        $dx = self::exportLevel($rights);
        return $dx !== null && (string) $dx !== '0';
    }
}
