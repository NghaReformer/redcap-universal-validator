<?php
/**
 * scan_worker_php.php — the durable scan's state machine and worker.
 *
 * Two things are checked here that nothing else can check:
 *
 *   THE WHOLE TRANSITION MATRIX, not a handful of interesting cases. Every one
 *   of the 49 (from, to) pairs is asserted against a table written out by hand
 *   below. That table is a second, independent statement of the same rule, so a
 *   change to ScanPhase that the author believed was equivalent has to be made
 *   twice before it can pass — the differential technique this repository
 *   already uses to hold the PHP and JavaScript engines together.
 *
 *   THE PLAN'S DERIVATION TABLE, transcribed row for row. tests/scan_security_
 *   php.php checks ScanOutcome::derive() case by case in prose; this file checks
 *   it as the table the plan states, with all five columns at once. A change
 *   that satisfies one prose check while breaking a row of the specification
 *   fails here.
 *
 * Run:  php tests/scan_worker_php.php
 */

namespace {
    require_once __DIR__ . '/../php/Scan/ScanOutcome.php';
    require_once __DIR__ . '/../php/Scan/ScanPhase.php';
    require_once __DIR__ . '/../php/Scan/ScanStore.php';
    require_once __DIR__ . '/../php/Scan/ArrayScanStore.php';
    require_once __DIR__ . '/../php/Scan/Hmac.php';
    require_once __DIR__ . '/../php/Scan/RecordManifestSource.php';
    require_once __DIR__ . '/../php/Scan/SourceFence.php';
    require_once __DIR__ . '/../php/Scan/ScanDb.php';
    require_once __DIR__ . '/../php/Scan/Schema.php';
    require_once __DIR__ . '/../php/Scan/ScanPlanner.php';
    require_once __DIR__ . '/../php/Scan/WorkBudget.php';
    require_once __DIR__ . '/../php/Scan/WorkerSlots.php';
    require_once __DIR__ . '/../php/Scan/UniqueFinalizer.php';
    require_once __DIR__ . '/../php/Scan/ScanWorker.php';

    $n = 0; $fail = 0;
    function check($label, $cond) {
        global $n, $fail; $n++;
        if (!$cond) { $fail++; fwrite(STDERR, "FAIL: $label\n"); }
    }
}

namespace INSPIRE\UniversalValidator\Scan {

    // -- the transition matrix, stated independently -------------------------
    //
    // Rows are FROM, columns are TO, and the letter is what ScanPhase::check()
    // must answer:
    //
    //   y  legal
    //   n  refused
    //   =  no-op (already there): refused, but benign, and must NOT be written
    //
    // Written out in full rather than generated, because a generated expectation
    // would share whatever assumption the implementation got wrong.
    $P = ['planning', 'scanning', 'catch-up', 'unique-finalize', 'rollup-finalize',
          'cancelling', 'terminal'];

    $matrix = [
        //                 plan scan catch uniq roll canc term
        'planning'        => ['=', 'y', 'n', 'n', 'n', 'y', 'y'],
        'scanning'        => ['n', '=', 'y', 'n', 'n', 'y', 'y'],
        'catch-up'        => ['n', 'n', '=', 'y', 'n', 'y', 'y'],
        'unique-finalize' => ['n', 'n', 'n', '=', 'y', 'y', 'y'],
        'rollup-finalize' => ['n', 'n', 'n', 'n', '=', 'y', 'y'],
        // A cancelling run only finishes. It never goes back to work, because
        // the epoch bump that made cancellation stick has already invalidated
        // every worker that was mid-evaluation.
        'cancelling'      => ['n', 'n', 'n', 'n', 'n', '=', 'y'],
        // Absorbing, with no exception: a finished run is never reopened, or a
        // retried finaliser could overwrite a terminal state that a report has
        // already been exported against.
        'terminal'        => ['n', 'n', 'n', 'n', 'n', 'n', '='],
    ];

    foreach ($P as $from) {
        foreach ($P as $j => $to) {
            $want = $matrix[$from][$j];
            $got  = ScanPhase::check($from, $to);
            $ok   = ($want === 'y') ? ($got['ok'] === true && $got['noop'] === false)
                  : (($want === '=') ? ($got['ok'] === false && $got['noop'] === true)
                                     : ($got['ok'] === false && $got['noop'] === false));
            check("phase: $from -> $to is '$want'", $ok);
            if ($want !== 'y') {
                // A refusal that says nothing is a refusal nobody can debug.
                check("phase: and the refusal $from -> $to explains itself",
                    is_string($got['why']) && strlen($got['why']) > 20);
            }
        }
    }

    // An unrecognised phase is not a phase, in either direction. This is the
    // downgrade case: a run row written by a NEWER build, read by this one.
    check('phase: an unknown source phase is refused',
        ScanPhase::may('finalising', 'terminal') === false);
    check('phase: an unknown target phase is refused',
        ScanPhase::may('scanning', 'finalising') === false);
    check('phase: and neither is reported as a no-op',
        ScanPhase::check('finalising', 'finalising')['noop'] === false);

    // -- what the chain is for ----------------------------------------------
    check('phase: the chain runs planning -> scanning -> catch-up -> unique -> rollup',
        ScanPhase::next('planning') === 'scanning'
        && ScanPhase::next('scanning') === 'catch-up'
        && ScanPhase::next('catch-up') === 'unique-finalize'
        && ScanPhase::next('unique-finalize') === 'rollup-finalize'
        && ScanPhase::next('rollup-finalize') === null);
    check('phase: cancelling and terminal are not in the chain',
        ScanPhase::next('cancelling') === null && ScanPhase::next('terminal') === null);

    // THE SKIP IS THE POINT. A run with no unique rules still passes through
    // unique-finalize and records that it had nothing to do, because promotion
    // requires both finalizers to have COMPLETED and "completed" must not be
    // satisfiable by never having started.
    $skip = ScanPhase::check('catch-up', 'rollup-finalize');
    check('phase: skipping the unique finalizer is refused', $skip['ok'] === false);
    check('phase: and the refusal names what would have been skipped',
        strpos($skip['why'], 'unique-finalize') !== false);
    check('phase: skipping two phases names both',
        strpos(ScanPhase::check('scanning', 'rollup-finalize')['why'], 'unique-finalize') !== false
        && strpos(ScanPhase::check('scanning', 'rollup-finalize')['why'], 'catch-up') !== false);
    check('phase: going backwards says so',
        strpos(ScanPhase::check('rollup-finalize', 'scanning')['why'], 'backwards') !== false);

