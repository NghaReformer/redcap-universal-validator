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
}

namespace {
    echo "scan_worker_php: $n checks, $fail failure(s)\n";
    exit($fail ? 1 : 0);
}
