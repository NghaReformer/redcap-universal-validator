<?php
/**
 * docs_claims_php.php — the documentation names only what the build has.
 *
 * WHY THIS EXISTS (M19, P10). README described a 1.7.0 streaming writer and a
 * 75%/70% budget for releases after both were gone, INSTALL counted thirteen
 * tables while TESTING counted ten, and the user guide promised a one-shot
 * table of 1,000 rows that keyset paging replaced. Each was found by a
 * reviewer reading prose against code. These checks read the same prose
 * against the same code, so the next rename fails CI instead.
 *
 * WHAT IS CHECKED, AND AGAINST WHAT.
 *
 *   - Every `scan-*` name in code formatting is a config.json setting key, an
 *     AJAX action, or an event the module writes to its log.
 *   - Every name shaped like a config.json key (its first two words are a
 *     key's first two words) is a key.
 *   - In the scan docs, every `uv_*` name is a table of Schema::tables() or a
 *     cron of config.json, and a stated table count is that table count.
 *   - A line naming one cron and a schedule or a run-time limit states the
 *     cron's own, and INSTALL states both for each cron.
 *   - Every file path in code formatting under php/, pages/, js/, tests/,
 *     docs/ or data/ exists, and every `Schema::plan(N)` is a version.
 *   - INSTALL has an upgrade note for the current schema version, and it
 *     counts the tables and statements that upgrade has (Schema::plan()).
 *   - README and the live run sheet name every phase the page shows; README
 *     quotes the page's outcome sentences as the page words them, names every
 *     report column, and every export filename suffix a doc names is one the
 *     exporter can produce (README names each part).
 *   - README's findings-page section names the panel's four filters, quotes
 *     its paging buttons, its download link and its empty-filter sentence,
 *     and states the page size; the run sheet tests every filter.
 *   - A default a doc states beside a setting key is the default ScanPolicy
 *     applies.
 *   - TESTING's table of database cases is the list tests/mysql/run.php runs.
 *   - README's release candidate is the newest release in CHANGELOG.md.
 *
 * WHAT IS NOT. Whether a sentence is TRUE of behaviour is for the suites that
 * test the behaviour; this file compares the names, numbers and sentences the
 * prose borrows from the code with the code they came from. A plan kept as it
 * was first written (docs/event-instance-reference-plan-original.md) names
 * files that were later renamed, on purpose, and is left out of every check.
 *
 * Run:  php tests/docs_claims_php.php
 */

namespace {
    require_once __DIR__ . '/../php/Scan/ScanPhase.php';
    require_once __DIR__ . '/../php/Scan/ScanOutcome.php';
    require_once __DIR__ . '/../php/Scan/Schema.php';
    require_once __DIR__ . '/../php/Scan/ScanStore.php';
    require_once __DIR__ . '/../php/Scan/ScanAuthorization.php';
    require_once __DIR__ . '/../php/Scan/ScanPolicy.php';
    require_once __DIR__ . '/../php/ScanPageView.php';
    require_once __DIR__ . '/../php/Scan/ScanService.php';

    $n = 0; $fail = 0;
    function check($label, $cond) {
        global $n, $fail; $n++;
        if (!$cond) { $fail++; fwrite(STDERR, "FAIL: $label\n"); }
    }
}

namespace INSPIRE\UniversalValidator\Scan {

    use INSPIRE\UniversalValidator\ScanPageView;
    use function check;

    $root = dirname(__DIR__);
    $read = function ($rel) use ($root) {
        $s = @file_get_contents($root . '/' . $rel);
        return $s === false ? null : str_replace("\r\n", "\n", $s);
    };
    // Prose without its fenced blocks: a shell line in a fence is not a claim
    // about a name, and its backticks would pair with the prose's.
    $prose = function ($text) {
        return preg_replace('/^```.*?^```[^\n]*$/ms', '', (string) $text);
    };
    // Every `inline code` span of the prose.
    $codeSpans = function ($text) use ($prose) {
        preg_match_all('/`([^`\n]+)`/', $prose($text), $m);
        return $m[1];
    };
    $flat = function ($text) { return preg_replace('/\s+/', ' ', (string) $text); };

    /* =====================================================================
     * WHAT THE BUILD HAS
     * ===================================================================== */
    $config = json_decode((string) $read('config.json'), true);
    check('build: config.json reads as JSON', is_array($config));
    $config = is_array($config) ? $config : [];