    // -- who may work --------------------------------------------------------
    check('phase: a worker may claim while scanning, catching up, and finalizing',
        ScanPhase::mayWork('scanning') && ScanPhase::mayWork('catch-up')
        && ScanPhase::mayWork('unique-finalize') && ScanPhase::mayWork('rollup-finalize'));
    // Planning: the manifest is not frozen, so a claim is a claim against a list
    // that can still grow.
    check('phase: but never while planning', ScanPhase::mayWork('planning') === false);
    // Cancelling: work has stopped being wanted, which is the whole phase.
    check('phase: nor while cancelling', ScanPhase::mayWork('cancelling') === false);
    // Terminal: stated because a resumed browser tab will cheerfully ask a
    // finished run for more work.
    check('phase: nor after it has finished', ScanPhase::mayWork('terminal') === false);
    check('phase: an unrecognised phase may not be worked either',
        ScanPhase::mayWork('finalising') === false);

    check('phase: every phase but terminal is active',
        ScanPhase::isActive('planning') && ScanPhase::isActive('cancelling')
        && ScanPhase::isActive('terminal') === false);

    // -- the nullable terminal ----------------------------------------------
    //
    // `terminal IS NULL` is how every read path asks "is this run still going".
    // A row that carried a terminal state while still working would be read as
    // finished by a query that never looked at the phase.
    foreach ($P as $p) {
        $want = ($p === 'terminal');
        check("phase: $p " . ($want ? 'requires' : 'forbids') . ' a terminal state',
            ScanPhase::consistent($p, 'complete')['ok'] === $want);
        check("phase: $p " . ($want ? 'may not be' : 'is') . ' null-terminal',
            ScanPhase::consistent($p, null)['ok'] === !$want);
    }
    check('phase: an empty string is not a terminal state either',
        ScanPhase::consistent('terminal', '')['ok'] === false);
    check('phase: and a made-up one is refused',
        ScanPhase::consistent('terminal', 'finished')['ok'] === false);
    check('phase: every real terminal state is accepted',
        ScanPhase::consistent('terminal', ScanOutcome::COMPLETE)['ok']
        && ScanPhase::consistent('terminal', ScanOutcome::PARTIAL)['ok']
        && ScanPhase::consistent('terminal', ScanOutcome::CANCELLED)['ok']
        && ScanPhase::consistent('terminal', ScanOutcome::FAILED)['ok']
        && ScanPhase::consistent('terminal', ScanOutcome::EXPIRED)['ok']);
    check('phase: an unrecognised phase is never consistent',
        ScanPhase::consistent('finalising', null)['ok'] === false);

    // -- cancellation --------------------------------------------------------
    //
    // A run that has not started working has nothing in flight and can finish
    // outright. Anything else goes through `cancelling` first, so the epoch bump
    // lands BEFORE the terminal write and a worker mid-evaluation fails its
    // compare-and-set instead of committing into a finished run.
    check('cancel: a planning run finishes outright',
        ScanPhase::cancelTarget('planning') === 'terminal');
    check('cancel: a working run goes through cancelling first',
        ScanPhase::cancelTarget('scanning') === 'cancelling'
        && ScanPhase::cancelTarget('catch-up') === 'cancelling'
        && ScanPhase::cancelTarget('unique-finalize') === 'cancelling'
        && ScanPhase::cancelTarget('rollup-finalize') === 'cancelling');
    check('cancel: cancelling twice is refused rather than repeated',
        ScanPhase::cancelTarget('cancelling') === null);
    check('cancel: a finished run cannot be cancelled',
        ScanPhase::cancelTarget('terminal') === null);
    check('cancel: nor can a run whose phase this build cannot read',
        ScanPhase::cancelTarget('finalising') === null);

    // -- the plan's derivation table, row for row ---------------------------
    //
    // Section 2 of reports/scan-rebuild-plan-2026-08-17.md states eight rows.
    // Each is transcribed here with all five of its columns, so a change that
    // satisfies one prose assertion elsewhere while breaking a row of the
    // specification still fails.
    $base = ['fenced' => true, 'manifestDone' => true, 'blocked' => false, 'truncated' => false,
             'cancelled' => false, 'failed' => false, 'expired' => false,
             'violations' => 0, 'ruleProblems' => 0, 'gaps' => 0, 'labelDegraded' => false];

    $rows = [
        ['fenced coverage, nothing blocking, detail retained',
         [], 'complete', 'complete-through-fence', 'complete', true, ''],
        ['fenced coverage but the detail budget was exceeded',
         ['truncated' => true], 'partial', 'complete-through-fence', 'truncated', false, '_TRUNCATED'],
        ['frozen manifest processed without a reliable fence',
         ['fenced' => false], 'partial', 'manifest-complete', 'complete', false, '_MANIFEST_ONLY'],
        ['manifest-only AND truncated says both',
         ['fenced' => false, 'truncated' => true], 'partial', 'manifest-complete', 'truncated', false,
         '_MANIFEST_ONLY_TRUNCATED'],
        ['any unread or unstable record',
         ['blocked' => true], 'partial', 'partial', 'complete', false, '_INCOMPLETE'],
        ['explicit cancellation',
         ['cancelled' => true], 'cancelled', 'partial', 'complete', false, '_CANCELLED'],
        ['an unrecoverable store, schema or fingerprint failure',
         ['failed' => true], 'failed', 'failed', 'complete', false, '_FAILED'],
        ['abandonment beyond the configured lifetime',
         ['expired' => true], 'expired', 'partial', 'complete', false, '_EXPIRED'],
        // The row that exists so the green tick stays reachable: an event shown
        // by id rather than by name is a worse REPORT, not a worse scan.
        ['non-blocking label degradation only',
         ['labelDegraded' => true], 'complete', 'complete-through-fence', 'complete', true, ''],
    ];

    foreach ($rows as $r) {
        list($what, $over, $terminal, $coverage, $detail, $clean, $suffix) = $r;
        $o = ScanOutcome::derive(array_merge($base, $over));
        check("derive: $what -> $terminal", $o['terminal'] === $terminal);
        check("derive: $what -> coverage $coverage", $o['coverage'] === $coverage);
        check("derive: $what -> detail $detail", $o['detail'] === $detail);
        check("derive: $what -> clean is " . ($clean ? 'allowed' : 'refused'),
            ScanOutcome::mayClaimClean($o) === $clean);
        check("derive: $what -> suffix '" . $suffix . "'", ScanOutcome::suffix($o) === $suffix);
        // Whatever the row, the run must be describable in a sentence. An
        // outcome with no `why` is one a UI has to invent wording for.
        check("derive: $what explains itself", is_string($o['why']) && $o['why'] !== '');
    }

