<?php
/**
 * scan_wiring_php.php — a safeguard nothing calls is not a safeguard.
 *
 * WHY THIS FILE EXISTS. The durable scan carries roughly twelve thousand green
 * assertions and shipped with retention that never ran, a revocation that never
 * fired, an abandoned-run reaper with no caller, a detail budget that never
 * stopped storage, and no way at all to read a finding. Six of the review's
 * blocking findings are the same sentence: written, tested, never called. The
 * tests were not wrong about the behaviour; they were the ONLY thing exercising
 * it, and nothing anywhere asked whether the module itself ever did.
 *
 * docs/TESTING.md:249 already records this failure mode by name — v1.4.0
 * shipped a production-inert @UVUNIQUE with every mocked test green — and the
 * repository responded by writing a paragraph about it. This is that paragraph
 * as a test.
 *
 * WHAT IT ASSERTS. Every public method declared under php/Scan/ and in the scan
 * report layer is either called somewhere in the SHIPPED tree, or is named on
 * the allow-list below with a reason. Three failures, and the second is the one
 * that keeps the list honest:
 *
 *   INERT     a method with no production caller and no allow-list line. Wire
 *             it, delete it, or write down why it is inert.
 *   ROTTED    an allow-list line for a method that now HAS a caller. Delete the
 *             line. Without this the list would silently become a graveyard,
 *             and the next reader would trust it.
 *   GHOST     an allow-list line for a method that no longer exists. Same fix,
 *             same reason.
 *
 * THE ALLOW-LIST IS THE POINT, not the detection. Forty-nine methods are inert
 * today. None of that is news to the remediation plan — it scheduled all of it.
 * What was missing is that "this safeguard is not wired" was a property nobody
 * could see. It is now a line in a file a reviewer reads, with a wave number on
 * it, that somebody has to delete.
 *
 * DELIBERATELY CONSERVATIVE, in three ways, all of them toward the false
 * NEGATIVE — because a wiring test that cries wolf is one that gets deleted:
 *
 *   Name collisions are not resolved. `findings(` anywhere in the shipped tree
 *   counts for ScanStore::findings, SqlScanStore::findings and
 *   ArrayScanStore::findings alike. An interface and its implementations
 *   therefore stand or fall together, which is the right granularity anyway:
 *   what matters is whether the CAPABILITY is reachable.
 *
 *   A method reached only through a variable callable — call_user_func,
 *   $cb(), [$obj, 'name'] — is not detected as wired and would be reported
 *   INERT. There is no such call in the scan today; if one is added, the
 *   allow-list is where it gets written down.
 *
 *   Only public methods are examined. A private method with no caller is dead
 *   code, but it cannot be a safeguard somebody believes in.
 *
 * Run:  php tests/scan_wiring_php.php
 */

namespace {

    $n = 0; $fail = 0;
    function check($label, $cond) {
        global $n, $fail; $n++;
        if (!$cond) { $fail++; fwrite(STDERR, "FAIL: $label\n"); }
    }