    $keys = [];
    $walk = function (array $settings) use (&$walk, &$keys) {
        foreach ($settings as $s) {
            if (isset($s['key'])) $keys[(string) $s['key']] = true;
            if (isset($s['sub_settings']) && is_array($s['sub_settings'])) $walk($s['sub_settings']);
        }
    };
    foreach (['system-settings', 'project-settings'] as $k) {
        if (isset($config[$k]) && is_array($config[$k])) $walk($config[$k]);
    }
    check('build: config.json declares setting keys', count($keys) > 20 && isset($keys['scan-system-enable-durable']));

    $actions = [];
    foreach (['auth-ajax-actions', 'no-auth-ajax-actions'] as $k) {
        if (isset($config[$k]) && is_array($config[$k])) foreach ($config[$k] as $a) $actions[(string) $a] = true;
    }
    $crons = [];
    if (isset($config['crons']) && is_array($config['crons'])) {
        foreach ($config['crons'] as $c) {
            $crons[(string) $c['cron_name']] = ['every' => (int) $c['cron_frequency'],
                                                'max' => (int) $c['cron_max_run_time']];
        }
    }
    check('build: config.json declares the two scan crons', count($crons) === 2);

    // The events the module writes to its log, read from the source that
    // writes them: a doc naming one a reader can search the log for.
    $source = (string) $read('UniversalValidator.php');
    foreach (array_merge(glob($root . '/php/*.php'), glob($root . '/php/Scan/*.php')) as $f) {
        $source .= file_get_contents($f);
    }
    preg_match_all('/(?:->log\(|call_user_func\(\$this->log,|\$log\()\s*\'(scan-[a-z0-9-]+)\'/', $source, $m);
    $events = array_fill_keys($m[1], true);
    check('build: the module logs scan events', isset($events['scan-cron'], $events['scan-schema-migrate']));

    $tables = array_fill_keys(Schema::tables(), true);
    $numberWords = ['one' => 1, 'two' => 2, 'three' => 3, 'four' => 4, 'five' => 5, 'six' => 6,
        'seven' => 7, 'eight' => 8, 'nine' => 9, 'ten' => 10, 'eleven' => 11, 'twelve' => 12,
        'thirteen' => 13, 'fourteen' => 14, 'fifteen' => 15, 'sixteen' => 16, 'seventeen' => 17,
        'eighteen' => 18, 'nineteen' => 19, 'twenty' => 20];

    // The first two words of every key: a name in a doc that starts like a
    // key is a claim that it is one.
    $families = [];
    foreach (array_keys($keys) as $k) {
        $p = explode('-', $k);
        if (count($p) >= 2) $families[$p[0] . '-' . $p[1]] = true;
    }

    /* =====================================================================
     * THE DOCS
     * ===================================================================== */
    $docs = ['README.md' => $read('README.md'), 'tests/README.md' => $read('tests/README.md')];
    // The one doc that is a plan written before its build, kept as written:
    // the files it names are what it proposed, and some never existed.
    $asWritten = ['docs/event-instance-reference-plan-original.md'];
    foreach (glob($root . '/docs/*.md') as $f) {
        if (in_array('docs/' . basename($f), $asWritten, true)) continue;
        $docs['docs/' . basename($f)] = $read('docs/' . basename($f));
    }
    foreach (glob($root . '/docs/testbed/*.md') as $f) {
        $docs['docs/testbed/' . basename($f)] = $read('docs/testbed/' . basename($f));
    }
    $sheet = 'docs/testbed/scan_live_run_sheet.md';
    check('docs: the live run sheet exists', isset($docs[$sheet]) && $docs[$sheet] !== null);
    // The documents that describe the scan: here `uv_` names are its tables.
    $scanDocs = ['README.md', 'docs/INSTALL.md', 'docs/USER_GUIDE.md', 'docs/TESTING.md', $sheet];