    // Clean is a claim about the PROJECT and is refused by anything found, even
    // on the one row that permits it.
    check('derive: a fenced complete run with violations is not clean',
        ScanOutcome::mayClaimClean(ScanOutcome::derive(array_merge($base, ['violations' => 1]))) === false);
    check('derive: nor with a rule nobody could configure',
        ScanOutcome::mayClaimClean(ScanOutcome::derive(array_merge($base, ['ruleProblems' => 1]))) === false);

    // Every terminal state ScanOutcome can produce must be one ScanPhase will
    // store. The two files are edited separately and this is what keeps a new
    // terminal state from being written into a column that rejects it.
    foreach ($rows as $r) {
        $o = ScanOutcome::derive(array_merge($base, $r[1]));
        check('derive: ' . $o['terminal'] . ' is a terminal state the phase machine accepts',
            ScanPhase::consistent(ScanPhase::TERMINAL, $o['terminal'])['ok'] === true);
    }

    // ======================================================================
    // ScanPlanner: naming a rule, and hashing a configuration
    // ======================================================================

    $threw = function (callable $f) {
        try { $f(); } catch (\Throwable $e) { return true; }
        return false;
    };

    // -- canonical encoding --------------------------------------------------
    //
    // Every value carries its type, so nothing that PHP treats as loosely equal
    // encodes the same way. A fingerprint built on an untagged encoding would
    // report a rule whose `expectedIds` went from "5" to 5 as unchanged.
    $c = function ($v) { return ScanPlanner::canonical($v); };
    check('canonical: 1, "1", 1.0 and true are four different values',
        count(array_unique([$c(1), $c('1'), $c(1.0), $c(true)])) === 4);
    check('canonical: null, false, "" and 0 are four more',
        count(array_unique([$c(null), $c(false), $c(''), $c(0)])) === 4);

    // Key ORDER in a map is not information; item order in a list is.
    check('canonical: a map does not depend on the order its keys were written',
        $c(['a' => 1, 'b' => 2]) === $c(['b' => 2, 'a' => 1]));
    check('canonical: but a list does depend on its order',
        $c([1, 2]) !== $c([2, 1]));
    check('canonical: a list and a map with the same contents differ',
        $c([1, 2]) !== $c([1 => 1, 0 => 2]));
    check('canonical: nesting is preserved',
        $c([['a']]) !== $c(['a']) && $c([['a']]) !== $c([['a'], []]));

    // THE LENGTH PREFIX IS LOAD-BEARING. Without it, ['ab','c'] and ['a','bc']
    // concatenate to the same bytes - a collision anyone could construct by
    // naming two fields.
    check('canonical: strings cannot be re-split into a different list',
        $c(['ab', 'c']) !== $c(['a', 'bc']));

    // THE L-01 REGRESSION, restated for the fingerprint. Two DISTINCT invalid
    // UTF-8 byte sequences must stay distinct. json_encode refuses them
    // outright, and its substitute flag collapses each to U+FFFD - a lossy,
    // data-constructible collision, which is precisely what L-01 was.
    // One invalid byte each, differing only in which one - the smallest form of
    // the collision, and the one a Latin-1 import produces.
    $bad1 = "x\xA0y";
    $bad2 = "x\xA1y";
    check('canonical: two different invalid UTF-8 sequences stay different',
        $c($bad1) !== $c($bad2));
    check('canonical: json_encode could not have encoded either at all',
        json_encode($bad1) === false && json_encode($bad2) === false);
    check('canonical: and substituting collapses both to the same replacement',
        json_encode($bad1, JSON_INVALID_UTF8_SUBSTITUTE)
        === json_encode($bad2, JSON_INVALID_UTF8_SUBSTITUTE));

    check('canonical: a NUL byte is a value like any other',
        $c("a\0b") !== $c('ab') && $c("a\0b") !== $c("a\0\0b"));
    check('canonical: floats keep enough precision to differ where they differ',
        $c(0.1 + 0.2) !== $c(0.3));
    check('canonical: NAN and INF are named rather than printed',
        $c(NAN) === 'dNAN' && $c(INF) === 'dINF' && $c(-INF) === 'd-INF');

    // An object would be silently cast to an array and hash its private state.
    check('canonical: an object is refused rather than guessed at',
        $threw(function () { ScanPlanner::canonical(new \stdClass()); }));

    // -- rule identity -------------------------------------------------------
    $settings = ['type' => 'single', 'fields' => ['a'], 'algorithm' => 'mod37,36',
                 'source' => 'field'];
    $ann      = ['type' => 'required', 'fields' => ['dob', 'sex']];

    // THE ORDINAL BUG, stated as a test. Two rule lists differing only in order
    // must name the same rule the same way; the ordinal named it 1 and 2.
    $other = ['type' => 'constraint', 'fields' => ['z'], 'assert' => '[z] > 0'];
    $idsA = ScanPlanner::identifyAll([$settings, $other], 2);
    $idsB = ScanPlanner::identifyAll([$other, $settings], 2);
    check('identity: reordering the rule list does not rename a rule',
        $idsA[0]['source_id'] === $idsB[1]['source_id']
        && $idsA[1]['source_id'] === $idsB[0]['source_id']);
    check('identity: and the two rules do not share a name',
        $idsA[0]['source_id'] !== $idsA[1]['source_id']);

    // Identical siblings: same content, so the same stem, distinguished by
    // occurrence - and the SAME occurrence next time, because list order among
    // identical rules is itself derived from content.
    $twins = ScanPlanner::identifyAll([$settings, $settings], 2);
    check('identity: two identical rules get distinct names',
        $twins[0]['source_id'] !== $twins[1]['source_id']);
    check('identity: numbered from zero, in order',
        substr($twins[0]['source_id'], -2) === ':0'
        && substr($twins[1]['source_id'], -2) === ':1');
    check('identity: and re-running gives the same two names',
        ScanPlanner::identifyAll([$settings, $settings], 2) == $twins);