    /* =====================================================================
     * THE ALLOW-LIST
     *
     * Class::method => why it is not wired, and WHEN it will be. Every entry
     * names a wave of reports/scan-remediation-plan-2026-08-25.md, or states
     * plainly that nothing is scheduled — which is itself the finding.
     *
     * Deleting a line is how a safeguard graduates. Adding one should feel
     * expensive.
     * ===================================================================== */
    $allowed = [

        // -- test accessors. ArrayScanStore lives under php/Scan/ only because
        //    production type-hints ScanStore; these four read state that no
        //    production caller has any business asking for.
        'ArrayScanStore::candidates'  => 'test accessor on the in-memory store',
        'ArrayScanStore::allFindings' => 'test accessor on the in-memory store',
        'ArrayScanStore::allAudits'   => 'test accessor on the in-memory store',
        'ArrayScanStore::recordState' => 'test accessor on the in-memory store',

        // -- scheduled for DELETION, not for wiring.
        // The six leaseSlot/releaseSlot entries that stood here are GONE, not
        // moved: wave 2 deleted the methods. This check is what noticed - an
        // allow-list that outlives its subject is how a list of known-inert
        // code turns back into a list nobody reads.
        'ScanPageView::verdict'        => 'wave 7 deletes it: a second clean predicate with no caller that does not agree with ScanOutcome::mayClaimClean(), which is the drift ScanOutcome\'s docblock exists to prevent',

        // -- wave 7, the honest outcome.
        'ScanOutcome::mayClaimClean' => 'wave 7 (B4/H15) asks it: nothing currently asks whether "clean" may be said, so it is said',

        // -- wave 10, the safeguards. Each is dangerous before its dependency
        //    lands, which is why they are last and not first.
        'ScanRetention::expireValues'    => 'wave 10 (B6/H8) wires the cron; wave 8 must page it first, or the purge becomes the outage',
        'ScanRetention::purgeRuns'       => 'wave 10 (B6) wires the cron; needs wave 4\'s project scoping, or one project\'s expired run deletes every project\'s findings',
        'ScanRetention::revokePreviews'  => 'wave 10 (B6) wires it: a tightened privacy policy revokes nothing today',
        'ScanRetention::expireAbandoned' => 'wave 10 (B6) wires it: one closed browser tab holds a project\'s only slot forever',
        'ScanRetention::preview'         => 'wave 10 (B6) wires it with the rest of retention',
        'ScanStore::expireValues'        => 'wave 10, through ScanRetention',
        'ArrayScanStore::expireValues'   => 'wave 10, through ScanRetention',
        'SqlScanStore::expireValues'     => 'wave 10, through ScanRetention',
        'ScanPolicy::budgetSpent'        => 'wave 10 (H9): the detail budget never stops storage; it gates in ScanWorker::batch once wired',
        'ScanPolicy::tightened'          => 'wave 10 (B6): a tightened policy must revoke immediately rather than at the next scan',
        'ScanPolicy::floor'              => 'wave 10, transitively: tightened() is its only intended caller',
        'ScanAuthorization::preFenceStatus' => 'wave 10 (H10 part 1): counts are disclosed before a group-scoped run has proved its scope',

        // -- wave 11, the report. It could not have been built earlier:
        //    findings() filters on generation_id alone and generation_id was
        //    the literal 1 for every run of every project until wave 4.
        'ScanStore::findings'                    => 'wave 11 (B5): there is no way to read a finding, and a read path built before wave 4 is a cross-project leak',
        'ArrayScanStore::findings'               => 'wave 11 (B5), with the contract',
        'SqlScanStore::findings'                 => 'wave 11 (B5), with the contract',
        'ScanAuthorization::projectionStillValid' => 'wave 11 (H10 part 2): a report is never invalidated on DAG drift, and the read path is where it belongs',
        'ScanColumns::row'                       => 'wave 11 (B5): the findings table and CSV',
        'ScanColumns::headers'                   => 'wave 11 (B5): the findings table and CSV',
        'ScanColumns::keyLegend'                 => 'wave 11 (B5): the findings table and CSV',
        'ScanPageView::csvRow'                   => 'wave 11 (B5): pages/export.php is a permanent 503 until then',
        'ScanDimensions::isDegraded'             => 'wave 11 (M5): uv_scan_dim has no producer, so nothing can be degraded yet',
        'ScanDimensions::degradedSummary'        => 'wave 11 (M5), with isDegraded',

        // -- WorkerSlots::renew was deleted from this list the same way. It
        //    stood under "nothing is scheduled", saying that a long batch's
        //    slot lease simply expires under the worker still using it;
        //    ScanWorker::loop now renews it once per turn and stops when the
        //    renewal is refused, because a worker that lost its slot is the
        //    excess the semaphore exists to prevent.

        // -- ScanPageView::labels, panelPrefill and isJsIdentifierPath sat here
        //    for one afternoon and were removed the same day, when the
        //    browser-client item taught pages/scan.php to call them. That is
        //    the whole mechanism: the ROTTED check insisted, and the fix was to
        //    delete three lines. Nothing else about the list is different.

        // -- NOTHING IS SCHEDULED. These are the entries that should be
        //    uncomfortable to read. Each states what is lost by its absence.
        'ScanStore::writeManifest'      => 'no scheduled caller: production streams appendManifest() then freezeManifest(), so this is a test convenience living on the production contract',
        'ArrayScanStore::writeManifest' => 'no scheduled caller, with the contract',
        'SqlScanStore::writeManifest'   => 'no scheduled caller, with the contract',
        'WorkerSlots::idleAbove'        => 'no scheduled caller: a lowered concurrency limit therefore never takes effect',
        'ScanPhase::consistent'         => 'no scheduled caller: the phase/state agreement it checks is never checked',
        'ScanPhase::cancelTarget'       => 'no scheduled caller: cancellation picks its target elsewhere',
        'UniqueFinalizer::sweep'        => 'no scheduled caller: the duplicate-group sweep runs only when a test runs it',
        'Hmac::hex'                     => 'no scheduled caller: a hex rendering nothing renders',
        'ReasonCode::pooledNames'       => 'no scheduled caller: a diagnostic nothing asks for',
        'RecordManifestSource::via'     => 'no scheduled caller: the run never records which source it walked, so a report cannot say',
    ];