    /* =====================================================================
     * NAMES IN CODE FORMATTING
     * ===================================================================== */
    foreach ($docs as $doc => $text) {
        if ($text === null) continue;
        foreach (array_unique($codeSpans($text)) as $span) {
            $t = trim($span);
            if (preg_match('/^scan-[a-z0-9-]+$/', $t)) {
                check($doc . ': `' . $t . '` is a setting key, an AJAX action or a log event',
                    isset($keys[$t]) || isset($actions[$t]) || isset($events[$t]));
                continue;
            }
            if (preg_match('/^[a-z][a-z0-9]*(?:-[a-z0-9]+)+$/', $t)) {
                $p = explode('-', $t);
                if (isset($families[$p[0] . '-' . $p[1]])) {
                    check($doc . ': `' . $t . '` is a config.json setting key', isset($keys[$t]));
                }
                if (substr($t, -6) === '-check') {
                    check($doc . ': `' . $t . '` is an AJAX action', isset($actions[$t]));
                }
            }
            // A path the doc points at. Globs, prefixes and placeholders are
            // not paths.
            if (preg_match('#^(?:php|pages|js|tests|docs|data)/[A-Za-z0-9_./-]+\.(?:php|js|cjs|json|md|csv|py|sql)$#', $t)) {
                check($doc . ': `' . $t . '` exists', is_file($root . '/' . $t));
            }
            if (preg_match('/Schema::plan\((\d+)\)/', $t, $pm)) {
                check($doc . ': `' . $t . '` names a schema version', (int) $pm[1] < Schema::VERSION);
            }
        }
    }

    // `uv_` names in the scan docs: tables, crons, or the database the
    // database suite connects to by default, read from its runner.
    preg_match("/getenv\('UV_DB_NAME'\) \?: '([a-z0-9_]+)'/", (string) $read('tests/mysql/run.php'), $dbm);
    $testDb = isset($dbm[1]) ? $dbm[1] : null;
    check('build: tests/mysql/run.php names its default database', $testDb !== null);
    foreach ($scanDocs as $doc) {
        if (!isset($docs[$doc]) || $docs[$doc] === null) continue;
        foreach (array_unique($codeSpans($docs[$doc])) as $span) {
            if (!preg_match('/^(uv_[a-z0-9_]*[a-z0-9])(?:\.[a-z_]+)?$/', trim($span), $um)) continue;
            check($doc . ': `' . $um[1] . '` is a table or a cron of the scan',
                isset($tables[$um[1]]) || isset($crons[$um[1]]) || $um[1] === $testDb);
        }
    }

    /* =====================================================================
     * TABLE COUNT, CRONS, SCHEMA VERSION
     * ===================================================================== */
    // The tables and statements of the latest upgrade, which INSTALL's
    // upgrade note counts.
    $upgrade = Schema::plan(Schema::VERSION - 1);
    $altered = [];
    foreach ($upgrade as $sql) {
        if (preg_match('/^\s*ALTER TABLE\s+`?(uv_[a-z0-9_]+)/i', $sql, $am)) $altered[$am[1]] = true;
    }
    $words = array_flip($numberWords);

    // A count of tables in the scan docs is the scan's table count, or, in
    // a sentence about what an upgrade changes or the statements it runs,
    // the number of tables that upgrade alters. Table cells are sentences
    // of their own, and a count may be written "thirteen `uv_` tables".
    $counted = 0;
    $countRe = '/\b(\d+|' . implode('|', array_keys($numberWords)) . ')\s+(?:`uv_\*?`\s+)?tables\b/i';
    foreach ($scanDocs as $doc) {
        if (!isset($docs[$doc]) || $docs[$doc] === null) continue;
        foreach (preg_split('/(?<=[.!?])\s+|\s\|\s/', $flat($prose($docs[$doc]))) as $sentence) {
            if (!preg_match_all($countRe, $sentence, $tm)) continue;
            $upgrading = preg_match('/\b(?:changes|alters|statements)\b/i', $sentence) === 1;
            foreach ($tm[1] as $w) {
                $num = ctype_digit($w) ? (int) $w : $numberWords[strtolower($w)];
                $counted++;
                if ($upgrading) {
                    check($doc . ': "' . $w . ' tables" is how many tables the upgrade to schema version '
                        . Schema::VERSION . ' alters (' . count($altered) . ')', $num === count($altered));
                } else {
                    check($doc . ': "' . $w . ' tables" is the scan\'s table count (' . count($tables) . ')',
                        $num === count($tables));
                }
            }
        }
    }
    check('docs: INSTALL states how many tables the scan keeps', $counted > 0
        && preg_match('/\b(?:thirteen|13) tables\b/i', (string) $docs['docs/INSTALL.md']) === 1
        && count($tables) === 13);
    $n7 = count($upgrade);
    check('docs/INSTALL.md: the upgrade note counts the ' . $n7 . ' statements of the latest upgrade',
        preg_match('/\b(?:' . $n7 . (isset($words[$n7]) ? '|' . $words[$n7] : '') . ') statements\b/i',
                   $flat((string) $docs['docs/INSTALL.md'])) === 1);