    // A stored id wins whenever there is one, because it is the only form that
    // survives an EDIT.
    $withUid = array_merge($settings, ['uid' => 'ab12-cd34']);
    check('identity: a stored rule id is used when present',
        ScanPlanner::identify($withUid, 'settings')['source_id'] === 'uid:ab12-cd34');
    $edited = array_merge($withUid, ['algorithm' => 'mod11,10']);
    check('identity: and survives editing the rule, which is the point of it',
        ScanPlanner::identify($edited, 'settings')['source_id']
        === ScanPlanner::identify($withUid, 'settings')['source_id']);
    check('identity: while the revision records that it changed',
        ScanPlanner::revision($edited) !== ScanPlanner::revision($withUid));
    // The honest limitation of the fallback, asserted rather than left implied.
    check('identity: without a stored id, an edit DOES rename the settings rule',
        ScanPlanner::identify(array_merge($settings, ['algorithm' => 'mod11,10']), 'settings')['source_id']
        !== ScanPlanner::identify($settings, 'settings')['source_id']);
    check('identity: a stored id ending in digits is not mistaken for an occurrence',
        ScanPlanner::identify(array_merge($settings, ['uid' => 'rule:7']), 'settings')['stem']
        === 'uid:rule:7');

    // An annotation rule is named by WHERE it is written, so editing its
    // pattern keeps the name and moves the revision - the split that lets a
    // report say "this rule changed" instead of "this rule vanished".
    $annEdit = array_merge($ann, ['when' => '[age] > 18']);
    check('identity: editing an annotation rule keeps its name',
        ScanPlanner::identify($annEdit, 'annotation')['source_id']
        === ScanPlanner::identify($ann, 'annotation')['source_id']);
    check('identity: and changes its revision',
        ScanPlanner::identify($annEdit, 'annotation')['revision']
        !== ScanPlanner::identify($ann, 'annotation')['revision']);
    check('identity: field order within an annotation rule is not information',
        ScanPlanner::identify(['type' => 'required', 'fields' => ['sex', 'dob']], 'annotation')['source_id']
        === ScanPlanner::identify($ann, 'annotation')['source_id']);
    check('identity: but WHICH fields is, because that is where the rule lives',
        ScanPlanner::identify(['type' => 'required', 'fields' => ['dob']], 'annotation')['source_id']
        !== ScanPlanner::identify($ann, 'annotation')['source_id']);
    check('identity: a settings rule gaining a field is still the same rule',
        ScanPlanner::identify(array_merge($settings, ['fields' => ['a', 'b']]), 'settings')['source_id']
        === ScanPlanner::identify($settings, 'settings')['source_id']);
    check('identity: two tag families on the same fields do not collide',
        ScanPlanner::identify(['type' => 'unique', 'fields' => ['dob', 'sex']], 'annotation')['source_id']
        !== ScanPlanner::identify($ann, 'annotation')['source_id']);
    check('identity: a settings rule and an annotation rule never share a name',
        ScanPlanner::identify($ann, 'settings')['source_id']
        !== ScanPlanner::identify($ann, 'annotation')['source_id']);
    check('identity: a rule type this build does not know still gets a name',
        ScanPlanner::identify(['type' => 'range', 'fields' => ['x']], 'annotation')['source_id'] !== '');

    // Authoring metadata is for people. Renaming a rule must not invalidate a
    // 100,000-record baseline.
    foreach (['label', 'note', 'message'] as $k) {
        $renamed = array_merge($ann, [$k => 'a friendlier wording']);
        check("identity: changing '$k' changes neither name nor revision",
            ScanPlanner::identify($renamed, 'annotation') == ScanPlanner::identify($ann, 'annotation'));
    }

    // -- fingerprint ---------------------------------------------------------
    $spec = [
        'engine'    => '1.8.19',
        'rules'     => [['id' => 'ann:x:required:0', 'rev' => str_repeat('a', 64)]],
        'ownership' => ['dob' => 'demographics'],
        'structure' => ['longitudinal' => false, 'events' => [], 'repeating' => []],
        'choices'   => ['sex' => ['0' => 'F', '1' => 'M']],
        'gapPolicy' => 'separate',
        'valueMode' => 'locations',
    ];
    $fp = ScanPlanner::fingerprint($spec);
    check('fingerprint: is 64 hex characters', preg_match('/^[0-9a-f]{64}\z/', $fp) === 1);
    check('fingerprint: does not depend on the order the inputs were written',
        ScanPlanner::fingerprint(array_reverse($spec, true)) === $fp);

    // EVERY required input must be able to change it. An input that is accepted
    // and then ignored is worse than one that is missing, because it looks
    // covered.
    foreach (array_keys($spec) as $k) {
        $moved = $spec;
        $moved[$k] = is_array($moved[$k]) ? array_merge($moved[$k], ['zzz' => 1]) : ($moved[$k] . '-x');
        check("fingerprint: changing '$k' changes it",
            ScanPlanner::fingerprint($moved) !== $fp);
    }
    // Missing is loud, because a fingerprint that omits an input fails to
    // notice the change it exists to notice, and fails quietly.
    foreach (array_keys($spec) as $k) {
        $short = $spec;
        unset($short[$k]);
        check("fingerprint: omitting '$k' is refused",
            $threw(function () use ($short) { ScanPlanner::fingerprint($short); }));
    }
    // Wording may never enter. If it did, fixing a typo would force every
    // project to re-scan, so typos would not get fixed.
    foreach (['messages', 'catalog', 'labels', 'wording'] as $k) {
        check("fingerprint: '$k' is refused as an input",
            $threw(function () use ($spec, $k) {
                ScanPlanner::fingerprint(array_merge($spec, [$k => ['a' => 'b']]));
            }));
    }
    // An unrecognised input COUNTS rather than being dropped: the safe
    // direction for something nobody anticipated is that it invalidates.
    check('fingerprint: an input this build did not expect still counts',
        ScanPlanner::fingerprint(array_merge($spec, ['futureThing' => 1])) !== $fp);

    check('fingerprint: matching is exact',
        ScanPlanner::fingerprintMatches($fp, $fp) === true
        && ScanPlanner::fingerprintMatches($fp, str_repeat('0', 64)) === false);
    check('fingerprint: a missing or malformed stored value never matches',
        ScanPlanner::fingerprintMatches(null, $fp) === false
        && ScanPlanner::fingerprintMatches('', '') === false
        && ScanPlanner::fingerprintMatches('abc', 'abc') === false);