    /* =====================================================================
     * Discovery
     * ===================================================================== */

    /** The classes this test governs: the durable scan and its report layer. */
    function uv_declaration_files() {
        $root = dirname(__DIR__);
        $out = glob($root . '/php/Scan/*.php');
        foreach (['ScanCapabilities', 'ScanColumns', 'ScanDimensions', 'ScanPageView',
                  'FindingSink'] as $f) {
            $p = $root . '/php/' . $f . '.php';
            if (is_file($p)) $out[] = $p;
        }
        sort($out);
        return $out;
    }

    /**
     * The SHIPPED tree, which is the only place a call counts.
     *
     * tests/ is excluded because "its only callers are tests" is the exact
     * condition being detected. tools/ is excluded because it holds untracked
     * scratch scripts: including it hid six dead methods behind files that are
     * not in `git ls-files` and never run anywhere.
     */
    function uv_call_site_files() {
        $root = dirname(__DIR__);
        $out = [$root . '/UniversalValidator.php'];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(
                $root . '/php', \FilesystemIterator::SKIP_DOTS)) as $f) {
            if (substr($f->getFilename(), -4) === '.php') $out[] = $f->getPathname();
        }
        foreach (glob($root . '/pages/*.php') as $f) $out[] = $f;
        foreach (glob($root . '/js/*.js') as $f) $out[] = $f;
        sort($out);
        return $out;
    }

    /**
     * Public methods declared in one file, by the tokenizer.
     *
     * NOT preg_match('/public function (\w+)/'). Interface stubs carry no
     * visibility keyword at all — public is the default — and six of the dead
     * methods live on ScanStore, which is an interface. A regex for the word
     * "public" would have missed exactly the ones that matter, and this whole
     * file would have been a comfortable green.
     *
     * @return array<int,array{class:string, method:string, line:int}>
     */
    function uv_public_methods($file) {
        $t = [];
        foreach (token_get_all(file_get_contents($file)) as $tok) {
            if (is_array($tok)) {
                if ($tok[0] === T_WHITESPACE) continue;
                $t[] = [$tok[0], $tok[1], $tok[2]];
            } else {
                $t[] = [$tok, $tok, 0];
            }
        }
        $out = [];
        $class = '';
        for ($i = 0, $c = count($t); $i < $c; $i++) {
            $id = $t[$i][0];
            if (($id === T_CLASS || $id === T_INTERFACE || $id === T_TRAIT)
                    && isset($t[$i + 1]) && $t[$i + 1][0] === T_STRING
                    && !($i > 0 && $t[$i - 1][0] === T_NEW)) {
                $class = $t[$i + 1][1];
                continue;
            }
            if ($id !== T_FUNCTION) continue;
            $j = $i + 1;
            if (isset($t[$j]) && $t[$j][0] === '&') $j++;               // function &foo()
            if (!isset($t[$j]) || $t[$j][0] !== T_STRING) continue;      // a closure names nothing
            $name = $t[$j][1];
            // Backwards past the modifiers and the docblock for a visibility
            // keyword. Finding none means public, which is the case that
            // matters: that is how an interface declares every one of its
            // methods.
            $vis = 'public';
            for ($k = $i - 1; $k >= 0; $k--) {
                $m = $t[$k][0];
                if ($m === T_STATIC || $m === T_FINAL || $m === T_ABSTRACT
                        || $m === T_COMMENT || $m === T_DOC_COMMENT) continue;
                if ($m === T_PUBLIC)    { $vis = 'public';    break; }
                if ($m === T_PRIVATE)   { $vis = 'private';   break; }
                if ($m === T_PROTECTED) { $vis = 'protected'; break; }
                break;
            }
            if ($vis !== 'public') continue;
            if (strpos($name, '__') === 0) continue;   // constructors and magic
            if ($class === '') continue;               // a plain function is not a method
            $out[] = ['class' => $class, 'method' => $name, 'line' => $t[$j][2]];
        }
        return $out;
    }

    /**
     * The shipped tree with its PROSE removed.
     *
     * A comment that names a method is not a call to it, and this codebase's
     * comments name methods constantly - they are how it records why a thing
     * exists and what it replaced. Searching raw file text made the sentence
     * "ScanRetention::purgeRuns() removed those AND the findings" read as a
     * call site, so a method could be marked wired by the very comment
     * explaining that nothing calls it. Strings go too: a method name inside a
     * message is not a call either.
     */
    function uv_code_only($file) {
        $out = '';
        foreach (token_get_all(file_get_contents($file)) as $tok) {
            if (is_array($tok)) {
                if ($tok[0] === T_COMMENT || $tok[0] === T_DOC_COMMENT) continue;
                if ($tok[0] === T_CONSTANT_ENCAPSED_STRING) continue;
                if ($tok[0] === T_ENCAPSED_AND_WHITESPACE) continue;
                $out .= $tok[1];
            } else {
                $out .= $tok;
            }
        }
        return $out;
    }

    /** Is this method name invoked anywhere in the shipped tree? */
    function uv_is_called($name, $corpus) {
        return (bool) preg_match('/(->|::)\s*' . preg_quote($name, '/') . '\s*\(/', $corpus);
    }

    /**
     * The three failure lists, computed rather than asserted, so the checks
     * below can drive it over synthetic inputs and prove it can go red.
     *
     * @param array $declared  ['Class::method' => line-label]
     * @param array $wired     ['Class::method' => bool]
     * @param array $allowed   ['Class::method' => reason]
     */
    function uv_wiring_report(array $declared, array $wired, array $allowed) {
        $inert = []; $rotted = []; $ghosts = [];
        foreach ($declared as $key => $label) {
            if (!empty($wired[$key])) {
                if (isset($allowed[$key])) $rotted[] = $label;
                continue;
            }
            if (!isset($allowed[$key])) $inert[] = $label;
        }
        foreach (array_keys($allowed) as $key) {
            if (!isset($declared[$key])) $ghosts[] = $key;
        }
        sort($inert); sort($rotted); sort($ghosts);
        return ['inert' => $inert, 'rotted' => $rotted, 'ghosts' => $ghosts];
    }

    $declFiles = uv_declaration_files();
    $siteFiles = uv_call_site_files();
    $corpus = '';
    foreach ($siteFiles as $f) $corpus .= uv_code_only($f) . "\n";

    $declared = []; $wired = []; $where = [];
    foreach ($declFiles as $f) {
        foreach (uv_public_methods($f) as $m) {
            $key = $m['class'] . '::' . $m['method'];
            $declared[$key] = $key . '   (' . basename($f) . ':' . $m['line'] . ')';
            $where[$key] = $f;
            if (!isset($wired[$key])) $wired[$key] = uv_is_called($m['method'], $corpus);
        }
    }

    /* =====================================================================
     * W-01  the analysis is looking at something
     *
     * Both directions of a broken corpus are loud — an empty call-site corpus
     * makes every method inert, and a corpus that wrongly included tests/ makes
     * every allow-list entry rotted — but a test whose evidence is implicit is
     * how this repository got here, so it is stated.
     * ===================================================================== */
    {
        check('W-01: the durable scan is under examination', count($declFiles) >= 20);
        check('W-01: and so is the report layer',
            (bool) preg_grep('~ScanPageView\.php$~', array_map(function ($p) {
                return str_replace('\\', '/', $p); }, $declFiles)));
        check('W-01: public methods were found in numbers', count($declared) >= 150);
        check('W-01: the call-site corpus is the shipped tree', count($siteFiles) >= 25);
        check('W-01: it does NOT include the tests', !preg_grep('~/tests/~',
            array_map(function ($p) { return str_replace('\\', '/', $p); }, $siteFiles)));
        check('W-01: nor the scratch tools', !preg_grep('~/tools/~',
            array_map(function ($p) { return str_replace('\\', '/', $p); }, $siteFiles)));

        // Positive controls: methods the module unquestionably calls. If these
        // read as unwired the matcher is broken, and every other answer here is
        // noise.
        check('W-01: a method the service calls reads as wired',
            uv_is_called('claim', $corpus) && uv_is_called('commitBatch', $corpus));
        check('W-01: and one the page calls', uv_is_called('scanScope', $corpus));
        // Negative control: a name nothing anywhere calls must read as unwired,
        // or the matcher answers true for everything.
        check('W-01: an invented name reads as unwired',
            !uv_is_called('thisMethodDoesNotExistAnywhere', $corpus));
    }

    /* =====================================================================
     * W-02  the report itself can go red, in all three directions
     *
     * Driven over synthetic inputs, because the tree is green by construction:
     * the allow-list was populated from it. Without this, W-03 would pass on a
     * report function that returned three empty arrays.
     * ===================================================================== */
    {
        $decl = ['A::wired' => 'A::wired', 'A::inert' => 'A::inert', 'A::known' => 'A::known'];
        $wire = ['A::wired' => true, 'A::inert' => false, 'A::known' => false];

        $r = uv_wiring_report($decl, $wire, ['A::known' => 'documented']);
        check('W-02: an undocumented method with no caller is INERT', $r['inert'] === ['A::inert']);
        check('W-02: a documented one is not', $r['rotted'] === [] && $r['ghosts'] === []);

        $r2 = uv_wiring_report($decl, $wire, ['A::known' => 'documented', 'A::wired' => 'stale']);
        check('W-02: an allow-list line for a method that acquired a caller is ROTTED',
            $r2['rotted'] === ['A::wired']);

        $r3 = uv_wiring_report($decl, $wire, ['A::known' => 'documented', 'A::gone' => 'stale']);
        check('W-02: an allow-list line for a method that no longer exists is a GHOST',
            $r3['ghosts'] === ['A::gone']);

        $r4 = uv_wiring_report($decl, $wire, []);
        check('W-02: an empty allow-list reports every inert method, so the list is load-bearing',
            $r4['inert'] === ['A::inert', 'A::known']);
    }

    /* =====================================================================
     * W-03  the shipped tree agrees with the allow-list
     * ===================================================================== */
    {
        $r = uv_wiring_report($declared, $wired, $allowed);

        if ($r['inert']) {
            fwrite(STDERR, "  public methods with no caller in the shipped tree, and no allow-list line:\n    "
                . implode("\n    ", $r['inert'])
                . "\n  Wire it, delete it, or add a line to \$allowed saying which wave will.\n");
        }
        check('W-03: every inert scan method is either wired or written down', $r['inert'] === []);

        if ($r['rotted']) {
            fwrite(STDERR, "  allow-list lines for methods that NOW HAVE a caller - delete these lines:\n    "
                . implode("\n    ", $r['rotted']) . "\n");
        }
        check('W-03: no allow-list line outlived the reason it was written', $r['rotted'] === []);

        if ($r['ghosts']) {
            fwrite(STDERR, "  allow-list lines for methods that no longer exist - delete these lines:\n    "
                . implode("\n    ", $r['ghosts']) . "\n");
        }
        check('W-03: no allow-list line names a method that is gone', $r['ghosts'] === []);

        // Every line carries a reason somebody wrote, not a placeholder. A list
        // of bare names would be a suppression file, which is the failure mode
        // this replaces.
        $unexplained = [];
        foreach ($allowed as $key => $why) {
            if (!is_string($why) || strlen($why) < 20) $unexplained[] = $key;
        }
        check('W-03: every allow-list line explains itself', $unexplained === []);
    }

    /* =====================================================================
     * W-04  the scale of it, stated once
     *
     * Not a pass/fail on the number - it will fall as the waves land, and a
     * test that has to be edited every time is a test that gets edited without
     * being read. It is printed so the count is in the CI log rather than in a
     * report nobody opens.
     * ===================================================================== */
    {
        $inert = count($allowed);
        check('W-04: the inert surface is documented rather than discovered', $inert > 0);
        echo "  " . $inert . " public scan methods are inert today, each with a written reason\n";
    }

    echo "scan_wiring_php: $n checks, $fail failure(s)\n";
    exit($fail ? 1 : 0);
}