    $stated = [];
    foreach ($docs as $doc => $text) {
        if ($text === null) continue;
        foreach (explode("\n", $text) as $line) {
            $named = [];
            foreach (array_keys($crons) as $c) if (strpos($line, $c) !== false) $named[] = $c;
            if (count($named) !== 1) continue;
            $c = $named[0];
            $every = null;
            if (preg_match('/every (\d+) minutes|\|\s*(\d+) minutes\s*\|/', $line, $em)) {
                $every = 60 * (int) ($em[1] !== '' ? $em[1] : $em[2]);
            } elseif (preg_match('/every hour|hourly|\|\s*hour\s*\|/', $line)) {
                $every = 3600;
            }
            if ($every !== null) {
                $stated[$doc][$c]['every'] = true;
                check($doc . ': ' . $c . ' runs every ' . $every . ' seconds as config.json declares',
                    $every === $crons[$c]['every']);
            }
            if (preg_match('/(\d+) seconds/', $line, $sm)) {
                $stated[$doc][$c]['max'] = true;
                check($doc . ': ' . $c . ' runs for at most ' . $sm[1] . ' seconds as config.json declares',
                    (int) $sm[1] === $crons[$c]['max']);
            }
        }
    }
    foreach (array_keys($crons) as $c) {
        check('docs/INSTALL.md states the schedule and the run-time limit of ' . $c,
            !empty($stated['docs/INSTALL.md'][$c]['every']) && !empty($stated['docs/INSTALL.md'][$c]['max']));
        check('README.md states the schedule of ' . $c, !empty($stated['README.md'][$c]['every']));
    }

    $install = $flat((string) $docs['docs/INSTALL.md']);
    check('docs/INSTALL.md: an upgrade note for schema version ' . Schema::VERSION,
        strpos($install, 'schema version ' . (Schema::VERSION - 1) . ' to ' . Schema::VERSION) !== false);
    foreach ($scanDocs as $doc) {
        if (!isset($docs[$doc]) || $docs[$doc] === null) continue;
        preg_match_all('/schema version (\d+)/i', $docs[$doc], $vm);
        foreach ($vm[1] as $v) {
            check($doc . ': schema version ' . $v . ' exists', (int) $v >= 1 && (int) $v <= Schema::VERSION);
        }
    }

    /* =====================================================================
     * THE PAGE'S OWN WORDS
     * ===================================================================== */
    $readme = $flat((string) $docs['README.md']);
    foreach (ScanPageView::phaseLabels() as $phase => $label) {
        check('README.md names the phase "' . $label . '"', strpos($readme, $label) !== false);
        check($sheet . ' names the phase "' . $label . '"',
            isset($docs[$sheet]) && strpos($flat($docs[$sheet]), $label) !== false);
    }

    // The coverage sentences, as the README's table quotes them: a quote is
    // the page's sentence, or the page's sentence up to its colon.
    $sentences = ScanPageView::coverageSentences();
    foreach ($sentences as $coverage => $s) {
        $colon = strpos($s, ':');
        $head = $colon === false ? $s : substr($s, 0, $colon + 1);
        check('README.md quotes the ' . $coverage . ' sentence as the page words it',
            strpos($readme, '"' . $s . '"') !== false || strpos($readme, '"' . $head . ' ...') !== false);
    }
    preg_match('/### What a finished run says(.*?)\n### /s', (string) $docs['README.md'], $section);
    check('README.md has a "What a finished run says" section', isset($section[1]));
    $tableQuotes = 0;
    foreach (explode("\n", isset($section[1]) ? $section[1] : '') as $line) {
        if (!preg_match('/^\| "([^"]+)" \|/', $line, $qm)) continue;
        $tableQuotes++;
        $q = rtrim(preg_replace('/\s*\.\.\.$/', '', $qm[1]));
        $known = false;
        foreach ($sentences as $s) if (strpos($s, $q) === 0) $known = true;
        check('README.md: "' . substr($q, 0, 50) . '" is a sentence the page shows', $known);
    }
    check('README.md: its outcome table quotes every coverage sentence', $tableQuotes === count($sentences));
    foreach ([ScanPageView::NOT_CERTIFIED, ScanPageView::DETAIL_TRUNCATED,
              str_replace(' {date}.', '', ScanPageView::JUDGED_AS_OF),
              ScanPageView::STOPPED_WITHHELD, ScanPageView::STOPPED_PROVED, ScanPageView::UPGRADED] as $s) {
        check('README.md quotes "' . $s . '" as the page words it', strpos($readme, $s) !== false);
    }