    // -- planning refuses before a run exists --------------------------------
    //
    // The rest of plan() runs against a real record source and a real store in
    // tests/mysql/run.php - a planner judged by a fake source would only prove
    // that the fake agrees with it. What belongs here is the one refusal that
    // needs neither: an installation that cannot list records at all.
    //
    // A refused start costs a message. An abandoned run costs the project its
    // scan slot until something expires it, so every refusal that CAN happen
    // before startRun() does.
    $planner = new ScanPlanner(new ArrayScanStore(2), 'k');
    $noSource = $planner->plan(1, ['rules' => [['type' => 'required', 'fields' => ['a']]]]);
    check('plan: an installation that cannot list records is refused',
        $noSource['ok'] === false && $noSource['busy'] === false);
    check('plan: and told why, in terms of what it cannot do',
        strpos($noSource['why'], 'without exporting it') !== false);
    check('plan: refusing is not the same as being busy',
        $noSource['busy'] === false && $noSource['run'] === null);

    // ======================================================================
    // WorkBudget: how much a request may take on, and when it must stop
    // ======================================================================

    // -- reading the two ini values ------------------------------------------
    //
    // Both have a value that means "no limit" and reads as a very small number
    // if taken literally. A memory limit of minus one byte refuses every batch;
    // a time budget of zero seconds stops the scan before it starts. Neither
    // failure announces itself - the scan simply never progresses.
    check('budget: -1 memory means no limit, not a limit of -1',
        WorkBudget::bytes('-1') === null);
    check('budget: 0 seconds means no limit, not no time',
        WorkBudget::seconds('0') === null && WorkBudget::seconds(0) === null);
    check('budget: suffixes are bytes, kilobytes, megabytes, gigabytes',
        WorkBudget::bytes('128M') === 134217728
        && WorkBudget::bytes('512K') === 524288
        && WorkBudget::bytes('1G') === 1073741824
        && WorkBudget::bytes('1024') === 1024);
    check('budget: case and spacing do not change the answer',
        WorkBudget::bytes('128m') === WorkBudget::bytes('128M')
        && WorkBudget::bytes(' 128M ') === WorkBudget::bytes('128M'));
    check('budget: an unreadable value is unknown rather than zero',
        WorkBudget::bytes('lots') === null && WorkBudget::bytes('') === null
        && WorkBudget::seconds('soon') === null);
    check('budget: a real time limit is read as itself',
        WorkBudget::seconds('30') === 30 && WorkBudget::seconds(30) === 30);

    // -- what the two modes aim at -------------------------------------------
    $browser = new WorkBudget(['mode' => 'browser', 'memoryLimit' => '128M',
                               'timeLimit' => null, 'startedAt' => 1000.0]);
    $cron    = new WorkBudget(['mode' => 'cron', 'memoryLimit' => '128M',
                               'timeLimit' => null, 'startedAt' => 1000.0]);
    check('budget: a browser batch aims at a few seconds', $browser->target() === 3.0);
    check('budget: a cron batch aims at much longer, because nobody is watching',
        $cron->target() === 20.0);
    // The remainder of the limit is what COMMITS the batch. A batch that ran out
    // of time before committing did its work for nothing.
    $tight = new WorkBudget(['mode' => 'cron', 'memoryLimit' => '128M', 'timeLimit' => 10,
                             'startedAt' => 1000.0]);
    check('budget: and never aims past the share of the limit it may spend',
        $tight->target() === 8.0);

    // A run's first batch has no measurement behind it, so it is a guess, and a
    // guess that is too large is an OOM while one that is too small costs a
    // round trip.
    check('budget: the first batch is deliberately small', $browser->claim() === 25);

    // -- sizing from what the last batch actually cost ------------------------
    $b = new WorkBudget(['mode' => 'cron', 'memoryLimit' => null, 'timeLimit' => null,
                         'min' => 1, 'max' => 500, 'first' => 25, 'startedAt' => microtime(true)]);
    // 25 records in 5s is 0.2s each, so 20s of work is 100 - but growth is
    // capped at double, because a first batch that hit a run of empty records
    // measures a cost no later batch repeats.
    $n1 = $b->next(['records' => 25, 'seconds' => 5.0, 'memoryDelta' => 0]);
    check('budget: a fast batch grows the next one', $n1['claim'] > 25);
    check('budget: but never by more than double in one step', $n1['claim'] === 50);
    // A slow batch shrinks immediately and without a cap: shrinking is safe.
    $b2 = new WorkBudget(['mode' => 'browser', 'memoryLimit' => null, 'timeLimit' => null,
                          'min' => 1, 'max' => 500, 'first' => 200, 'startedAt' => microtime(true)]);
    $n2 = $b2->next(['records' => 200, 'seconds' => 60.0, 'memoryDelta' => 0]);
    check('budget: a slow batch shrinks the next one at once', $n2['claim'] === 10);

    $b3 = new WorkBudget(['mode' => 'cron', 'memoryLimit' => null, 'timeLimit' => null,
                          'min' => 5, 'max' => 40, 'first' => 20, 'startedAt' => microtime(true)]);
    check('budget: the configured maximum is respected however fast the batch was',
        $b3->next(['records' => 20, 'seconds' => 0.001, 'memoryDelta' => 0])['claim'] === 40);
    $b4 = new WorkBudget(['mode' => 'browser', 'memoryLimit' => null, 'timeLimit' => null,
                          'min' => 5, 'max' => 40, 'first' => 20, 'startedAt' => microtime(true)]);
    check('budget: and the configured minimum however slow',
        $b4->next(['records' => 20, 'seconds' => 600.0, 'memoryDelta' => 0])['claim'] === 5);

    // -- memory has the last word --------------------------------------------
    //
    // Time only makes a batch slow. Memory ends the request with no report at
    // all, which is the one failure this module cannot narrate.
    $mem = new WorkBudget(['mode' => 'cron', 'memoryLimit' => 100 * 1024 * 1024,
                           'timeLimit' => null, 'min' => 1, 'max' => 500, 'first' => 10,
                           'startedAt' => microtime(true)]);
    // 40 MiB used of 100, so the usable ceiling is 60 MiB and 20 MiB remains.
    // At 1 MiB a record that is 20 records, not the 100 the clock would allow.
    $nm = $mem->next(['records' => 10, 'seconds' => 2.0, 'usage' => 40 * 1024 * 1024,
                      'memoryDelta' => 10 * 1024 * 1024]);
    check('budget: a memory-hungry batch is capped by memory, not by the clock',
        $nm['claim'] === 20);

    // ONE RECORD ALONE, never zero. A record too large to examine beside others
    // may still be examinable by itself, and excluding it without trying would
    // be a guess recorded as a fact.
    $huge = new WorkBudget(['mode' => 'cron', 'memoryLimit' => 100 * 1024 * 1024,
                            'timeLimit' => null, 'min' => 4, 'max' => 500, 'first' => 10,
                            'startedAt' => microtime(true)]);
    $nh = $huge->next(['records' => 10, 'seconds' => 1.0, 'usage' => 55 * 1024 * 1024,
                       'memoryDelta' => 50 * 1024 * 1024]);
    check('budget: an oversized record is examined on its own', $nh['claim'] === 1);
    check('budget: which overrides even the configured minimum', $nh['claim'] < 4);
    check('budget: and the reason is stated rather than left to be inferred',
        strpos($nh['why'], 'one at a time') !== false);

    // Past the reserve there is no batch small enough to be worth starting.
    $full = new WorkBudget(['mode' => 'cron', 'memoryLimit' => 100 * 1024 * 1024,
                            'timeLimit' => null, 'min' => 1, 'max' => 500, 'first' => 10,
                            'startedAt' => microtime(true)]);
    $nf = $full->next(['records' => 10, 'seconds' => 1.0, 'usage' => 95 * 1024 * 1024,
                       'memoryDelta' => 1024]);
    check('budget: beyond the reserve the request stops rather than claiming again',
        $nf['stop'] === 'memory');
    check('budget: and says the scan continues, not that it failed',
        strpos($nf['why'], 'continues in the next one') !== false);

    // -- when to stop asking for more ----------------------------------------
    $st = new WorkBudget(['mode' => 'browser', 'memoryLimit' => 100 * 1024 * 1024,
                          'timeLimit' => null, 'startedAt' => 1000.0]);
    check('budget: before the deadline there is time for another batch',
        $st->mustStop(1002.0, 1024) === null);
    check('budget: after it there is not', $st->mustStop(1003.5, 1024) === 'time');
    check('budget: the reserve is 40 per cent of the limit, not the last byte of it',
        $st->mustStop(1000.0, 59 * 1024 * 1024) === null
        && $st->mustStop(1000.0, 61 * 1024 * 1024) === 'memory');

    // A limit nobody could read must not stop a healthy scan: that is the same
    // mistake as a failed read judged as a blank.
    $unknown = new WorkBudget(['mode' => 'cron', 'memoryLimit' => 'lots', 'timeLimit' => 'soon',
                               'startedAt' => 1000.0]);
    check('budget: an unknown memory limit never halts a scan',
        $unknown->mustStop(1000.0, PHP_INT_MAX) === null);
    check('budget: an unknown time limit still leaves the mode target in force',
        $unknown->target() === 20.0 && $unknown->mustStop(1021.0, 0) === 'time');

    // ======================================================================
    // ScanWorker: claim, prove it held still, look, commit - or commit nothing
    // ======================================================================

    /**
     * A version source a test can move.
     *
     * The race the stable-read protocol exists for is an edit landing BETWEEN
     * the worker's two version reads, and that cannot be arranged against a real
     * change log without racing the test itself. Here it is arranged exactly:
     * `bump` names a record that changes on every second read, which is the
     * worst case rather than a convenient one.
     */
    class Versions implements RecordVersions
    {
        public $v = [];          // record => current version
        public $bumpOnRead = []; // record => bump its version on every read
        public $reads = 0;

        public function versions(array $ids)
        {
            $this->reads++;
            $out = [];
            foreach ($ids as $id) {
                if (isset($this->bumpOnRead[$id])) {
                    $this->v[$id] = (string) (((int) (isset($this->v[$id]) ? $this->v[$id] : 0)) + 1);
                }
                $out[(string) $id] = isset($this->v[$id]) ? $this->v[$id] : null;
            }
            return $out;
        }
    }

    /**
     * A duplicate finalizer a test can hold at whatever answer it needs.
     *
     * The real one is 400 lines of SQL and is checked against four servers. What
     * belongs HERE is the worker's behaviour around it: that a phase with
     * nothing to do is still entered, and that a finalizer nobody configured
     * stops the run rather than being walked past.
     */
    class Finalizer implements DuplicateFinalizer
    {
        public $calls = 0;
        public $rounds = 1;       // how many steps before it reports itself done
        public $emitted = 0;
        public $collisions = 0;

        public function step($generationId, $limit = 500)
        {
            $this->calls++;
            $done = $this->calls >= $this->rounds;
            return ['done' => $done, 'groups' => 0, 'verified' => 0,
                    'emitted' => $done ? $this->emitted : 0, 'published' => $done ? 1 : 0,
                    'collisions' => $done ? $this->collisions : 0, 'why' => null];
        }
    }

    /** A run with a frozen manifest, ready to be worked. */
    $fixture = function ($ids, $pid = 800) {
        $store = new ArrayScanStore(2);
        $r = $store->startRun($pid, ['created_by' => 'alice']);
        $runId = (int) $r['run']['run_id'];
        $recs = [];
        foreach ($ids as $id) {
            $recs[] = ['id_bin' => $id, 'hash' => hash('sha256', $id, true), 'dag' => null];
        }
        $store->writeManifest($runId, $recs);
        return [$store, $runId];
    };
    $reader = function ($ids) {
        $data = [];
        foreach ($ids as $id) $data[$id] = ['1' => ['x' => 'v']];
        return ['ok' => true, 'data' => $data, 'why' => null];
    };
    $finder = function ($n) {
        return function ($id, $node) use ($n) {
            $out = [];
            for ($i = 0; $i < $n; $i++) {
                $out[] = ['generation_id' => 1, 'identity' => hash('sha256', $id . $i, true),
                          'seq' => 1, 'record_hash' => hash('sha256', $id, true),
                          'record_id_bin' => $id, 'host_form' => 'f', 'field' => 'x',
                          'rule_source_id' => 'r', 'rule_revision' => str_repeat('c', 64),
                          'check_type' => 'required', 'reason_code' => 'required-blank'];
            }
            return ['findings' => $out, 'bytes' => 10, 'contexts' => 1, 'why' => null];
        };
    };
    $wide = function () { return new WorkBudget(['mode' => 'cron', 'memoryLimit' => null,
        'timeLimit' => null, 'min' => 1, 'max' => 100, 'first' => 10,
        'startedAt' => microtime(true)]); };
    $fin = function () { return new Finalizer(); };