    // The report's columns, by the labels the page and the file print.
    preg_match_all("/'label' => '([^']+)'/", (string) $read('php/ScanColumns.php'), $cm);
    check('build: ScanColumns names its columns', count($cm[1]) >= 10);
    foreach ($cm[1] as $label) {
        check('README.md names the report column "' . $label . '"', strpos($readme, $label) !== false);
    }

    // The findings panel, as README's findings-page section and the run
    // sheet quote it: the filter labels, the paging buttons and the download
    // link of pages/scan.php, the empty-filter sentence, and the page size.
    $scanPage = (string) $read('pages/scan.php');
    preg_match('/foreach \(\[(\'form\' => [^\]]+)\] as \$fk => \$fl\)/', $scanPage, $fm);
    preg_match_all("/=> '([^']+)'/", isset($fm[1]) ? $fm[1] : '', $fl);
    check('build: pages/scan.php names the four findings filters', count($fl[1]) === 4);
    preg_match_all('/id="uv-scan-report-(?:prev|next)"[^>]*>([^<]+)</', $scanPage, $bm);
    check('build: pages/scan.php has the two paging buttons', count($bm[1]) === 2);
    preg_match('/<a id="uv-scan-report-export">([^<]+)<\/a>/', $scanPage, $dm);
    check('build: pages/scan.php has the download link', isset($dm[1]));
    preg_match('/### The findings page(.*?)\n### /s', (string) $docs['README.md'], $fp);
    $panel = $flat(isset($fp[1]) ? $fp[1] : '');
    check('README.md has a "The findings page" section', $panel !== '');
    $sheetText = $flat((string) $docs[$sheet]);
    foreach ($fl[1] as $label) {
        check('README.md names the findings filter "' . $label . '"', strpos($panel, $label) !== false);
        check('the live run sheet tests the findings filter "' . $label . '"',
            strpos($sheetText, $label . ' filter') !== false);
    }
    foreach (array_merge($bm[1], isset($dm[1]) ? [$dm[1]] : [], [ScanPageView::REPORT_EMPTY]) as $s) {
        check('README.md quotes "' . $s . '" as the page words it', strpos($panel, '"' . $s . '"') !== false);
    }
    foreach ($scanDocs as $doc) {
        if (!isset($docs[$doc]) || $docs[$doc] === null) continue;
        preg_match_all('/\b(\d+) to a page\b/', $flat($docs[$doc]), $pm);
        foreach ($pm[1] as $size) {
            check($doc . ': "' . $size . ' to a page" is the findings page size (' . ScanService::FINDINGS_PAGE . ')',
                (int) $size === ScanService::FINDINGS_PAGE);
        }
    }
    check('README.md states the findings page size', strpos($panel, ScanService::FINDINGS_PAGE . ' to a page') !== false);