    // -- the ordinary case ---------------------------------------------------
    list($store, $runId) = $fixture(['A', 'B', 'C', 'D', 'E']);
    $ver = new Versions();
    $w = new ScanWorker($store, ['fence' => $ver, 'read' => $reader, 'evaluate' => $finder(1),
        'budget' => $wide(), 'owner' => 'w1', 'attempts' => 3, 'finalizer' => $fin()]);
    $res = $w->work(800, $runId);
    check('worker: every record is examined', $res['worked'] === 5);
    check('worker: and every finding kept', $res['findings'] === 5);
    check('worker: the manifest completes', $store->manifestComplete($runId) === true);
    check('worker: and the run walks to the end of the phase chain',
        $res['phase'] === 'rollup-finalize' && $res['done'] === true);
    check('worker: progress matches the manifest, never exceeds it',
        (int) $store->run(800, $runId)['manifest_done'] === 5);
    // The versions are read once before and once after the data, every batch.
    check('worker: each batch proves stability with two reads, not one',
        $ver->reads >= 2 && $ver->reads % 2 === 0);

    // -- an edit landing between the two version reads -----------------------
    //
    // THE RACE THE PROTOCOL EXISTS FOR. Without it this record is examined half
    // in its old state and half in its new one, and the finding describes a
    // state the project was never in.
    list($store, $runId) = $fixture(['A', 'B', 'C']);
    $ver = new Versions();
    $ver->v = ['A' => '1', 'B' => '1', 'C' => '1'];
    $moved = false;
    $racing = function ($ids) use (&$ver, &$moved, $reader) {
        if (!$moved) { $ver->v['B'] = '2'; $moved = true; }   // edited during the read
        return $reader($ids);
    };
    $w = new ScanWorker($store, ['fence' => $ver, 'read' => $racing, 'evaluate' => $finder(1),
        'budget' => $wide(), 'owner' => 'w1', 'attempts' => 3, 'finalizer' => $fin()]);
    $res = $w->work(800, $runId);
    check('worker: a record edited during the read is requeued, not reported',
        $res['requeued'] === 1);
    check('worker: and is examined on the next pass instead', $res['worked'] === 3);
    check('worker: so the manifest still completes', $store->manifestComplete($runId) === true);
    check('worker: with no finding attributed to a state that never existed',
        $res['findings'] === 3);

    // -- a record that will not hold still -----------------------------------
    list($store, $runId) = $fixture(['A', 'HOT']);
    $ver = new Versions();
    $ver->bumpOnRead = ['HOT' => true];
    $w = new ScanWorker($store, ['fence' => $ver, 'read' => $reader, 'evaluate' => $finder(1),
        'budget' => $wide(), 'owner' => 'w1', 'attempts' => 3, 'finalizer' => $fin()]);
    $res = $w->work(800, $runId);
    check('worker: a record edited on every look is eventually reported, not retried forever',
        $res['blocked'] === 1);
    check('worker: while every other record is still examined', $res['worked'] === 1);
    // A blocking exclusion is what stops the run claiming it covered the
    // project. The run reaching a terminal state is not the same as a clean one.
    check('worker: and the run can still finish rather than waiting forever',
        $store->manifestComplete($runId) === true && $res['done'] === true);

    // -- a record deleted mid-run --------------------------------------------
    //
    // Asked for and not returned. Without a terminal state for this the manifest
    // could never complete and the run would hold the project's scan slot until
    // something expired it.
    list($store, $runId) = $fixture(['A', 'GONE']);
    $partial = function ($ids) {
        $data = [];
        foreach ($ids as $id) {
            if ($id === 'GONE') continue;
            $data[$id] = ['1' => ['x' => 'v']];
        }
        return ['ok' => true, 'data' => $data, 'why' => null];
    };
    $w = new ScanWorker($store, ['fence' => new Versions(), 'read' => $partial,
        'evaluate' => $finder(1), 'budget' => $wide(), 'owner' => 'w1', 'attempts' => 2, 'finalizer' => $fin()]);
    $res = $w->work(800, $runId);
    check('worker: a record that vanished mid-run reaches a terminal state',
        $store->manifestComplete($runId) === true);
    check('worker: counted as blocking rather than as examined',
        $res['blocked'] === 1 && $res['worked'] === 1);

    // -- a read that fails outright ------------------------------------------
    //
    // A FAILED READ IS NOT AN EMPTY ONE. Committing these as examined-and-clean
    // is the exact mistake this module exists to prevent.
    list($store, $runId) = $fixture(['A', 'B']);
    $broken = function ($ids) {
        return ['ok' => false, 'data' => [], 'why' => 'the export timed out'];
    };
    $w = new ScanWorker($store, ['fence' => new Versions(), 'read' => $broken,
        'evaluate' => $finder(1), 'budget' => $wide(), 'owner' => 'w1', 'attempts' => 3, 'finalizer' => $fin()]);
    $res = $w->work(800, $runId);
    check('worker: a failed read commits nothing', $res['worked'] === 0
        && (int) $store->run(800, $runId)['detail_rows'] === 0);
    check('worker: and does not mark the records examined',
        $store->manifestComplete($runId) === false);

    // -- an evaluator that throws --------------------------------------------
    list($store, $runId) = $fixture(['A', 'BAD', 'C']);
    $throwing = function ($id, $node) use ($finder) {
        if ($id === 'BAD') throw new \RuntimeException('rule engine fell over');
        $f = $finder(1);
        return $f($id, $node);
    };
    $w = new ScanWorker($store, ['fence' => new Versions(), 'read' => $reader,
        'evaluate' => $throwing, 'budget' => $wide(), 'owner' => 'w1', 'attempts' => 3, 'finalizer' => $fin()]);
    $res = $w->work(800, $runId);
    check('worker: one record that cannot be examined does not lose the batch',
        $res['worked'] === 2 && $res['findings'] === 2);
    check('worker: it is reported as unexamined rather than as clean',
        $res['blocked'] === 1);
    check('worker: and the throw never reaches the request', $res['ok'] === true);

    // -- cancellation beats an in-flight worker ------------------------------
    list($store, $runId) = $fixture(['A', 'B', 'C']);
    $cancelling = function ($id, $node) use (&$store, $runId, $finder) {
        $store->cancel(800, $runId, 'admin');    // arrives mid-evaluation
        $f = $finder(1);
        return $f($id, $node);
    };
    $w = new ScanWorker($store, ['fence' => new Versions(), 'read' => $reader,
        'evaluate' => $cancelling, 'budget' => $wide(), 'owner' => 'w1', 'attempts' => 3, 'finalizer' => $fin()]);
    $res = $w->work(800, $runId);
    check('worker: a cancelled worker fails its fence rather than committing',
        $res['ok'] === false && $res['stop'] === 'fenced');
    check('worker: and everything it had buffered is discarded',
        (int) $store->run(800, $runId)['detail_rows'] === 0
        && (int) $store->run(800, $runId)['manifest_done'] === 0);
    check('worker: it says what happened rather than reporting success',
        strpos($res['why'], 'cancelled or taken over') !== false);