    // Filename suffixes: every one the exporter can give a file, and its parts.
    $suffixes = [];
    $parts = [];
    $outcomes = [
        [ScanOutcome::FAILED, ScanOutcome::COV_FAILED], [ScanOutcome::CANCELLED, ScanOutcome::COV_PARTIAL],
        [ScanOutcome::EXPIRED, ScanOutcome::COV_PARTIAL], [ScanOutcome::PARTIAL, ScanOutcome::EMPTY_SCOPE],
        [ScanOutcome::PARTIAL, ScanOutcome::COV_PARTIAL], [ScanOutcome::PARTIAL, ScanOutcome::MANIFEST],
        [ScanOutcome::PARTIAL, ScanOutcome::FENCED], [ScanOutcome::COMPLETE, ScanOutcome::FENCED],
    ];
    foreach ($outcomes as $o) {
        foreach ([ScanOutcome::DETAIL_COMPLETE, ScanOutcome::DETAIL_TRUNCATED] as $d) {
            foreach ([null, 0, 1] as $clean) {
                $sfx = ScanService::incompleteMarks(['terminal' => $o[0], 'coverage' => $o[1],
                                                     'detail' => $d, 'clean' => $clean])['suffix'];
                if ($sfx !== '') $suffixes[$sfx] = true;
            }
        }
        // The parts a suffix is built from: the outcome's own, then what
        // truncation adds to it. incompleteMarks() puts _INCOMPLETE in front
        // of an incomplete run's, and gives _NOT-CERTIFIED a complete run
        // that may not be called clean; both are whole suffixes above.
        $whole = ScanOutcome::suffix(['terminal' => $o[0], 'coverage' => $o[1],
                                      'detail' => ScanOutcome::DETAIL_COMPLETE, 'clean' => false]);
        $cut = ScanOutcome::suffix(['terminal' => $o[0], 'coverage' => $o[1],
                                    'detail' => ScanOutcome::DETAIL_TRUNCATED, 'clean' => false]);
        if ($whole !== '') $parts[$whole] = true;
        if (strlen($cut) > strlen($whole)) $parts[substr($cut, strlen($whole))] = true;
    }
    foreach (['_INCOMPLETE', '_NOT-CERTIFIED'] as $s) if (isset($suffixes[$s])) $parts[$s] = true;
    check('build: the exporter has suffixes to name', isset($suffixes['_INCOMPLETE'], $suffixes['_NOT-CERTIFIED'])
        && isset($parts['_TRUNCATED']));
    foreach ($docs as $doc => $text) {
        if ($text === null) continue;
        foreach (array_unique($codeSpans($text)) as $span) {
            if (!preg_match('/^_[A-Z][A-Z_-]+$/', $span)) continue;
            check($doc . ': `' . $span . '` is a filename suffix the export gives',
                isset($suffixes[$span]) || isset($parts[$span]));
        }
    }
    foreach (array_keys($parts) as $p) {
        check('README.md names the filename suffix `' . $p . '`', strpos($readme, '`' . $p . '`') !== false);
    }

    /* =====================================================================
     * DEFAULTS
     * ===================================================================== */
    $defaults = [
        'scan-system-stale-run-hours'        => ScanPolicy::D_STALE_HOURS,
        'scan-system-max-concurrent-projects' => ScanPolicy::D_MAX_PROJECTS,
        'scan-run-retention-days'            => ScanPolicy::D_RUN_DAYS,
        'scan-value-retention-days'          => ScanPolicy::D_VALUE_DAYS,
        'scan-max-detail-findings'           => ScanPolicy::D_MAX_FINDINGS,
        'scan-system-record-attempts'        => ScanPolicy::D_RECORD_ATTEMPTS,
    ];
    $seen = 0;
    foreach ($docs as $doc => $text) {
        if ($text === null) continue;
        $t = $flat($text);
        foreach ($defaults as $key => $want) {
            preg_match_all('/`' . preg_quote($key, '/') . '`[^.;`]{0,60}?\(([\d,]+)(?: [a-z]+)?(?: and [^)]*)? by default/',
                           $t, $dm);
            foreach ($dm[1] as $got) {
                $seen++;
                check($doc . ': the default stated for `' . $key . '` is ' . $want,
                    (int) str_replace(',', '', $got) === (int) $want);
            }
        }
    }
    check('docs: the scan docs state defaults beside their keys', $seen >= 4);

    /* =====================================================================
     * THE DATABASE CASES, AND THE RELEASE
     * ===================================================================== */
    preg_match('/\$cases = array\((.*?)\);/s', (string) $read('tests/mysql/run.php'), $rm);
    preg_match_all("/'([a-z]+)'/", isset($rm[1]) ? $rm[1] : '', $rc);
    $cases = $rc[1];
    check('build: tests/mysql/run.php lists its cases', count($cases) >= 10);
    $testing = (string) $docs['docs/TESTING.md'];
    preg_match_all('#`cases/([a-z]+)\.php`#', $testing, $tc);
    foreach (array_unique($tc[1]) as $c) {
        check('docs/TESTING.md: cases/' . $c . '.php is a case the harness runs',
            in_array($c, $cases, true) && is_file($root . '/tests/mysql/cases/' . $c . '.php'));
    }
    foreach ($cases as $c) {
        check('docs/TESTING.md lists the database case ' . $c, in_array($c, $tc[1], true));
    }

    preg_match('/^## (\d+\.\d+\.\d+(?:-[0-9A-Za-z.]+)?)\b/m', (string) $read('CHANGELOG.md'), $vm);
    preg_match('/Current release candidate: `v([^`]+)`/', (string) $docs['README.md'], $rmv);
    check('README.md names the newest release of CHANGELOG.md as the current release candidate',
        isset($vm[1], $rmv[1]) && $vm[1] === $rmv[1]);

    echo "docs_claims_php: $n checks, $fail failure(s)\n";
    exit($fail ? 1 : 0);
}