    // -- the configuration moved underneath the run --------------------------
    list($store, $runId) = $fixture(['A']);
    $fpNow = str_repeat('a', 64);
    $w = new ScanWorker($store, ['fence' => new Versions(), 'read' => $reader,
        'evaluate' => $finder(1), 'budget' => $wide(), 'owner' => 'w1', 'attempts' => 3,
        'fingerprint' => $fpNow]);
    $res = $w->work(800, $runId);
    check('worker: a run whose rules changed is stopped, not continued',
        $res['ok'] === false);
    check('worker: and ends terminally rather than being left mid-phase',
        $store->run(800, $runId)['phase'] === 'terminal'
        && $store->run(800, $runId)['terminal'] === 'failed');
    check('worker: releasing the project slot for the run that must replace it',
        $store->startRun(800, [])['ok'] === true);

    // -- the privacy policy tightened ----------------------------------------
    //
    // The run stores the policy it began under. Continuing would keep writing
    // value previews the project has just decided it does not want.
    list($store, $runId) = $fixture(['A'], 801);
    $w = new ScanWorker($store, ['fence' => new Versions(), 'read' => $reader,
        'evaluate' => $finder(1), 'budget' => $wide(), 'owner' => 'w1', 'attempts' => 3,
        'policyRevision' => 9]);
    $res = $w->work(801, $runId);
    check('worker: a tightened privacy policy stops the run at once', $res['ok'] === false);
    check('worker: and the reason names the settings rather than a fault',
        strpos($res['why'], 'privacy settings') !== false);
    check('worker: nothing further was written',
        (int) $store->run(801, $runId)['detail_rows'] === 0);

    // -- what a worker is told about a run that is not its own ---------------
    list($store, $runId) = $fixture(['A'], 802);
    $w = new ScanWorker($store, ['fence' => new Versions(), 'read' => $reader,
        'evaluate' => $finder(1), 'budget' => $wide(), 'owner' => 'w1']);
    $wrong = $w->work(803, $runId);      // right run id, wrong project
    check('worker: a run id from another project resolves to nothing',
        $wrong['ok'] === false);
    check('worker: worded identically to a run that does not exist, so it is not an oracle',
        $wrong['why'] === $w->work(802, 999999)['why']);

    // -- the budget stops the request, not the run ---------------------------
    list($store, $runId) = $fixture(['A', 'B', 'C']);
    $spent = new WorkBudget(['mode' => 'browser', 'memoryLimit' => null, 'timeLimit' => null,
                             'startedAt' => microtime(true) - 3600]);
    $w = new ScanWorker($store, ['fence' => new Versions(), 'read' => $reader,
        'evaluate' => $finder(1), 'budget' => $spent, 'owner' => 'w1', 'attempts' => 3]);
    $res = $w->work(800, $runId);
    check('worker: a request out of time claims nothing', $res['worked'] === 0
        && $res['stop'] === 'time');
    check('worker: the run is left workable rather than finished',
        $res['done'] === false && $store->run(800, $runId)['phase'] === 'scanning');
    check('worker: and says the scan continues rather than that it failed',
        strpos($res['why'], 'continues in the next one') !== false);

    // -- the duplicate phase is entered, not skipped -------------------------
    //
    // A project with no unique rules still passes through it. "We checked and
    // there were no duplicates" and "nobody checked" must not be the same stored
    // fact, which is the whole reason the phase chain forbids skipping.
    list($store, $runId) = $fixture(['A', 'B']);
    $ff = new Finalizer();
    $ff->rounds = 3;                     // needs several bounded steps to settle
    $w = new ScanWorker($store, ['fence' => new Versions(), 'read' => $reader,
        'evaluate' => $finder(0), 'budget' => $wide(), 'owner' => 'w1', 'attempts' => 3,
        'finalizer' => $ff]);
    $res = $w->work(800, $runId);
    check('worker: the duplicate finalizer is run even when there is nothing to find',
        $ff->calls === 3);
    check('worker: and the run only moves on once it says it is settled',
        $res['phase'] === 'rollup-finalize' && $res['done'] === true);

    // A finalizer that could not decide a group contributes a blocking
    // exclusion, which is what stops the run claiming it covered the project.
    list($store, $runId) = $fixture(['A']);
    $fc = new Finalizer();
    $fc->collisions = 2;
    $w = new ScanWorker($store, ['fence' => new Versions(), 'read' => $reader,
        'evaluate' => $finder(0), 'budget' => $wide(), 'owner' => 'w1', 'attempts' => 3,
        'finalizer' => $fc]);
    $res = $w->work(800, $runId);
    check('worker: a group the finalizer could not decide counts as blocking',
        $res['blocked'] === 2);

    // AND A FINALIZER NOBODY CONFIGURED STOPS THE RUN. Walking past it would
    // turn a wiring mistake into a report that silently contains no duplicate
    // findings at all, which reads exactly like a project that has none.
    list($store, $runId) = $fixture(['A']);
    $w = new ScanWorker($store, ['fence' => new Versions(), 'read' => $reader,
        'evaluate' => $finder(0), 'budget' => $wide(), 'owner' => 'w1', 'attempts' => 3]);
    $res = $w->work(800, $runId);
    check('worker: with no way to decide duplicates the run stops rather than advancing',
        $res['ok'] === false && $res['stop'] === 'unconfigured');
    check('worker: and says so, rather than reporting that it found none',
        strpos($res['why'], 'reporting that it found none') !== false);
    check('worker: the run is left where it stopped, not finished',
        $store->run(800, $runId)['phase'] === 'unique-finalize');

    // -- a finished run takes no more work -----------------------------------
    list($store, $runId) = $fixture(['A']);
    $store->finish($runId, ScanOutcome::derive(['fenced' => true, 'manifestDone' => true]));
    $w = new ScanWorker($store, ['fence' => new Versions(), 'read' => $reader,
        'evaluate' => $finder(1), 'budget' => $wide(), 'owner' => 'w1']);
    $res = $w->work(800, $runId);
    check('worker: a finished run is not reopened by a resumed browser tab',
        $res['worked'] === 0 && $res['done'] === true && $res['phase'] === 'terminal');
}

namespace {
    echo "scan_worker_php: $n checks, $fail failure(s)\n";
    exit($fail ? 1 : 0);
}
