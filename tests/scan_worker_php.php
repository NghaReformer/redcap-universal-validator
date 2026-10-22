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
    require_once __DIR__ . '/../php/Scan/DbError.php';
    require_once __DIR__ . '/../php/Scan/Schema.php';
    require_once __DIR__ . '/../php/Scan/ScanPlanner.php';
    require_once __DIR__ . '/../php/Scan/ScanPolicy.php';    // the detail budget (H9)
    require_once __DIR__ . '/../php/Scan/WorkBudget.php';
    require_once __DIR__ . '/../php/Scan/WorkerSlots.php';
    require_once __DIR__ . '/../php/Scan/UniqueFinalizer.php';
    require_once __DIR__ . '/../php/Scan/CatchUp.php';
    require_once __DIR__ . '/../php/Scan/RollupBuilder.php';   // tally() reads its KINDS
    require_once __DIR__ . '/../php/Scan/ScanPromotion.php';
    require_once __DIR__ . '/../php/Scan/ScanStoreUnavailable.php';
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
    // Every working phase goes through `cancelling` first, so the epoch bump
    // lands BEFORE the terminal write and a worker mid-evaluation fails its
    // compare-and-set instead of committing into a finished run. PLANNING
    // TOO since B10: it finished outright while the list was walked inside
    // the start request, and a work request now lists a slice of it under a
    // lease - that planner is the worker in flight.
    check('cancel: a planning run goes through cancelling, because a planner may be mid-slice',
        ScanPhase::cancelTarget('planning') === 'cancelling');
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
        // THE CELL WHERE BOTH ARE FALSE. It answered from the fence row, so a
        // run that had neither finished its list nor proved a window was stored
        // as manifest-complete: "every record on the opening list was examined".
        ['neither a finished manifest nor a proved window',
         ['fenced' => false, 'manifestDone' => false], 'partial', 'partial', 'complete', false,
         '_INCOMPLETE'],
        ['a proved window over a manifest nobody finished',
         ['manifestDone' => false], 'partial', 'partial', 'complete', false, '_INCOMPLETE'],
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
        // A scope that held no records. NOT `failed` - an empty project is a
        // fact about the project and a failure is a fact about the run - and
        // NOT `manifest-complete`, which would claim a manifest that does not
        // exist. $base carries fenced and manifestDone, so this row also pins
        // that an empty scope OUTRANKS the fenced clean row.
        ['a scope that held no records at all',
         ['emptyScope' => true], 'partial', 'empty-scope', 'complete', false, '_NO_RECORDS'],
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

    // THE GAP POLICY CHANGED ITS WORD AND THE FINGERPRINT MUST NOT NOTICE (B4c).
    // policy.collectionGaps said 'separate' while nothing detected a gap, and
    // says 'unimplemented' now. The fingerprint used to read it; had it kept
    // doing so, every run in flight across the upgrade would have been failed
    // as "the configuration moved". What is pinned is the OLD hash: a request
    // fingerprinted today equals one built by hand with the old wire value.
    $req = ['engine' => '1.8.19', 'rules' => [], 'ownership' => ['dob' => 'demographics'],
            'structure' => ['longitudinal' => false], 'choices' => []];
    $asStored = ScanPlanner::fingerprint(['engine' => '1.8.19', 'rules' => [],
        'ownership' => ['dob' => 'demographics'], 'structure' => ['longitudinal' => false],
        'choices' => [], 'gapPolicy' => 'separate', 'valueMode' => 'locations']);
    $with = function ($policy) use ($req) {
        return ScanPlanner::requestFingerprint(array_merge($req, ['policy' => $policy]));
    };
    check('fingerprint: a run planned before the gap policy was renamed still matches',
        $with(['collectionGaps' => 'separate', 'valueMode' => 'locations']) === $asStored);
    check('fingerprint: and so does one planned after it',
        $with(['collectionGaps' => 'unimplemented', 'valueMode' => 'locations']) === $asStored);
    check('fingerprint: whatever the policy says about gaps, because it is no longer asked',
        $with(['collectionGaps' => 'anything at all', 'valueMode' => 'locations']) === $asStored
        && ScanPlanner::requestFingerprint($req) === $asStored);
    check('fingerprint: while the value mode, which does decide what is stored, still counts',
        $with(['valueMode' => 'raw']) !== $asStored);

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
    $planner = new ScanPlanner(new ArrayScanStore(), 'k');
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

    // EVERY SPELLING PHP ENFORCES IS A LIMIT HERE TOO (review BU-6). PHP 8.2+
    // reads 0x, 0o and 0b prefixes in memory_limit, both 7.4 and 8.x read a
    // leading 0 as octal, and both accept a leading '+'. Each of these used to
    // parse to null, which is "no limit", which switched the memory guard off
    // on a server that had a limit. The figures are the ones PHP itself
    // enforced for these strings, read from its "Allowed memory size" fatal.
    check('budget: a hexadecimal memory_limit is the limit PHP enforces',
        WorkBudget::bytes('0x10000000') === 268435456 && WorkBudget::bytes('0X20m') === 33554432
        && WorkBudget::bytes('0x1G') === 1073741824 && WorkBudget::bytes('0x10 M') === 16777216);
    check('budget: so are the octal and binary prefixes PHP 8.2 added',
        WorkBudget::bytes('0o1000000000') === 134217728 && WorkBudget::bytes('0O7M') === 7340032
        && WorkBudget::bytes('0b1000000000000000000000000000') === 134217728
        && WorkBudget::bytes('0B110M') === 6291456);
    // 0256 is octal 174, and PHP enforced 182452224 bytes for it. Reading it as
    // 256 MiB would put the reserve line above what PHP actually allows.
    check('budget: a leading zero is octal, as PHP reads it',
        WorkBudget::bytes('0256M') === 182452224 && WorkBudget::bytes('07M') === 7340032);
    check('budget: and a leading plus sign is still a limit',
        WorkBudget::bytes('+128M') === 134217728 && WorkBudget::bytes('+0x10M') === 16777216);
    check('budget: a prefix with no digits, or digits the base cannot hold, is unreadable',
        WorkBudget::bytes('0x') === null && WorkBudget::bytes('0b2') === null
        && WorkBudget::bytes('0o9') === null && WorkBudget::bytes('089M') === null);
    // A figure too large for an integer is not a limit anyone can reach. It
    // used to come back as a float; it is "no limit" now, said as null.
    check('budget: a limit past the integer range is no limit, not a float',
        WorkBudget::bytes('99999999999G') === null
        && WorkBudget::bytes('0xffffffffffffffffM') === null);

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

    // -- what is measured, and against what (P5) ------------------------------
    //
    // Every check above hands next() its numbers, so none of them can see WHERE
    // the numbers come from - and that was the defect. The worker measured a
    // batch's cost as the change in the ARENA, which moves in 2 MiB steps: a
    // batch that needed 20 MiB inside chunks already held measured zero, and the
    // budget then predicted nothing. These drive the seam itself, mark() and
    // observe(), through a probe that answers with stated figures.
    $MB = 1024 * 1024;
    /** A probe that answers from a script and remembers how it was asked. */
    $scripted = function (array $answers) use ($MB) {
        $o = new \stdClass();
        $o->calls = [];
        $o->answers = $answers;
        $o->fn = function ($reset) use ($o, $MB) {
            $o->calls[] = (bool) $reset;
            $a = count($o->answers) > 1 ? array_shift($o->answers) : $o->answers[0];
            return ['used' => (int) ($a[0] * $MB), 'peak' => (int) ($a[1] * $MB),
                    'arena' => (int) ($a[2] * $MB)];
        };
        return $o;
    };
    $measured = function ($limitMb, $probe) use ($MB) {
        return new WorkBudget(['mode' => 'cron', 'memoryLimit' => $limitMb * $MB,
            'timeLimit' => null, 'min' => 1, 'max' => 500, 'first' => 10,
            'startedAt' => microtime(true), 'probe' => $probe->fn]);
    };
    $t = microtime(true);

    // THE ARENA DID NOT MOVE AND THE BATCH STILL NEEDED 20 MiB. 42 MiB of arena
    // before and after; real usage rose to a 60 MiB peak and fell back.
    $p1 = $scripted([[40, 40, 42], [40.5, 60, 42]]);
    $b1m = $measured(100, $p1);
    $o1 = $b1m->observe($b1m->mark('records', $t), 10, $t + 1.0);
    check('measure: a batch that peaked 20 MiB inside chunks already held is capped by memory',
        $o1['claim'] === 9 && strpos((string) $o1['why'], 'rather than the clock') !== false);
    $arenaOnly = new WorkBudget(['mode' => 'cron', 'memoryLimit' => 100 * $MB, 'timeLimit' => null,
        'min' => 1, 'max' => 500, 'first' => 10, 'startedAt' => microtime(true)]);
    $o1a = $arenaOnly->next(['records' => 10, 'seconds' => 1.0, 'memoryDelta' => 0,
                             'usage' => 42 * $MB]);
    check('measure: where the arena delta it used to be given saw nothing and doubled the batch',
        $o1a['claim'] === 20 && $o1a['why'] === null);

    // A STALE PEAK IS NOT A MEASUREMENT. Below PHP 8.2 the peak cannot be reset,
    // so it is whatever the request reached before the scan started - here 70
    // MiB, from bootstrap. The batch did not move it and retained 0.2 MiB.
    // Charging the batch the 30 MiB gap would cap it at 16, the next at fewer,
    // and so on down to one record at a time with a message blaming the project.
    $p2 = $scripted([[40, 70, 72], [40.2, 70, 72],      // batch 1: peak unmoved
                     [40, 70, 72], [40, 92, 94],        // batch 2: peak moved to 92
                     [40, 92, 94], [40, 92, 94],        // batch 3: unmoved again
                     [40, 92, 94], [40, 92, 94]]);      // batch 4: another kind of unit
    $b2m = $measured(200, $p2);
    $o2 = $b2m->observe($b2m->mark('records', $t), 10, $t + 1.0);
    // Not charged, so it does not shrink. But nor does it double blind (review
    // BU-3): it grows only as far as the high-water mark itself proves. Ten
    // records peaked at most 30 MiB above where they started, 3 MiB each at
    // the outside, and 48 MiB of room holds 16 of those. The clock wanted 20.
    check('measure: a peak the batch did not move is not charged to the batch',
        $o2['claim'] === 16 && $o2['why'] === null);
    // 52 MiB over 20 records is 2.6 MiB each; 120 - 94 leaves room for 10.
    $o3 = $b2m->observe($b2m->mark('records', $t), 20, $t + 2.0);
    check('measure: a peak the batch DID move is, exactly', $o3['claim'] === 10);
    // The next batch stays under that high-water mark, so it measures nothing
    // new. Forgetting the last exact figure would let it double straight back.
    $o4 = $b2m->observe($b2m->mark('records', $t), 10, $t + 1.0);
    check('measure: and the last exact figure holds when a later batch measures nothing',
        $o4['claim'] === 10);
    // A cost per record says nothing about a cost per duplicate group, so the
    // groups batch has no exact figure of its own. It stays under the request's
    // high-water mark, so all that is known is that ten groups cost at most
    // 52 MiB; 26 MiB of room proves five, not the twenty the clock would allow,
    // and the claim holds where it is (review BU-3).
    $o5 = $b2m->observe($b2m->mark('groups', $t), 10, $t + 1.0);
    check('measure: a kind with nothing exact yet holds its claim rather than doubling blind',
        $o5['claim'] === 10);

    // HEADROOM IS STILL THE ARENA, because that is what memory_limit caps.
    $p3 = $scripted([[30, 30, 61]]);
    $b3m = new WorkBudget(['mode' => 'cron', 'memoryLimit' => 100 * $MB, 'timeLimit' => null,
        'startedAt' => 1000.0, 'probe' => $p3->fn]);
    check('measure: 30 MiB in use inside a 61 MiB arena is past the reserve',
        $b3m->mustStop(1000.0) === 'memory');
    $p3b = $scripted([[30, 30, 59]]);
    $b3n = new WorkBudget(['mode' => 'cron', 'memoryLimit' => 100 * $MB, 'timeLimit' => null,
        'startedAt' => 1000.0, 'probe' => $p3b->fn]);
    check('measure: and inside a 59 MiB arena is not', $b3n->mustStop(1000.0) === null);
    check('measure: asking how much room is left never restarts the peak',
        $p3->calls === [false]);

    // THE REAL PROBE, on whatever PHP this is. 7.4 has no way to restart the
    // peak and must not be asked to.
    $real = new WorkBudget(['mode' => 'cron', 'memoryLimit' => null, 'timeLimit' => null,
        'startedAt' => microtime(true)]);
    $rm = $real->mark('records');
    $ro = $real->observe($rm, 5);
    check('measure: the default probe reads real usage, and the peak is never below it',
        $rm['used'] > 0 && $rm['peak'] >= $rm['used']
        && array_keys($ro) === ['claim', 'stop', 'why'] && $ro['claim'] >= 1);

    // ON 8.2+ THE PEAK IS RESTARTED, AND THAT IS WHAT MAKES IT EXACT (review
    // BU-5). Nothing pinned it: with the memory_reset_peak_usage() call taken
    // out, every suite stayed green. Real memory, real probe: a 24 MiB block
    // is allocated and freed, leaving a high-water mark far above what follows;
    // then one unit allocates and frees 1 MiB between mark() and observe().
    // Restarted, the peak says 1 MiB, and a reserve line 10 MiB above the
    // arena holds about ten such units. Not restarted, the batch sits under the
    // old mark and measures nothing, and the claim does not move from 25.
    if (function_exists('memory_reset_peak_usage')) {
        $junk = str_repeat('j', 24 * $MB);
        unset($junk);
        $lim = (int) ((memory_get_usage(true) + 10 * $MB) / (1.0 - WorkBudget::RESERVE)) + 1;
        $rb = new WorkBudget(['mode' => 'cron', 'memoryLimit' => $lim, 'timeLimit' => null,
            'min' => 1, 'max' => 500, 'first' => 25, 'startedAt' => microtime(true)]);
        $rmk = $rb->mark('records');
        $blob = str_repeat('b', $MB);
        unset($blob);
        $rro = $rb->observe($rmk, 1);
        check('measure: on 8.2+ a unit under an old high-water mark is measured by a restarted peak',
            $rro['claim'] >= 5 && $rro['claim'] <= 10
            && strpos((string) $rro['why'], 'rather than the clock') !== false);
    }

    // -- one claim, and one floor, per kind of unit (review BU-1) -------------
    //
    // The budget used to hold ONE claim for the whole request while it kept its
    // memory figure per kind. So a cheap kind of batch could double the claim
    // with nothing to say about memory, and the next batch of an expensive kind
    // was handed that doubled number. Catch-up alternates exactly like this:
    // records that cost megabytes, change-log pages that cost a kilobyte.
    /**
     * A model of PHP's heap, for driving the budget through whole sequences.
     *
     * `used` is real usage and `peak` the high-water mark; the arena is 2 MiB
     * chunks over usage, as PHP takes them. With `$reset` the peak restarts
     * when the budget asks (PHP 8.2+); without it the peak is request-wide, as
     * on 7.4. A batch costs whatever the test makes it spike.
     */
    class ModelHeap
    {
        public $used;
        public $peak;
        public $reset;
        public function __construct($usedMb, $reset, $peakMb = null)
        {
            $this->used = (int) ($usedMb * 1048576);
            // A peak above usage is something earlier in the request that
            // allocated and let go - REDCap's bootstrap, say.
            $this->peak = max($this->used, (int) (($peakMb === null ? 0 : $peakMb) * 1048576));
            $this->reset = (bool) $reset;
        }
        public function probe()
        {
            return function ($resetPeak) {
                $did = $resetPeak && $this->reset;
                if ($did) $this->peak = $this->used;
                return ['used' => $this->used, 'peak' => $this->peak,
                        'arena' => (int) (ceil($this->used / 2097152) * 2097152), 'reset' => $did];
            };
        }
        /** Something was held for a moment and let go. */
        public function spike($bytes)
        {
            $this->peak = max($this->peak, $this->used + (int) $bytes);
        }
    }

    $heap = new ModelHeap(10, true);
    $kinds = new WorkBudget(['mode' => 'cron', 'memoryLimit' => 100 * $MB, 'timeLimit' => null,
        'min' => 1, 'max' => 500, 'first' => 10, 'startedAt' => microtime(true),
        'probe' => $heap->probe()]);
    $seen = ['records' => [], 'changes' => []];
    $at = microtime(true);
    for ($i = 0; $i < 6; $i++) {
        // 4 MiB a record against 50 MiB of room is 12 records; a change-log
        // row costs a kilobyte and may grow as fast as the clock allows.
        foreach (['records' => 4 * $MB, 'changes' => 1024] as $kind => $cost) {
            $size = $kinds->claim($kind);     // not $n: that is the check counter
            $seen[$kind][] = $size;
            $mk = $kinds->mark($kind, $at);
            $heap->spike($size * $cost);
            $at += 0.01;
            $kinds->observe($mk, $size, $at);
        }
    }
    check('kinds: records alternating with cheap pages never exceed what memory allowed records',
        max($seen['records']) === 12 && array_slice($seen['records'], 1) === [12, 12, 12, 12, 12]);
    check('kinds: while the cheap kind grows on its own clock',
        $seen['changes'][0] === 10 && max($seen['changes']) >= 160);

    // AND THE FLOOR IS KEPT PER KIND, not dropped whenever the kind changes.
    // Below 8.2 a records batch that stays under the high-water mark measures
    // only what it kept, so the last exact records figure is all that stands
    // between it and a blind doubling. It used to be thrown away by any batch
    // of another kind in between.
    $p6 = $scripted([[40, 40, 42], [40, 92, 94],       // records: moved the peak, 52 MiB / 20
                     [40, 92, 94], [40, 92, 94],       // groups: did not
                     [40, 92, 94], [40, 92, 94]]);     // records again: did not
    $b6 = new WorkBudget(['mode' => 'cron', 'memoryLimit' => 200 * $MB, 'timeLimit' => null,
        'min' => 1, 'max' => 500, 'first' => 20, 'startedAt' => microtime(true),
        'probe' => $p6->fn]);
    $b6->observe($b6->mark('records', $t), 20, $t + 1.0);
    $b6->observe($b6->mark('groups', $t), 10, $t + 1.0);
    $o6 = $b6->observe($b6->mark('records', $t), 10, $t + 1.0);
    check('kinds: the records figure survives a batch of another kind in between',
        $o6['claim'] === 10 && $b6->claim('records') === 10);
    check('kinds: and a kind not yet seen starts from the first claim, not from another kind\'s',
        $b6->claim('rows') === 20);

    // WHAT THE FLOOR IS STILL FOR, and why it is this kind's own. Records were
    // measured exactly at 2.6 MiB each; a cheap groups batch was measured
    // exactly too (its mark restarted the peak); then the room left fell from
    // 26 MiB to 13 and a records batch measured nothing. Only the records
    // floor knows a records batch now fits five. Without a floor the claim
    // would hold at ten; with the groups figure in its place it would grow.
    $p7 = $scripted([[40, 40, 42], [40, 92, 94],       // records: moved, 52 MiB / 20
                     [40, 40, 94], [40, 40.1, 94],     // groups: restarted, 0.1 MiB / 10
                     [53, 92, 107], [53, 92, 107]]);   // records: did not move; arena up 13
    $b7 = new WorkBudget(['mode' => 'cron', 'memoryLimit' => 200 * $MB, 'timeLimit' => null,
        'min' => 1, 'max' => 500, 'first' => 20, 'startedAt' => microtime(true),
        'probe' => $p7->fn]);
    $b7->observe($b7->mark('records', $t), 20, $t + 1.0);
    $b7->observe($b7->mark('groups', $t), 10, $t + 1.0);
    $o7 = $b7->observe($b7->mark('records', $t), 10, $t + 1.0);
    check('kinds: a records batch that measured nothing still shrinks by the records floor',
        $o7['claim'] === 5 && strpos((string) $o7['why'], 'rather than the clock') !== false);

    // -- below PHP 8.2: what a batch under the high-water mark proves (BU-3/4)
    //
    // Drives a budget through a sequence of batches against the heap model,
    // and stops where PHP would have died: a batch whose true peak passes the
    // limit. Each step is [kind, bytes per unit, bytes once for the batch].
    $drive = function (WorkBudget $b, ModelHeap $h, array $steps, $limitBytes) {
        $claims = [];
        $fatal = null;
        $at = microtime(true);
        foreach ($steps as $i => $s) {
            list($kind, $per, $extra) = $s;
            $size = $b->claim($kind);
            $claims[] = $size;
            $mk = $b->mark($kind, $at);
            if ($h->used + $size * $per + $extra > $limitBytes) { $fatal = $i; break; }
            $h->spike($size * $per + $extra);
            $at += 0.01;                     // fast: the clock always allows doubling
            $b->observe($mk, $size, $at);
        }
        return [$claims, $fatal];
    };
    $old = function (ModelHeap $h, $limitMb, $first = 25) use ($MB) {
        return new WorkBudget(['mode' => 'cron', 'memoryLimit' => $limitMb * $MB, 'timeLimit' => null,
            'min' => 1, 'max' => 500, 'first' => $first, 'startedAt' => microtime(true),
            'probe' => $h->probe()]);
    };

    // A BATCH UNDER AN EARLIER HIGH-WATER MARK MEASURES ONLY WHAT IT KEPT.
    // Something earlier in the request peaked at 100 of 128 MiB and let go.
    // Every batch of 0.3 MiB records stayed under that mark, measured nothing,
    // and doubled: 25, 50, 100, 200, then 400 records needing 120 MiB on top of
    // the 10 in use. It now grows only as far as the mark proves: 25 records
    // cost at most 90 MiB, and 66.8 MiB of room does not prove 50.
    $h = new ModelHeap(10, false, 100);
    list($cl, $fatal) = $drive($old($h, 128), $h, array_fill(0, 12, ['records', (int) (0.3 * $MB), 0]),
                               128 * $MB);
    check('below 8.2: a batch under a stale high-water mark does not double blind into a fatal',
        $fatal === null && max($cl) === 25);
    // ...and where the mark DOES prove room, growth goes on. Here it sits only
    // 10 MiB above usage: 25 records cost at most that, and 80 MiB of room
    // proves 200, so the clock's doubling is allowed until a batch moves the
    // peak and is measured exactly.
    $h = new ModelHeap(40, false, 50);
    list($cl, $fatal) = $drive($old($h, 200), $h, array_fill(0, 6, ['records', (int) (0.2 * $MB), 0]),
                               200 * $MB);
    check('below 8.2: a batch still grows as far as the high-water mark proves it may',
        $fatal === null && $cl === [25, 50, 100, 200, 400, 400]);

    // ONE OUTLIER DOES NOT PIN THE REST OF THE REQUEST (BU-4). A 40 MiB record
    // in the second batch moves the peak and sets the floor at 0.85 MiB a
    // record. Every later batch stays under that peak, so it used to measure
    // nothing and the floor held the batch at 78 for the rest of the request.
    // But a batch under the mark is PROVED to have cost no more than the gap
    // up to it, and that bound tightens as the batch grows: the floor relaxes
    // to it.
    $h = new ModelHeap(10, false);
    $steps = [['records', (int) (0.05 * $MB), 0], ['records', (int) (0.05 * $MB), 40 * $MB]];
    for ($i = 0; $i < 8; $i++) $steps[] = ['records', (int) (0.05 * $MB), 0];
    list($cl, $fatal) = $drive($old($h, 128), $h, $steps, 128 * $MB);
    check('below 8.2: the outlier caps the batch after it',
        $fatal === null && $cl[2] === 78);
    check('below 8.2: and then the floor relaxes to what later batches prove, without a fatal',
        $fatal === null && max(array_slice($cl, 3)) > 200);

    // WHAT A BATCH HELD AT ONCE, sampled between its units, is a better lower
    // bound than what it kept. 25 records held 75 MiB together under a stale
    // 120 MiB mark and freed it; 66.8 MiB of room holds 22 of those.
    $h = new ModelHeap(10, false, 120);
    $sb = $old($h, 128);
    $mk = $sb->mark('records', $t);
    $h->used += 75 * $MB;
    $sb->sample();
    $h->used -= 75 * $MB;
    $os = $sb->observe($mk, 25, $t + 0.01);
    check('below 8.2: what a batch held between units caps the next one',
        $os['claim'] === 22 && strpos((string) $os['why'], 'rather than the clock') !== false);

    // -- a request that starts past the reserve (review BU-2) ---------------
    //
    // The worker asks before its first claim whether it must stop. When the
    // host request already holds 60% of memory_limit before the scan does
    // anything - REDCap's bootstrap, the project's metadata, the rules - the
    // answer was 'memory' on every request, nothing was ever claimed, and the
    // person watching read "continues in the next one" until the abandoned-run
    // sweep expired the run. next() has always said "one record alone, never
    // zero"; the gate in front of the first claim did not.
    $pw = $scripted([[62, 62, 62]]);
    $bw = new WorkBudget(['mode' => 'cron', 'memoryLimit' => 100 * $MB, 'timeLimit' => null,
        'first' => 25, 'startedAt' => microtime(true), 'probe' => $pw->fn]);
    check('wedge: the reserve line itself has not moved', $bw->mustStop() === 'memory');
    check('wedge: but the first unit of a request past it is let through', $bw->admit() === null);
    // ONE RECORD, NOT ONE ROW (review R2S-2). The let-through spends a margin
    // sized for one record. It used to hand out one unit of ANY kind, so a
    // squeezed request summarised one finding, or read one change-log row, and
    // stopped; a run with thousands of findings needed thousands of requests
    // and the abandoned-run sweep expired it first. A page of rows costs a
    // kilobyte a row, so those kinds take their ordinary first claim.
    check('wedge: and it is ONE record - a kind that reads records takes one',
        $bw->claim('records') === 1 && $bw->claim('') === 1);
    check('wedge: but a page of rows is not a record, and takes its ordinary first claim',
        $bw->claim('rows') === 25 && $bw->claim('changes') === 25 && $bw->claim('groups') === 25);
    $ow = $bw->observe($bw->mark('records'), 1);
    check('wedge: after which the request stops, and says why it is going slowly',
        $ow['stop'] === 'memory' && strpos($ow['why'], 'before the scan began') !== false);
    $gw = $bw->admit();
    check('wedge: and is not let through a second time in the same request',
        $gw['stop'] === 'memory' && $gw['resumable'] === true);
    // No room for even one unit: 95 MiB of 100 leaves less than the tenth of
    // the limit one unit is assumed to need. That is not a pause, and is not
    // worded as one, because every request would arrive at the same place.
    $bf = new WorkBudget(['mode' => 'cron', 'memoryLimit' => 100 * $MB, 'timeLimit' => null,
        'first' => 25, 'startedAt' => microtime(true), 'probe' => $scripted([[95, 95, 95]])->fn]);
    $gf = $bf->admit();
    check('wedge: with no room for one unit it stops, and does not promise to continue',
        $gf['stop'] === 'memory' && $gf['resumable'] === false
        && strpos($gf['why'], 'continues in the next one') === false
        && strpos($gf['why'], 'memory_limit') !== false);
    check('wedge: time is still a pause, past the reserve or not',
        (new WorkBudget(['mode' => 'browser', 'memoryLimit' => null, 'timeLimit' => null,
            'startedAt' => microtime(true) - 3600]))->admit()['resumable'] === true);

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

        public function step($generationId, $limit = 500, $readLimit = null)
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
        $store = new ArrayScanStore();
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
                // A finding names its project. The store refuses one that does
                // not, because a row written with no project belongs to
                // nothing: invisible to its own report and immune to its own
                // project's retention. A stub that omitted it would keep this
                // suite green over a producer that had forgotten to set it,
                // which is how the constant generation survived five pilots.
                $out[] = ['project_id' => 800, 'generation_id' => 1,
                          'identity' => hash('sha256', $id . $i, true),
                          'valid_from_seq' => 1, 'record_hash' => hash('sha256', $id, true),
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
            if (strpos((string) $id, 'GONE') === 0) continue;
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

    // WHICH TERMINAL STATE, which the two checks above never asked (H18). They
    // passed for as long as this block has existed while the worker wrote a
    // TOMBSTONE on the strength of an absence: an export that left a record out
    // three times running recorded it as deleted, a tombstone does not block
    // coverage, and the run certified a project holding a record nobody
    // examined. A tombstone is a CONFIRMED deletion now, and nothing less is.
    $absent = function (array $deps, array $ids = ['A', 'GONE']) use ($fixture, $partial, $finder, $wide, $fin) {
        list($store, $runId) = $fixture($ids);
        $w = new ScanWorker($store, array_merge(['fence' => new Versions(), 'read' => $partial,
            'evaluate' => $finder(1), 'budget' => $wide(), 'owner' => 'w1', 'attempts' => 2,
            'finalizer' => $fin()], $deps));
        $w->work(800, $runId);
        $states = $store->recordStates($runId);
        ksort($states);
        $facts = ScanPromotion::facts(array_merge($store->run(800, $runId), ['fence_target' => '500']),
            $states, ['uniqueDone' => true, 'rollupDone' => true]);
        return [$states, ScanOutcome::derive($facts['facts'])];
    };

    list($states, $o) = $absent([]);
    check('absent: with nobody to ask, a record the read left out was NOT READ',
        $states === [ScanStore::REC_DONE => 1, ScanStore::REC_UNREADABLE => 1]);
    check('absent: so the run may not say it covered the project',
        $o['coverage'] === ScanOutcome::COV_PARTIAL && ScanOutcome::mayClaimClean($o) === false);

    $asked = [];
    list($states, $o) = $absent(['exists' => function (array $ids) use (&$asked) {
        $asked[] = $ids;
        return array_fill_keys($ids, false);
    }]);
    check('absent: a record the project confirms it no longer holds is a tombstone',
        $states === [ScanStore::REC_DONE => 1, ScanStore::REC_TOMBSTONE => 1]);
    check('absent: and a confirmed deletion does not cap coverage',
        $o['coverage'] === ScanOutcome::FENCED);
    // attempts = 2, so the first absence is a requeue and the answer would
    // change nothing. The question is asked on the LAST attempt only.
    check('absent: the project is asked once, on the last attempt, about that record alone',
        $asked === [['GONE']]);

    list($states, ) = $absent(['exists' => function (array $ids) { return array_fill_keys($ids, true); }]);
    check('absent: a record the project still holds is unreadable, never deleted',
        $states === [ScanStore::REC_DONE => 1, ScanStore::REC_UNREADABLE => 1]);

    // EVERY WAY OF NOT KNOWING IS "NOT GONE".
    foreach ([
        'throws'              => function (array $ids) { throw new \RuntimeException('the table is locked'); },
        'answers with a bool' => function (array $ids) { return false; },
        'answers with null'   => function (array $ids) { return null; },
        'leaves the id out'   => function (array $ids) { return []; },
        'answers 0, not false' => function (array $ids) { return array_fill_keys($ids, 0); },
        'answers null per id' => function (array $ids) { return array_fill_keys($ids, null); },
    ] as $how => $probe) {
        list($states, ) = $absent(['exists' => $probe]);
        check("absent: a resolver that $how confirms nothing",
            $states === [ScanStore::REC_DONE => 1, ScanStore::REC_UNREADABLE => 1]);
    }

    // ONE QUESTION PER BATCH, however many records are missing from it.
    $asked = [];
    list($states, ) = $absent(['exists' => function (array $ids) use (&$asked) {
        $asked[] = $ids;
        return ['GONE' => false, 'GONE2' => true, 'GONE3' => false];
    }], ['A', 'GONE', 'GONE2', 'GONE3']);
    check('absent: three missing records are one question',
        count($asked) === 1 && count($asked[0]) === 3);
    check('absent: and each is judged on its own answer',
        $states === [ScanStore::REC_DONE => 1, ScanStore::REC_UNREADABLE => 1,
                     ScanStore::REC_TOMBSTONE => 2]);

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
    // AND SAYS WHICH FENCE REFUSED. One message covered a stopped run, a taken
    // -over run and a database that would not write, and during the pilot a run
    // failed its first commit with no way to tell those apart.
    check('worker: it says what happened rather than reporting success',
        strpos($res['why'], 'stopped while these records were being examined') !== false);
    check('worker: and the claimed records are handed back, not stranded',
        $store->claimPending($runId, 'w2', (int) $store->run(800, $runId)['lease_epoch'], 5) !== []);


    // -- NO RUN MAY WEDGE FOREVER --------------------------------------------
    //
    // B8, B12 and H13 are three routes into one state: work was attempted,
    // nothing could be stored, and nothing recorded that it had been tried. The
    // records stayed claimable, the phase machine correctly refused to advance
    // over records nobody had examined, and the run held the project's one
    // active scan slot until somebody went into the database. The live pilot
    // logged forty identical batches.
    //
    // The counter is the whole of it. `attempts` was incremented by exactly one
    // statement - the record UPDATE inside commitBatch - so it moved only when
    // the commit had already succeeded. The retry cap was unreachable by the
    // path that needed it, BY CONSTRUCTION.

    // A WRITE THE STORE WILL REFUSE EVERY TIME. Two findings sharing one
    // identity is what a @UVCHOICES checkbox with two ticked hidden codes used
    // to produce; here it stands for any permanent write refusal, because the
    // worker cannot tell them apart and must not need to.
    $doomed = function ($id, $node) {
        $one = ['project_id' => 800, 'generation_id' => 1,
                'identity' => hash('sha256', 'the-same-identity-twice', true),
                'valid_from_seq' => 1, 'record_hash' => hash('sha256', $id, true),
                'record_id_bin' => $id, 'host_form' => 'f', 'field' => 'x',
                'rule_source_id' => 'r', 'rule_revision' => str_repeat('c', 64),
                'check_type' => 'required', 'reason_code' => 'required-blank'];
        return ['findings' => [$one, $one], 'bytes' => 10, 'contexts' => 1, 'why' => null];
    };
    list($store, $runId) = $fixture(['A']);
    $logged = [];
    $w = new ScanWorker($store, ['fence' => new Versions(), 'read' => $reader,
        'evaluate' => $doomed, 'budget' => $wide(), 'owner' => 'w1', 'attempts' => 3,
        'finalizer' => $fin(),
        'note' => function ($event, array $ctx) use (&$logged) { $logged[] = [$event, $ctx]; }]);
    $first = $w->work(800, $runId);
    check('wedge: a refused batch stops the pass rather than reporting success',
        $first['ok'] === false && $first['stop'] === 'fenced');
    check('wedge: saying the database refused it, not that the fence did',
        strpos($first['why'], 'database refused') !== false);
    // THE ATTEMPT IS COUNTED, and this is the assertion the whole release turns
    // on. It has to be visible from OUTSIDE the transaction that failed.
    $back = $store->claimPending($runId, 'w1', (int) $store->run(800, $runId)['lease_epoch'], 5);
    check('wedge: the record is handed back rather than left claimed', count($back) === 1);
    check('wedge: and the attempt was counted even though nothing committed',
        (int) $back[0]['attempts'] === 1);
    // The operator gets to know WHY, which is the only place the reason a record
    // ends up excluded is written down.
    check('wedge: the refusal reaches the module log',
        count($logged) >= 1 && $logged[0][0] === 'scan batch refused'
        && strpos($logged[0][1]['detail'], 'database refused') !== false);

    // AND IT ENDS. Three attempts, then the record becomes a reported exclusion
    // and the run can reach a terminal state.
    list($store, $runId) = $fixture(['A']);
    $w = new ScanWorker($store, ['fence' => new Versions(), 'read' => $reader,
        'evaluate' => $doomed, 'budget' => $wide(), 'owner' => 'w1', 'attempts' => 3,
        'finalizer' => $fin()]);
    $passes = 0;
    for ($i = 0; $i < 10; $i++) {
        $passes++;
        $w->work(800, $runId);
        if ($store->manifestComplete($runId)) break;
    }
    check('wedge: a permanently refused record stops being retried', $passes === 3);
    check('wedge: it reaches a terminal state and the run can finish',
        $store->manifestComplete($runId) === true);
    $states = $store->recordStates($runId);
    check('wedge: the state is UNSTORED - not done, not a tombstone, not unreadable',
        isset($states[ScanStore::REC_UNSTORED]) && (int) $states[ScanStore::REC_UNSTORED] === 1);
    // A SCAN THAT COULD NOT STORE WHAT IT FOUND HAS NOT CHECKED THE PROJECT.
    // Ending is necessary; ending CLEAN would be the worse failure.
    $facts = ScanPromotion::facts($store->run(800, $runId), $states, []);
    check('wedge: and it can never be reported clean',
        $facts['facts']['blocked'] === true);
    check('wedge: with nothing half-written from the batches that failed',
        (int) $store->run(800, $runId)['detail_rows'] === 0);

    // -- B12: A WHOLE-BATCH READ FAILURE TAKES THE SAME PATH ------------------
    //
    // It returned early: no commit, no release, no attempt. The rows stayed
    // CLAIMED, invisible to the straggler sweep for fifteen minutes, and one
    // transient REDCap export failure held the project's slot. Nothing more
    // exotic than that was needed to wedge a run permanently.
    list($store, $runId) = $fixture(['A', 'B']);
    $w = new ScanWorker($store, ['fence' => new Versions(), 'read' => $broken,
        'evaluate' => $finder(1), 'budget' => $wide(), 'owner' => 'w1', 'attempts' => 2,
        'finalizer' => $fin()]);
    $r = $w->work(800, $runId);
    check('wedge: a failed read stops the pass and says the export failed',
        $r['ok'] === false && $r['stop'] === 'read'
        && strpos($r['why'], 'export') !== false);
    $ep = (int) $store->run(800, $runId)['lease_epoch'];
    $handed = $store->claimPending($runId, 'w2', $ep, 5);
    check('wedge: the batch is released rather than left claimed for a quarter of an hour',
        count($handed) === 2);
    check('wedge: with the attempt counted against both records',
        (int) $handed[0]['attempts'] === 1 && (int) $handed[1]['attempts'] === 1);
    // H13: the same shape on the catch-up path, where the rows really are
    // CLAIMED and the straggler sweep is the only thing that could free them.
    list($store, $runId) = $fixture(['A', 'B']);
    $w = new ScanWorker($store, ['fence' => new Versions(), 'read' => $broken,
        'evaluate' => $finder(1), 'budget' => $wide(), 'owner' => 'w1', 'attempts' => 2,
        'finalizer' => $fin()]);
    $w->work(800, $runId);
    $w->work(800, $runId);
    check('wedge: a read that keeps failing ends the run instead of retrying it forever',
        $store->manifestComplete($runId) === true);
    $states = $store->recordStates($runId);
    check('wedge: reported UNREADABLE, which is what actually happened',
        isset($states[ScanStore::REC_UNREADABLE])
        && (int) $states[ScanStore::REC_UNREADABLE] === 2);

    // -- B9: ONE TAKEN-OVER RECORD DOES NOT KILL THE BATCH --------------------
    //
    // lease_epoch moves on cancellation and on nothing else, so takeover had no
    // fence at all: a second worker re-claimed a stale worker's rows while both
    // held the same epoch, and the first worker's commit passed. Bumping the
    // epoch is the obvious fix and is wrong - it throws away every other
    // worker's in-flight batch to invalidate one record's.
    list($store, $runId) = $fixture(['A', 'B']);
    $ep = (int) $store->run(800, $runId)['lease_epoch'];
    $stole = false;
    $stealing = function ($id, $node) use (&$store, $runId, $ep, $finder, &$stole) {
        // ONCE. The evaluator runs per record, so a sweep on every call would
        // take the whole batch over and the assertion below - that the records
        // this worker KEPT still commit - would be vacuous.
        if (!$stole) { $store->claimPending($runId, 'w2', $ep, 1); $stole = true; }
        $f = $finder(1);
        return $f($id, $node);
    };
    $w = new ScanWorker($store, ['fence' => new Versions(), 'read' => $reader,
        'evaluate' => $stealing, 'budget' => $wide(), 'owner' => 'w1', 'attempts' => 3,
        'finalizer' => $fin()]);
    $r = $w->work(800, $runId);
    check('wedge: the batch commits despite losing one record to another worker',
        $r['ok'] === true);
    $states = $store->recordStates($runId);
    check('wedge: the record it still held is finished',
        isset($states[ScanStore::REC_DONE]) && (int) $states[ScanStore::REC_DONE] === 1);
    check('wedge: and the epoch was not bumped, so no other worker was disturbed',
        (int) $store->run(800, $runId)['lease_epoch'] === $ep);
    check('wedge: only the held record\'s finding was written',
        (int) $store->run(800, $runId)['detail_rows'] === 1);

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

    // THROUGH PROMOTION, NOT A ROW OF ITS OWN (B7). The guard wrote
    // derive(['failed' => true]) with a sentence of its own, so the same event
    // stored a different row - no counts - from the one ScanService's check
    // stores. A rule problem the run had met is counted on the row it ends with.
    list($store, $runId) = $fixture(['A'], 804);
    $store->markAggregate($runId, ScanPromotion::RULE_KINDS, '1', 'config', 0, 'rule 1 is broken');
    (new ScanWorker($store, ['fence' => new Versions(), 'read' => $reader, 'evaluate' => $finder(1),
        'budget' => $wide(), 'owner' => 'w1', 'attempts' => 3, 'fingerprint' => $fpNow]))->work(804, $runId);
    $row = $store->run(804, $runId);
    check('B7: a moved configuration ends the run through promotion, with its counts and its sentence',
        $row['terminal'] === ScanOutcome::FAILED && (int) $row['rule_problem_count'] === 1
        && strpos((string) $row['terminal_reason'], 'validation rules or design changed') !== false);
    // rv10b1-6: told the evaluator revision, the guard names an upgrade. The
    // fixture's run records none, which is a run an older build began.
    list($store, $runId) = $fixture(['A'], 806);
    $res = (new ScanWorker($store, ['fence' => new Versions(), 'read' => $reader, 'evaluate' => $finder(1),
        'budget' => $wide(), 'owner' => 'w1', 'attempts' => 3, 'fingerprint' => $fpNow,
        'engine' => 'engine-5']))->work(806, $runId);
    $row = $store->run(806, $runId);
    check('rv10b1-6: the worker\'s guard, told the revision, says the module was upgraded',
        $row['terminal'] === ScanOutcome::FAILED && $row['terminal_reason'] === ScanPromotion::ENGINE_UPGRADED
        && strpos((string) $res['why'], 'upgraded') !== false);

    // A TIGHTENED POLICY IS A STOP, WHATEVER ELSE MOVED (review R5-5, B7). The
    // value mode is a fingerprint input, so tightening it moves both; with the
    // guard armed, the fingerprint must not be asked first.
    list($store, $runId) = $fixture(['A'], 805);
    $res = (new ScanWorker($store, ['fence' => new Versions(), 'read' => $reader,
        'evaluate' => $finder(1), 'budget' => $wide(), 'owner' => 'w1', 'attempts' => 3,
        'fingerprint' => $fpNow, 'policyRevision' => 9]))->work(805, $runId);
    check('B7: a tightened policy that also moved the fingerprint stops the run, it does not fail it',
        $store->run(805, $runId)['terminal'] === ScanOutcome::CANCELLED
        && strpos($res['why'], 'privacy settings') !== false);

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

    // -- the worker measures through the budget, not around it (P5) ----------
    //
    // The worker used to read memory_get_usage(true) itself, eight times, and
    // hand the budget the difference. It now marks and observes, so the budget
    // owns what is measured. Proved from the outside: the probe sees a mark
    // (which restarts the peak) answered by an observe (which does not), once
    // per batch.
    list($store, $runId) = $fixture(['A', 'B', 'C', 'D', 'E', 'F']);
    $seam = $scripted([[10, 10, 12]]);
    $two = new WorkBudget(['mode' => 'cron', 'memoryLimit' => null, 'timeLimit' => null,
        'min' => 2, 'max' => 2, 'first' => 2, 'startedAt' => microtime(true),
        'probe' => $seam->fn]);
    $w = new ScanWorker($store, ['fence' => new Versions(), 'read' => $reader,
        'evaluate' => $finder(1), 'budget' => $two, 'owner' => 'w1', 'attempts' => 3,
        'finalizer' => $fin()]);
    $res = $w->work(800, $runId);
    $marks = 0; $paired = true;
    foreach ($seam->calls as $i => $reset) {
        if (!$reset) continue;
        $marks++;
        // every mark is followed by a read that does NOT restart the peak
        if (!isset($seam->calls[$i + 1]) || $seam->calls[$i + 1] !== false) $paired = false;
    }
    check('seam: six records two at a time are three measured batches, at least',
        $res['worked'] === 6 && $marks >= 3);
    check('seam: and each mark is answered by an observe', $paired === true);

    // AND THE BUDGET'S ANSWER IS OBEYED. The arena is over the reserve by the
    // time the first batch is observed, so the request stops there - with that
    // batch committed and the run left to be carried on.
    list($store, $runId) = $fixture(['A', 'B', 'C', 'D', 'E', 'F']);
    // Reads, in order: admit, mark, a sample after the read and one after each
    // of the two records, then observe. The arena jumps only at observe; a
    // jump during the batch is a different test (the batch is cut short).
    $tight = $scripted([[10, 10, 12], [10, 10, 12], [10, 10, 12], [10, 10, 12], [10, 10, 12],
                        [10, 10, 95]]);
    $capped = new WorkBudget(['mode' => 'cron', 'memoryLimit' => 100 * $MB, 'timeLimit' => null,
        'min' => 2, 'max' => 2, 'first' => 2, 'startedAt' => microtime(true),
        'probe' => $tight->fn]);
    $w = new ScanWorker($store, ['fence' => new Versions(), 'read' => $reader,
        'evaluate' => $finder(1), 'budget' => $capped, 'owner' => 'w1', 'attempts' => 3,
        'finalizer' => $fin()]);
    $res = $w->work(800, $runId);
    check('seam: an arena past the reserve stops the request after the batch in hand',
        $res['stop'] === 'memory' && $res['worked'] === 2);
    check('seam: leaving the run workable', $res['done'] === false
        && $store->run(800, $runId)['phase'] === 'scanning');

    // THE WEDGE, THROUGH THE WORKER (review BU-2). 62 MiB of a 100 MiB limit
    // is held before the worker starts, every request. Each request now does
    // one record and the run moves; it used to claim nothing, forever.
    list($store, $runId) = $fixture(['A', 'B', 'C']);
    $pastReserve = function ($arenaMb) use ($scripted, $MB) {
        return new WorkBudget(['mode' => 'browser', 'memoryLimit' => 100 * $MB, 'timeLimit' => null,
            'first' => 25, 'startedAt' => microtime(true),
            'probe' => $scripted([[$arenaMb, $arenaMb, $arenaMb]])->fn]);
    };
    $passes = [];
    for ($i = 0; $i < 3; $i++) {
        $w = new ScanWorker($store, ['fence' => new Versions(), 'read' => $reader,
            'evaluate' => $finder(1), 'budget' => $pastReserve(62), 'owner' => 'w1',
            'attempts' => 3, 'finalizer' => $fin()]);
        $res = $w->work(800, $runId);
        $passes[] = $res['worked'];
    }
    check('wedge: a request that starts past the reserve examines one record and stops',
        $passes === [1, 1, 1] && $res['ok'] === true && $res['stop'] === 'memory');
    check('wedge: so the run moves, one request at a time',
        (int) $store->run(800, $runId)['manifest_done'] === 3);
    list($store, $runId) = $fixture(['A']);
    $w = new ScanWorker($store, ['fence' => new Versions(), 'read' => $reader,
        'evaluate' => $finder(1), 'budget' => $pastReserve(95), 'owner' => 'w1',
        'attempts' => 3, 'finalizer' => $fin()]);
    $res = $w->work(800, $runId);
    // ok:false is what stops the browser pumping; the run is untouched and
    // resumable once the limit is raised.
    check('wedge: with no room for one record the request stops the pump and says why',
        $res['ok'] === false && $res['stop'] === 'memory' && $res['worked'] === 0
        && strpos($res['why'], 'continues in the next one') === false
        && $store->run(800, $runId)['phase'] === 'scanning');

    // NOTHING IN THE WORKER READS MEMORY ANY MORE. Comments and strings are
    // stripped first, so this is about calls and not about prose.
    $workerCode = '';
    foreach (token_get_all(file_get_contents(__DIR__ . '/../php/Scan/ScanWorker.php')) as $tok) {
        if (is_array($tok) && in_array($tok[0], [T_COMMENT, T_DOC_COMMENT,
            T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE], true)) continue;
        $workerCode .= is_array($tok) ? $tok[1] : $tok;
    }
    check('seam: the worker no longer measures memory for itself',
        strpos($workerCode, 'memory_get') === false);
    check('seam: and every batch it marks, it observes',
        preg_match_all('/->\s*mark\s*\(/', $workerCode) === 4
        && preg_match_all('/->\s*observe\s*\(/', $workerCode) === 4);

    // -- a verify page reads records, so it is sized as records (review BU-1) -
    //
    // UniqueFinalizer::step() was handed ONE limit, the request's one claim,
    // and used it for discover pages (a page of group rows, a kilobyte each)
    // and verify pages (a re-read of whole records, through the scan's own
    // read) alike. Discover pages cost nothing, so they doubled the claim to
    // the maximum; the verify page that followed then re-read 500 records and
    // the request died at memory_limit, the worker slot still leased.
    /**
     * UniqueFinalizer::step()'s contract with its pages made explicit: group
     * pages first, then verify pages that re-read records. Without a read
     * limit a verify page is as large as a group page, which is what the
     * production class does when it is not given one.
     */
    class PagedFinalizer implements DuplicateFinalizer
    {
        public $discover;
        public $verify;
        public $heap;
        public $perRecord;
        public $pages = [];     // [what, the limit it was given]
        public function __construct(ModelHeap $heap, $discover, $verify, $perRecord)
        {
            $this->heap = $heap; $this->discover = $discover; $this->verify = $verify;
            $this->perRecord = $perRecord;
        }
        public function step($generationId, $limit = 500, $readLimit = null)
        {
            $out = ['done' => false, 'groups' => 0, 'verified' => 0, 'emitted' => 0,
                    'published' => 0, 'collisions' => 0, 'read' => 0, 'why' => null];
            if ($this->discover > 0) {
                $n = min($limit, $this->discover);
                $this->discover -= $n;
                $this->pages[] = ['discover', $limit];
                $this->heap->spike($n * 1024);
                usleep(1000);       // a page takes time, so the clock lets it grow
                $out['groups'] = $n;
                return $out;
            }
            if ($this->verify > 0) {
                $lim = $readLimit === null ? $limit : $readLimit;
                $n = min($lim, $this->verify);
                $this->verify -= $n;
                $this->pages[] = ['verify', $lim];
                $this->heap->spike($n * $this->perRecord);
                $out['verified'] = $n;
                $out['read'] = $n;
                return $out;
            }
            $out['done'] = true;
            return $out;
        }
    }
    $limitsOf = function (PagedFinalizer $f, $what) {
        $out = [];
        foreach ($f->pages as $p) if ($p[0] === $what) $out[] = $p[1];
        return $out;
    };
    $heapBudget = function (ModelHeap $h) use ($MB) {
        return new WorkBudget(['mode' => 'cron', 'memoryLimit' => 100 * $MB, 'timeLimit' => null,
            'min' => 1, 'max' => 500, 'first' => 10, 'startedAt' => microtime(true),
            'probe' => $h->probe()]);
    };

    // A REQUEST THAT STARTS IN UNIQUE-FINALIZE, as every browser pass after
    // the first one in that phase does. 150 groups to discover, then a group of
    // 30 candidates at 4 MiB a record, against 50 MiB of room.
    list($store, $runId) = $fixture(['A']);
    $ep = (int) $store->run(800, $runId)['lease_epoch'];
    $store->advancePhase($runId, $ep, ScanPhase::CATCH_UP);
    $store->advancePhase($runId, $ep, ScanPhase::UNIQUE);
    $h = new ModelHeap(10, true);
    $pf = new PagedFinalizer($h, 150, 30, 4 * $MB);
    $w = new ScanWorker($store, ['fence' => new Versions(), 'read' => $reader,
        'evaluate' => $finder(0), 'budget' => $heapBudget($h), 'owner' => 'w1', 'attempts' => 3,
        'finalizer' => $pf]);
    $res = $w->work(800, $runId);
    check('kinds: group pages grow on their own claim',
        $limitsOf($pf, 'discover') === [10, 20, 40, 80]);
    check('kinds: a verify page is handed the RECORDS claim, not the doubled group claim',
        $limitsOf($pf, 'verify') !== [] && $limitsOf($pf, 'verify')[0] === 10);
    // 10 records at 4 MiB is 40 MiB measured; 50 MiB of room holds 12. The
    // second page asking for 12 is the proof the first was measured as records.
    check('kinds: and it is measured as records, so the next verify page is sized by it',
        $limitsOf($pf, 'verify') === [10, 12, 12] && $res['ok'] === true);

    // THE SAME REQUEST THAT SCANNED. Records were capped at 12 by memory; the
    // group pages start from their own first claim, and the verify pages from
    // the records claim memory set.
    list($store, $runId) = $fixture(['R1', 'R2', 'R3', 'R4', 'R5', 'R6', 'R7', 'R8', 'R9', 'R10',
        'R11', 'R12', 'R13', 'R14', 'R15', 'R16', 'R17', 'R18', 'R19', 'R20', 'R21', 'R22', 'R23',
        'R24', 'R25', 'R26', 'R27', 'R28', 'R29', 'R30']);
    $h = new ModelHeap(10, true);
    $readSizes = [];
    $heavyReader = function ($ids) use ($h, $MB, &$readSizes) {
        $readSizes[] = count($ids);
        $h->spike(count($ids) * 4 * $MB);
        $data = [];
        foreach ($ids as $id) $data[$id] = ['1' => ['x' => 'v']];
        return ['ok' => true, 'data' => $data, 'why' => null];
    };
    $pf = new PagedFinalizer($h, 30, 20, 4 * $MB);
    $w = new ScanWorker($store, ['fence' => new Versions(), 'read' => $heavyReader,
        'evaluate' => $finder(0), 'budget' => $heapBudget($h), 'owner' => 'w1', 'attempts' => 3,
        'finalizer' => $pf]);
    $res = $w->work(800, $runId);
    check('kinds: the scan itself was capped by memory at 12 records a batch',
        $readSizes === [10, 12, 8] && $res['worked'] === 30);
    check('kinds: a group page after it does not inherit the records claim',
        $limitsOf($pf, 'discover') === [10, 20]);
    check('kinds: and a verify page inherits exactly the records claim',
        $limitsOf($pf, 'verify') === [12, 12]);

    // THE WORKER SAMPLES BETWEEN RECORDS (review BU-3). Below 8.2, under a
    // stale 120 MiB high-water mark in a 128 MiB limit: each batch holds 3 MiB
    // a record while it works and gives it back record by record, so by the
    // time the batch is observed nothing is held and the peak never moved.
    // Only a sample taken inside the batch sees the 75 MiB that 25 records
    // held at once - and 66.8 MiB of room holds 22 of them.
    $sixty = [];
    for ($i = 1; $i <= 60; $i++) $sixty[] = 'R' . $i;
    list($store, $runId) = $fixture($sixty);
    $h = new ModelHeap(10, false, 120);
    $readSizes = [];
    $holdingReader = function ($ids) use ($h, $MB, &$readSizes) {
        $readSizes[] = count($ids);
        $h->used += count($ids) * 3 * $MB;
        $data = [];
        foreach ($ids as $id) $data[$id] = ['1' => ['x' => 'v']];
        return ['ok' => true, 'data' => $data, 'why' => null];
    };
    $releasing = function ($id, $node) use ($h, $MB) {
        $h->used -= 3 * $MB;
        return ['findings' => [], 'bytes' => 0, 'contexts' => 1, 'why' => null];
    };
    $w = new ScanWorker($store, ['fence' => new Versions(), 'read' => $holdingReader,
        'evaluate' => $releasing, 'owner' => 'w1', 'attempts' => 3, 'finalizer' => $fin(),
        'budget' => new WorkBudget(['mode' => 'cron', 'memoryLimit' => 128 * $MB, 'timeLimit' => null,
            'min' => 1, 'max' => 500, 'first' => 25, 'startedAt' => microtime(true),
            'probe' => $h->probe()])]);
    $w->work(800, $runId);
    check('below 8.2: what a batch held between records caps the next batch',
        array_slice($readSizes, 0, 2) === [25, 22]);

    // -- a batch is cut short before it outgrows the limit (review EE-1) -----
    //
    // The budget sizes a batch BEFORE it is claimed, from what earlier batches
    // cost, and the first batch of a request has nothing behind it. 25 records
    // that raise 4,000 findings each are about 156 MB of findings, held until
    // the commit, and at memory_limit=128M the request died before the budget
    // had observed anything. So the worker asks after every record: if the
    // next one grows like the largest so far, would it - with a copy of every
    // finding held, which the commit may need while it writes them - reach the
    // limit? Then it stops there, commits what it examined, and hands the rest
    // back unexamined. Here each record holds 6 MiB of findings over a 20 MiB
    // base: after 8 the arena is 68 MiB holding 48 MiB of findings, and a ninth
    // with its copy would reach 68 + 48 + 12 = 128 MiB.
    $heldFindings = function (ModelHeap $h, array $perRecord, $limitBytes, &$died) use ($MB) {
        return function ($id, $node) use ($h, $perRecord, $limitBytes, &$died, $MB) {
            $h->used += (int) ((isset($perRecord[$id]) ? $perRecord[$id] : $perRecord['*']) * $MB);
            if ($h->used > $limitBytes) $died = true;      // where PHP would have died
            return ['findings' => [], 'bytes' => 0, 'contexts' => 1, 'why' => null];
        };
    };
    $budgetOn = function (ModelHeap $h, $first) use ($MB) {
        return new WorkBudget(['mode' => 'cron', 'memoryLimit' => 128 * $MB, 'timeLimit' => null,
            'min' => 1, 'max' => 500, 'first' => $first, 'startedAt' => microtime(true),
            'probe' => $h->probe()]);
    };
    $ids25 = [];
    for ($i = 1; $i <= 25; $i++) $ids25[] = 'R' . $i;
    list($store, $runId) = $fixture($ids25);
    $died = false;
    $h = new ModelHeap(20, true);
    $w = new ScanWorker($store, ['fence' => new Versions(), 'read' => $reader, 'owner' => 'w1',
        'evaluate' => $heldFindings($h, ['*' => 6], 128 * $MB, $died), 'attempts' => 3,
        'finalizer' => $fin(), 'budget' => $budgetOn($h, 25)]);
    $res = $w->work(800, $runId);
    check('cut: a batch whose findings would pass the limit stops before they do',
        $died === false && $res['ok'] === true && $res['worked'] === 8);
    check('cut: what was examined is committed, and the request stops with the reason',
        (int) $store->run(800, $runId)['manifest_done'] === 8 && $res['stop'] === 'memory'
        && strpos((string) $res['why'], 'handed the rest back') !== false);
    check('cut: the records it never reached are handed back unexamined',
        $store->recordState($runId, 9) === ScanStore::REC_PENDING
        && $store->recordState($runId, 25) === ScanStore::REC_PENDING);
    $worked = [8];
    for ($pass = 0; $pass < 3; $pass++) {
        $h->used = 20 * $MB;                               // each request starts afresh
        $w = new ScanWorker($store, ['fence' => new Versions(), 'read' => $reader, 'owner' => 'w1',
            'evaluate' => $heldFindings($h, ['*' => 6], 128 * $MB, $died), 'attempts' => 3,
            'finalizer' => $fin(), 'budget' => $budgetOn($h, 25)]);
        $worked[] = $w->work(800, $runId)['worked'];
    }
    // No attempt was counted against the records handed back: none of them is
    // excluded, and every one is examined in a later request.
    check('cut: and later requests pick them up where the last one stopped, none excluded',
        $died === false && $worked === [8, 8, 8, 1] && $store->manifestComplete($runId) === true
        && $store->recordStates($runId) === [ScanStore::REC_DONE => 25]);

    // A BATCH THAT ENDS ON ITS LAST RECORD WAS NOT CUT. Eight records reach the
    // same line on the eighth, and there is nothing left to hand back, so the
    // request is not told a batch was cut short.
    list($store, $runId) = $fixture(['R1', 'R2', 'R3', 'R4', 'R5', 'R6', 'R7', 'R8']);
    $h = new ModelHeap(20, true);
    $w = new ScanWorker($store, ['fence' => new Versions(), 'read' => $reader, 'owner' => 'w1',
        'evaluate' => $heldFindings($h, ['*' => 6], 128 * $MB, $died), 'attempts' => 3,
        'finalizer' => $fin(), 'budget' => $budgetOn($h, 25)]);
    $res = $w->work(800, $runId);
    check('cut: a batch that stops on its last record is not reported as cut short',
        $res['worked'] === 8 && strpos((string) $res['why'], 'handed the rest back') === false);

    // A CUT BATCH WHOSE COMMIT IS REFUSED. The eight records it examined were
    // attempted and are charged one attempt, as any refused batch's are; the
    // seventeen it never reached were not, and must not be.
    require_once __DIR__ . '/scan_fault_support.php';
    list($inner, $runId) = $fixture($ids25);
    $refusing = new class($inner) extends FaultyStore {
        public function commitBatch($runId, $owner, $epoch, array $batch, &$heldCount = null,
                                    &$heldOrdinals = null)
        {
            return 'the database refused this batch';
        }
    };
    $h = new ModelHeap(20, true);
    $w = new ScanWorker($refusing, ['fence' => new Versions(), 'read' => $reader, 'owner' => 'w1',
        'evaluate' => $heldFindings($h, ['*' => 6], 128 * $MB, $died), 'attempts' => 3,
        'finalizer' => $fin(), 'budget' => $budgetOn($h, 25)]);
    $res = $w->work(800, $runId);
    $rows = $inner->claim($runId, 'probe', (int) $inner->run(800, $runId)['lease_epoch'], 25);
    $tries = [];
    foreach ((array) $rows as $row) $tries[(int) $row['ordinal']] = (int) $row['attempts'];
    check('cut: a refused commit charges the records it examined and not the ones it never reached',
        $res['ok'] === false && count($tries) === 25 && $tries[1] === 1 && $tries[8] === 1
        && $tries[9] === 0 && $tries[25] === 0);

    // ONE RECORD THAT CANNOT BE KEPT. R3's findings take 70 MiB. Kept, the
    // commit could need as much again while it writes them, and 90 + 70 is
    // past the 128 MiB limit itself - even with nothing else read beside it.
    // So it is set aside: its findings are let go, ONE ATTEMPT is counted
    // against it outside the work that failed, and it is handed back. A record
    // that does this every time runs out of attempts and ends in a terminal
    // state - reported, blocking, and not a loop. The records before it are
    // committed; the ones after it go back.
    //
    // THE MODEL LETS GO WHEN THE WORKER DOES (review R2S-3). A record dropped
    // beside other records is now retried alone before it is charged, and the
    // retry is measured after the drop has returned its memory - which a heap
    // that only ever grew could not show. Each evaluation's memory is held by
    // the result the worker holds, and given back when the worker drops it.
    class HeldBytes
    {
        private $heap;
        private $bytes;
        public function __construct(ModelHeap $heap, $bytes)
        {
            $this->heap = $heap;
            $this->bytes = (int) $bytes;
            $heap->used += $this->bytes;
        }
        public function __destruct()
        {
            $this->heap->used -= $this->bytes;
        }
    }
    $letGo = function (ModelHeap $h, array $perRecord, $limitBytes, &$died) use ($MB) {
        return function ($id, $node) use ($h, $perRecord, $limitBytes, &$died, $MB) {
            $hold = new HeldBytes($h, (isset($perRecord[$id]) ? $perRecord[$id] : $perRecord['*']) * $MB);
            if ($h->used > $limitBytes) $died = true;      // where PHP would have died
            return ['findings' => [], 'bytes' => 0, 'contexts' => 1, 'why' => null, 'held' => $hold];
        };
    };
    list($store, $runId) = $fixture(['R1', 'R2', 'R3', 'R4', 'R5', 'R6']);
    $died = false;
    $h = new ModelHeap(20, true);
    $results = [];
    for ($pass = 0; $pass < 4; $pass++) {
        $h->used = 20 * $MB;
        $w = new ScanWorker($store, ['fence' => new Versions(), 'read' => $reader, 'owner' => 'w1',
            'evaluate' => $letGo($h, ['R3' => 70, '*' => 1], 128 * $MB, $died),
            'attempts' => 3, 'finalizer' => $fin(), 'budget' => $budgetOn($h, 6)]);
        $results[] = $w->work(800, $runId);
        if ($pass === 0) {
            check('set aside: the records before it are committed and it is not',
                $results[0]['worked'] === 2 && $store->recordState($runId, 3) === ScanStore::REC_PENDING
                && $store->recordState($runId, 1) === ScanStore::REC_DONE
                && strpos((string) $results[0]['why'], 'set aside') !== false);
        }
    }
    check('set aside: three tries end it as a record that could not be examined, not a loop',
        $died === false && $store->recordState($runId, 3) === ScanStore::REC_UNREADABLE
        && $results[2]['blocked'] === 1);
    check('set aside: and every other record is examined',
        $store->manifestComplete($runId) === true
        && $store->recordStates($runId) === [ScanStore::REC_DONE => 5, ScanStore::REC_UNREADABLE => 1]);

    // A RECORD IS NOT CHARGED FOR ITS NEIGHBOURS (review R2S-3). Every record
    // here needs 57 MiB of a 128 MiB limit on its own: 20 MiB of host, 3 MiB
    // read, 17 MiB of findings and a copy of them for the commit. A first batch
    // of 25 reads 75 MiB before the first evaluation, so the first record's
    // findings reach the line - and it was charged for it. The other 24 went
    // back uncharged, the next request read the same 25 and charged the same
    // record, and after three requests a record that fits easily was reported
    // as one that could not be examined; then the next, and the next. Now it
    // goes back uncharged, the batch's read data is let go, and it is tried
    // again alone in the same request, where it fits.
    $sixty = [];
    for ($i = 1; $i <= 60; $i++) $sixty[] = sprintf('S%02d', $i);
    list($store, $runId) = $fixture($sixty);
    $died = false;
    $h = new ModelHeap(20, true);
    $readBytes = function ($ids) use ($h, $MB) {
        $data = [];
        foreach ($ids as $id) {
            // Each record's read data is held for as long as the batch holds it.
            $data[$id] = ['1' => ['x' => 'v'], 'mem' => new HeldBytes($h, 3 * $MB)];
        }
        return ['ok' => true, 'data' => $data, 'why' => null];
    };
    $evals = [];
    $eval17 = $letGo($h, ['*' => 17], 128 * $MB, $died);
    $states = [];
    for ($pass = 0; $pass < 3; $pass++) {
        $h->used = 20 * $MB;                               // each request starts afresh
        $w = new ScanWorker($store, ['fence' => new Versions(), 'read' => $readBytes, 'owner' => 'w1',
            'evaluate' => function ($id, $node) use ($eval17, &$evals) {
                $evals[] = $id;
                return $eval17($id, $node);
            },
            'attempts' => 3, 'finalizer' => $fin(),
            'budget' => new WorkBudget(['mode' => 'browser', 'memoryLimit' => 128 * $MB,
                'timeLimit' => null, 'startedAt' => microtime(true), 'probe' => $h->probe()])]);
        $w->work(800, $runId);
    }
    check('neighbours: a record dropped beside a full batch is not charged for it',
        !isset($store->recordStates($runId)[ScanStore::REC_UNREADABLE]) && $died === false);
    check('neighbours: it is tried again alone, in the same request, and examined',
        array_slice($evals, 0, 2) === ['S01', 'S01'] && $store->recordState($runId, 1) === ScanStore::REC_DONE);
    check('neighbours: and the run carries on one record at a time until every one is examined',
        $store->recordStates($runId) === [ScanStore::REC_DONE => 60]);

    // AND WHEN LETTING GO GIVES NOTHING BACK - an arena PHP does not shrink -
    // the retry still runs. Gated on the reserve, it would have been refused
    // after the drop; the next request would read the same batch and drop the
    // same record uncharged, for ever, with no attempt counted and no end. It
    // is let through once and measured alone, so a request that truly cannot
    // hold the record charges it, and the run still ends.
    list($store, $runId) = $fixture(['T1', 'T2', 'T3', 'T4']);
    $used = 0;
    $evalsT = [];
    for ($pass = 0; $pass < 3; $pass++) {
        $used = 20 * $MB;
        $w = new ScanWorker($store, ['fence' => new Versions(), 'owner' => 'w1', 'attempts' => 3,
            'finalizer' => $fin(),
            'read' => function ($ids) use (&$used, $MB, $reader) {
                $used += count($ids) * 15 * $MB;
                return $reader($ids);
            },
            'evaluate' => function ($id, $node) use (&$used, $MB, &$evalsT) {
                $evalsT[] = $id;
                $used += 25 * $MB;
                return ['findings' => [], 'bytes' => 0, 'contexts' => 1, 'why' => null];
            },
            'budget' => new WorkBudget(['mode' => 'browser', 'memoryLimit' => 128 * $MB,
                'timeLimit' => null, 'startedAt' => microtime(true),
                'probe' => function ($reset) use (&$used) {
                    return ['used' => $used, 'peak' => $used, 'arena' => $used];
                }])]);
        $w->work(800, $runId);
    }
    check('neighbours: with nothing given back, the retry alone is still made in each request',
        $evalsT === ['T1', 'T1', 'T1', 'T1', 'T1', 'T1']);
    check('neighbours: and is charged there, so the run reaches an end rather than a loop',
        $store->recordState($runId, 1) === ScanStore::REC_UNREADABLE);

    // ONE AT A TIME FOR THE REST OF THE REQUEST, NOT FOR ONE BATCH (review
    // R3S-2). alone() pins the records claim at one, in claim() and in next(),
    // and with both pins taken out every suite stayed green: the records above
    // are large enough that memory holds the claim at one by itself. These are
    // not. Each reads 10.5 MiB and evaluates in 2 MiB, so a first batch of ten
    // reads 105 MiB over a 20 MiB base and its first evaluation reaches the
    // limit beside the other nine. Retried alone it costs 12.5 MiB, and the
    // clock and the room left would both let the next batch double - which is
    // the batch the drop has just shown this request cannot hold.
    //
    // AND THE RETRY RUNS AFTER THE BATCH HAS LET GO OF WHAT IT READ (review
    // R3S-3). reclaim() can only hand back chunks that were freed before it
    // ran, and with a probe it did nothing, so moving the release of the read
    // data after it left every suite green. The model heap says what is held
    // at the moment reclaim() is called: the 20 MiB base, and nothing the
    // batch read or evaluated.
    $twelve = [];
    for ($i = 1; $i <= 12; $i++) $twelve[] = sprintf('P%02d', $i);
    list($store, $runId) = $fixture($twelve);
    $died = false;
    $h = new ModelHeap(20, true);
    $sizes = [];
    $heldAtReclaim = [];
    $w = new ScanWorker($store, ['fence' => new Versions(), 'owner' => 'w1', 'attempts' => 3,
        'finalizer' => $fin(),
        'read' => function ($ids) use ($h, $MB, &$sizes) {
            $sizes[] = count($ids);
            $data = [];
            foreach ($ids as $id) {
                $data[$id] = ['1' => ['x' => 'v'], 'mem' => new HeldBytes($h, (int) (10.5 * $MB))];
            }
            return ['ok' => true, 'data' => $data, 'why' => null];
        },
        'evaluate' => $letGo($h, ['*' => 2], 128 * $MB, $died),
        'budget' => new WorkBudget(['mode' => 'browser', 'memoryLimit' => 128 * $MB,
            'timeLimit' => null, 'first' => 10, 'startedAt' => microtime(true),
            'probe' => $h->probe(),
            'reclaim' => function () use ($h, &$heldAtReclaim) { $heldAtReclaim[] = $h->used; }])]);
    $res = $w->work(800, $runId);
    check('alone: the first evaluation beside nine others is dropped and retried by itself',
        $died === false && array_slice($sizes, 0, 2) === [10, 1]
        && $store->recordState($runId, 1) === ScanStore::REC_DONE);
    check('alone: the batch after the retry, and the one after that, each claim one record',
        count($sizes) >= 4 && $sizes[2] === 1 && $sizes[3] === 1);
    check('alone: and so does every batch to the end of the request',
        max(array_slice($sizes, 1)) === 1 && $res['ok'] === true);
    check('alone: the read data is let go before reclaim() runs, so it can be handed back',
        $heldAtReclaim === [20 * $MB]);

    // Each pin on its own, in the budget's own answers. next() hands back one
    // after a retry, below a configured minimum of four; and claim() still
    // says one after next() has set the claim to that minimum because the
    // room ran out.
    $hp = new ModelHeap(20, true);
    $bp = new WorkBudget(['mode' => 'browser', 'memoryLimit' => 128 * $MB, 'timeLimit' => null,
        'min' => 4, 'first' => 10, 'startedAt' => microtime(true), 'probe' => $hp->probe()]);
    $bp->alone('records');
    $tp = microtime(true);
    $op = $bp->observe($bp->mark('records', $tp), 1, $tp + 0.001);
    check('alone: next() answers one after a retry, whatever the clock and the minimum say',
        $op['claim'] === 1 && $bp->claim('records') === 1);
    $hp->used = 100 * $MB;                                   // past the reserve now
    $mp = $bp->mark('records', $tp);
    $hp->spike(5 * $MB);
    $op2 = $bp->observe($mp, 1, $tp + 0.001);
    check('alone: and claim() answers one after the claim was set to the minimum',
        $op2['stop'] === 'memory' && $bp->claim('records') === 1);

    // -- a squeezed request summarises a page, and says it moved (R2S-2) ------
    //
    // A request that starts past the 60% reserve is let through for one unit.
    // In rollup-finalize that unit was ONE finding: forty findings took
    // forty-one requests, every one of which reported worked=0, findings=0,
    // blocked=0 in an unchanged phase - which js/scan.js progressed() reads as
    // "nothing moved" and answers by backing off to 30 s. Now the page is the
    // ordinary first claim, and what it summarised is reported as `listed`,
    // which the browser already counts as movement.
    $findingPage = new class implements ScanDb {
        public $page = [];
        public function select($sql, array $params = []) {
            if (strpos($sql, 'finding_id > ?') === false) return [];
            $after = (int) $params[2];
            $lim = preg_match('/LIMIT (\d+)/', $sql, $m) ? (int) $m[1] : PHP_INT_MAX;
            $out = [];
            foreach ($this->page as $r) {
                if ($r[0] > $after && count($out) < $lim) $out[] = $r;
            }
            return $out;
        }
        public function exec($sql, array $params = []) {}
        public function execNoCount($sql, array $params = []) {}
        public function affected() { return 0; }
        public function begin() {}
        public function commit() {}
        public function rollback() {}
    };
    for ($i = 1; $i <= 40; $i++) $findingPage->page[] = [$i, 'f' . ($i % 3), 'required-blank', null, 'required'];
    list($store, $runId) = $fixture(['A'], 825);
    $ep = (int) $store->run(825, $runId)['lease_epoch'];
    foreach ([ScanPhase::CATCH_UP, ScanPhase::UNIQUE, ScanPhase::ROLLUP] as $p) {
        $store->advancePhase($runId, $ep, $p);
    }
    $requests = 0;
    $silent = 0;
    $res = null;
    while ($requests < 100) {
        $requests++;
        // 66 MiB of a 100 MiB limit before the scan does anything: past the
        // reserve, with room for the one unit the let-through allows.
        $w = new ScanWorker($store, ['fence' => new Versions(), 'read' => $reader,
            'evaluate' => $finder(0), 'owner' => 'w1', 'attempts' => 3, 'finalizer' => $fin(),
            'rollup' => new RollupBuilder($findingPage, $store),
            'budget' => new WorkBudget(['mode' => 'browser', 'memoryLimit' => 100 * $MB,
                'timeLimit' => null, 'startedAt' => microtime(true),
                'probe' => $scripted([[65, 65, 66]])->fn])]);
        $res = $w->work(825, $runId);
        $moved = ($res['worked'] + $res['requeued'] + $res['blocked'] + $res['findings']
                  + (isset($res['listed']) ? $res['listed'] : 0)) > 0;
        if (!$res['done'] && !$moved) $silent++;
        if ($res['done']) break;
    }
    $summarised = 0;
    foreach ($store->aggregates($runId) as $a) {
        if ($a['kind'] === RollupBuilder::K_FORM) $summarised += (int) $a['cnt'];
    }
    check('squeezed: a request past the reserve summarises a page of findings, not one',
        $requests <= 3 && $res['done'] === true && $summarised === 40);
    check('squeezed: and no request that summarised something reports that nothing moved',
        $silent === 0);

    // THE UNIQUENESS PHASE SAYS IT MOVED TOO (review R3S-4). Its `listed` -
    // groups discovered, candidates verified, groups published - could be
    // replaced by nothing with every suite green: the only finalizer stub
    // reported zeros until the step that finished. A squeezed request in
    // unique-finalize that discovered 3 groups, verified 4 candidates and
    // published 5 groups, and emitted nothing yet, used to answer worked=0,
    // findings=0, blocked=0 in an unchanged phase - "nothing moved" to the
    // browser. It also takes the ordinary first page of groups, and one record
    // for the verify read, as a squeezed request should.
    class StepFinalizer implements DuplicateFinalizer
    {
        public $limits = [];
        public function step($generationId, $limit = 500, $readLimit = null)
        {
            $this->limits[] = [$limit, $readLimit];
            return ['done' => false, 'groups' => 3, 'verified' => 4, 'emitted' => 0,
                    'published' => 5, 'collisions' => 0, 'read' => 2, 'why' => null];
        }
    }
    list($store, $runId) = $fixture(['A'], 826);
    $ep = (int) $store->run(826, $runId)['lease_epoch'];
    foreach ([ScanPhase::CATCH_UP, ScanPhase::UNIQUE] as $p) $store->advancePhase($runId, $ep, $p);
    $sf = new StepFinalizer();
    $w = new ScanWorker($store, ['fence' => new Versions(), 'read' => $reader,
        'evaluate' => $finder(0), 'owner' => 'w1', 'attempts' => 3, 'finalizer' => $sf,
        'budget' => new WorkBudget(['mode' => 'browser', 'memoryLimit' => 100 * $MB,
            'timeLimit' => null, 'startedAt' => microtime(true),
            'probe' => $scripted([[65, 65, 66]])->fn])]);
    $res = $w->work(826, $runId);
    check('squeezed: unique-finalize reports the groups it found, verified and published as movement',
        $res['ok'] === true && $res['phase'] === ScanPhase::UNIQUE && $res['done'] === false
        && $res['stop'] === 'memory' && isset($res['listed']) && $res['listed'] === 3 + 4 + 5);
    check('squeezed: one step, with the ordinary first page of groups and one record to re-read',
        $sf->limits === [[25, 1]]);

    // THE DUPLICATES A RUN WRITES ARE DATED TO THAT RUN (review R8-5). The
    // worker is the one place with the run row, so it binds any finalizer that
    // writes to the run's run_seq before stepping it. The project's third run,
    // so its run_seq is 3 and not the 1 a per-group epoch starts at.
    class SeqFinalizer implements DuplicateFinalizer, SequencedFinalizer
    {
        public $seq = null;
        /** Shared by every bound copy, so the test sees what each one stepped as. */
        public $log;
        public function __construct() { $this->log = new \ArrayObject(); }
        public function forRun($runSeq)
        {
            $c = clone $this;
            $c->seq = (int) $runSeq;
            return $c;
        }
        public function step($generationId, $limit = 500, $readLimit = null)
        {
            $this->log[] = $this->seq;
            return ['done' => true, 'groups' => 0, 'verified' => 0, 'emitted' => 0,
                    'published' => 0, 'collisions' => 0, 'read' => 0, 'why' => null];
        }
    }
    $seqStore = new ArrayScanStore();
    for ($i = 0; $i < 2; $i++) {
        $old = $seqStore->startRun(827, ['created_by' => 'alice']);
        $seqStore->finish((int) $old['run']['run_id'], ScanOutcome::derive(['failed' => true]));
    }
    $third = $seqStore->startRun(827, ['created_by' => 'alice']);
    $seqRun = (int) $third['run']['run_id'];
    $seqStore->writeManifest($seqRun, [['id_bin' => 'A', 'hash' => hash('sha256', 'A', true), 'dag' => null]]);
    $ep = (int) $seqStore->run(827, $seqRun)['lease_epoch'];
    foreach ([ScanPhase::CATCH_UP, ScanPhase::UNIQUE] as $p) $seqStore->advancePhase($seqRun, $ep, $p);
    $seqFin = new SeqFinalizer();
    (new ScanWorker($seqStore, ['fence' => new Versions(), 'read' => $reader,
        'evaluate' => $finder(0), 'owner' => 'w1', 'attempts' => 3, 'finalizer' => $seqFin]))
        ->work(827, $seqRun);
    check('unique: the worker binds the finalizer to the run it is working, before the first step',
        (int) $seqStore->run(827, $seqRun)['run_seq'] === 3
        && count($seqFin->log) >= 1 && $seqFin->log[0] === 3);

    // THE PRODUCTION FINALIZER HONOURS THE READ LIMIT, and says how many
    // records a verify page read so the worker can measure it as records.
    /** Just enough of the database for one verify page of one group. */
    class VerifyPageDb implements ScanDb
    {
        public $limits = [];
        public function select($sql, array $params = [])
        {
            if (strpos($sql, 'MAX(group_hmac)') !== false) return [['g1']];
            if (strpos($sql, 'GROUP BY group_hmac') !== false) return [];
            if (strpos($sql, 'ix_pending') !== false) return [[7, 'g1', 1, 0, 0, 'new', null, 9]];
            if (preg_match('/ORDER BY candidate_id LIMIT (\d+)/', $sql, $m)) {
                $this->limits[] = (int) $m[1];
                $rows = [];
                // Two candidates per record: two fields under one rule.
                for ($i = 1; $i <= min(40, (int) $m[1]); $i++) {
                    $rows[] = [$i, 'R' . (int) ceil($i / 2), 0, 1, 'f' . ($i % 2), null];
                }
                return $rows;
            }
            return [];
        }
        public function exec($sql, array $params = []) { return 1; }
        public function execNoCount($sql, array $params = []) { }
        public function affected() { return 1; }
        public function begin() { }
        public function commit() { }
        public function rollback() { }
    }
    $vdb = new VerifyPageDb();
    $asked = [];
    $uf = new UniqueFinalizer($vdb, ['pid' => 800, 'read' => function (array $locs) use (&$asked) {
        $values = [];
        foreach ($locs as $l) {
            $asked[(string) $l['record']] = true;
            $values[UniqueFinalizer::locKey($l)] = ['same'];
        }
        return ['ok' => true, 'values' => $values, 'why' => null];
    }]);
    $vr = $uf->step(1, 500, 6);
    check('finalizer: a verify page is bounded by the read limit, not the group limit',
        $vdb->limits === [6] && $vr['verified'] === 6);
    check('finalizer: and reports the records it re-read, which is what it costs',
        isset($vr['read']) && $vr['read'] === 3 && count($asked) === 3);
    $vdb2 = new VerifyPageDb();
    $uf2 = new UniqueFinalizer($vdb2, ['pid' => 800, 'read' => function (array $locs) {
        $values = [];
        foreach ($locs as $l) $values[UniqueFinalizer::locKey($l)] = ['same'];
        return ['ok' => true, 'values' => $values, 'why' => null];
    }]);
    $uf2->step(1, 4);
    check('finalizer: a caller that gives no read limit gets the old single limit',
        $vdb2->limits === [4]);

    // A run sequence is a positive whole number or it is refused: a 0 or a
    // fraction here would be written into every duplicate's validity interval.
    $badSeq = [];
    foreach (['zero' => 0, 'negative' => -3, 'fraction' => '2.5', 'word' => 'run'] as $what => $v) {
        try { new UniqueFinalizer($vdb2, ['pid' => 800, 'seq' => $v]); }
        catch (\InvalidArgumentException $e) { $badSeq[] = 'ctor-' . $what; }
        try { $uf2->forRun($v); }
        catch (\InvalidArgumentException $e) { $badSeq[] = 'bind-' . $what; }
    }
    check('finalizer: a run sequence that is not a positive whole number is refused, built or bound',
        count($badSeq) === 8);
    try {
        $unboundWhy = $uf2->emit(1, ['group_hmac' => 'g1', 'emit_cursor' => 0, 'group_id' => 7,
                                     'candidate_epoch' => 1], 10)['why'];
    } catch (\Throwable $e) {
        $unboundWhy = 'threw: ' . $e->getMessage();
    }
    check('finalizer: binding returns a copy and leaves the unbound one unbound, writing nothing',
        $uf2->forRun('7') !== $uf2 && $unboundWhy === UniqueFinalizer::NO_SEQ);

    // W7-R4: A DUPLICATE IS WRITTEN AS ITS RULE'S OTHER FINDINGS ARE. Rule
    // ordinal, the record's group and `duplicate-value`, where it wrote 0,
    // NULL and `duplicate`. The groups come from one call per page.
    /** One group in `emitting`, three candidates, and the writes it is sent. */
    class EmitPageDb implements ScanDb
    {
        public $limits = [];
        public $inserts = [];
        public $updates = 0;
        public function select($sql, array $params = [])
        {
            if (strpos($sql, 'MAX(group_hmac)') !== false) return [['g1']];
            if (strpos($sql, 'GROUP BY group_hmac') !== false) return [];
            if (strpos($sql, 'ix_pending') !== false) return [[7, 'g1', 1, 0, 0, 'emitting', null, 2]];
            if (strpos($sql, 'lookup = 0') !== false && preg_match('/LIMIT (\d+)/', $sql, $m)) {
                $this->limits[] = (int) $m[1];
                $rows = [[1, 'h1', 'R1', 0, 1, 'fa', 'sid', 'src-b', str_repeat('c', 64)],
                         [2, 'h2', 'R2', 0, 1, 'fa', 'sid', 'src-b', str_repeat('c', 64)],
                         [3, 'h1', 'R1', 0, 1, 'fa', 'sid2', 'src-x', str_repeat('c', 64)]];
                return array_slice($rows, 0, (int) $m[1]);
            }
            return [];
        }
        public function exec($sql, array $params = []) { return 1; }
        public function execNoCount($sql, array $params = [])
        {
            if (strpos($sql, 'INSERT INTO') !== false) $this->inserts[] = [$sql, $params];
            if (strpos($sql, 'SET emit_cursor') !== false) $this->updates++;
        }
        public function affected() { return 1; }
        public function begin() { }
        public function commit() { }
        public function rollback() { }
        /** Each written row, column => value, read off the statement's own column list. */
        public function rows()
        {
            $out = [];
            foreach ($this->inserts as $ins) {
                list($sql, $params) = $ins;
                if (!preg_match('/\(([a-z_,\s]+)\)\s*VALUES\s*\(([^)]*)\)/', $sql, $m)) continue;
                $cols = array_map('trim', explode(',', $m[1]));
                $marks = array_map('trim', explode(',', $m[2]));
                $p = 0;
                while ($p < count($params)) {
                    $row = [];
                    foreach ($cols as $i => $c) $row[$c] = $marks[$i] === '?' ? $params[$p++] : $marks[$i];
                    $out[] = $row;
                }
            }
            return $out;
        }
    }
    $g7 = ['group_hmac' => 'g1', 'emit_cursor' => 0, 'group_id' => 7, 'candidate_epoch' => 1];
    $asked = [];
    $dagsOk = function (array $ids) use (&$asked) {
        $asked[] = $ids;
        $out = [];
        foreach ($ids as $id) $out[$id] = $id === 'R1' ? 'site_a' : null;
        return ['ok' => true, 'dags' => $out, 'read' => count($ids), 'why' => null];
    };
    $edb = new EmitPageDb();
    $er = (new UniqueFinalizer($edb, ['pid' => 800, 'hmacKey' => 'k', 'ordinals' => ['src-b' => 2],
                                      'dags' => $dagsOk]))->forRun(5)->emit(1, $g7, 10, 10);
    $wrote = [];
    foreach ($edb->rows() as $row) {
        $wrote[] = [$row['record_id_bin'], $row['field'], $row['rule_ord'], $row['dag_key'], $row['reason_code']];
    }
    check('W7-R4: each duplicate carries its rule\'s ordinal, its record\'s group and duplicate-value',
        $wrote === [['R1', 'sid', 2, 'site_a', 'duplicate-value'], ['R2', 'sid', 2, null, 'duplicate-value'],
                    ['R1', 'sid2', 0, 'site_a', 'duplicate-value']]);
    check('W7-R4: a rule the map does not name is ordinal 0, as before, and never a guess',
        isset($wrote[2]) && $wrote[2][2] === 0);
    check('W7-R4: the groups are asked once for the page, each record once',
        $asked === [['R1', 'R2']] && $er['emitted'] === 3 && $er['read'] === 2);
    // The page is cut to the read limit when the groups may cost a read.
    $edb = new EmitPageDb();
    $cut = (new UniqueFinalizer($edb, ['pid' => 800, 'hmacKey' => 'k', 'dags' => $dagsOk]))
        ->forRun(5)->step(1, 10, 2);
    $edb2 = new EmitPageDb();
    (new UniqueFinalizer($edb2, ['pid' => 800, 'hmacKey' => 'k']))->forRun(5)->step(1, 10, 2);
    check('W7-R4: a page that asks for groups is cut to the read limit; one that does not is not',
        $edb->limits === [2] && $edb2->limits === [10]);
    check('W7-R4: and the step reports the records that read cost, so the worker measures it',
        $cut['emitted'] === 2 && $cut['read'] === 2);
    check('W7-R4: without a groups dependency the row has no group, as before',
        $edb2->rows() !== [] && $edb2->rows()[0]['dag_key'] === null
        && $edb2->rows()[0]['reason_code'] === 'duplicate-value');
    // A failed read is not "no group".
    $edb = new EmitPageDb();
    $down = (new UniqueFinalizer($edb, ['pid' => 800, 'hmacKey' => 'k', 'dags' => function (array $ids) {
        return ['ok' => false, 'dags' => [], 'read' => count($ids), 'why' => 'gone'];
    }]))->forRun(5)->step(1, 10, 10);
    check('W7-R4: a page whose groups could not be read writes nothing and moves no cursor',
        $edb->inserts === [] && $edb->updates === 0);
    check('W7-R4: and says why, with nothing counted as moved, so the worker stops on it',
        $down['why'] === UniqueFinalizer::NO_DAGS && $down['emitted'] === 0 && $down['read'] === 0
        && $down['done'] === false);

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

    // -- catch-up: what the project did while we were reading it -------------
    //
    // A finished manifest is not a finished scan. Everything below is one of the
    // four things a changed record can be, and the point is that they are NOT
    // the same thing - treating a deletion as an edit holds the run open
    // forever, and treating an addition as noise certifies a project containing
    // a record nobody looked at.

    /** A change log a test drives directly. */
    class Log implements ChangeLog
    {
        public $top = '500';
        public $keeps = true;
        public $changed = [];        // [id => version] inside the window
        public $calls = 0;
        /** @var string|null 'now' or 'retained': that read fails, as a blip does (H11) */
        public $broken = null;

        public function now()
        {
            if ($this->broken === 'now') throw new ScanStoreUnavailable('[2006] server has gone away');
            return $this->top;
        }
        public function retained($open)
        {
            if ($this->broken === 'retained') throw new ScanStoreUnavailable('[2006] server has gone away');
            return $this->keeps ? ['ok' => true, 'why' => null]
                 : ['ok' => false, 'why' => 'part of the change log covering this scan has been '
                                          . 'removed since the run opened'];
        }
        public function changedSince($after, $upTo, $afterId, $limit)
        {
            $this->calls++;
            $out = [];
            $ids = array_keys($this->changed);
            sort($ids);
            foreach ($ids as $id) {
                if ($afterId !== null && strcmp((string) $id, (string) $afterId) <= 0) continue;
                $out[] = ['id' => (string) $id, 'version' => (string) $this->changed[$id]];
                if (count($out) >= $limit) break;
            }
            return $out;
        }
    }

    /** A run sitting in catch-up with its records already scanned. */
    $hash = function ($id) { return hash('sha256', $id, true); };
    $inCatchUp = function (array $ids, $scannedAt = '100') use ($hash) {
        $store = new ArrayScanStore();
        $r = $store->startRun(800, ['created_by' => 'alice', 'fence_open' => '1']);
        $runId = (int) $r['run']['run_id'];
        $recs = [];
        foreach ($ids as $id) $recs[] = ['id_bin' => $id, 'hash' => $hash($id), 'dag' => null];
        $store->writeManifest($runId, $recs);
        $epoch = (int) $store->run(800, $runId)['lease_epoch'];
        // Scan them all, so the manifest is finished and the versions are known.
        $claimed = $store->claim($runId, 'w1', $epoch, count($ids));
        $batch = ['bytes' => 0, 'records' => [], 'findings' => []];
        foreach ($claimed as $c) {
            $batch['records'][] = ['ordinal' => $c['ordinal'], 'state' => ScanStore::REC_DONE,
                                   'version' => $scannedAt, 'claim' => $c['claim']];
        }
        $store->commitBatch($runId, 'w1', $epoch, $batch);
        $store->advancePhase($runId, $epoch, ScanPhase::CATCH_UP);
        return [$store, $runId, $epoch];
    };

    // EDITED AFTER WE READ IT -> requeued.
    list($store, $runId, $epoch) = $inCatchUp(['A', 'B', 'C']);
    $log = new Log();
    $log->changed = ['B' => '400'];                 // scanned at 100, edited at 400
    $cu = new CatchUp($store, ['fence' => $log, 'hash' => $hash]);
    $r = $cu->step(800, $runId, $epoch, 100);
    check('catchup: a record edited after it was read is sent back to be read again',
        $r['requeued'] === 1);
    check('catchup: and it is the one that changed',
        $store->recordState($runId, 2) === ScanStore::REC_PENDING
        && $store->recordState($runId, 1) === ScanStore::REC_DONE);
    check('catchup: which un-finishes the manifest', $store->manifestComplete($runId) === false);

    // CHANGED BEFORE WE READ IT -> nothing to do. This is the branch that makes
    // the confirming round cheap, and getting it wrong would requeue the whole
    // project on every run.
    list($store, $runId, $epoch) = $inCatchUp(['A', 'B'], '900');
    $log = new Log();
    $log->changed = ['A' => '400', 'B' => '500'];   // both older than the scan
    $cu = new CatchUp($store, ['fence' => $log, 'hash' => $hash]);
    $r = $cu->step(800, $runId, $epoch, 100);
    check('catchup: a change already inside the reading we have is not re-read',
        $r['requeued'] === 0);
    // ...but it is still a change in the window, and a verdict that reads OTHER
    // records (@UVEXISTS) may have moved with it: counted once, round 1 only.
    $windowChanges = function ($store, $runId) {
        $c = 0;
        foreach ($store->aggregates($runId) as $a) if ($a['kind'] === CatchUp::K_WINDOW) $c += (int) $a['cnt'];
        return $c;
    };
    check('catchup: every change in the window is counted, settled ones included', $windowChanges($store, $runId) === 2);
    $cu->step(800, $runId, $epoch, 100);
    check('catchup: walking to the end of the round adds nothing', $windowChanges($store, $runId) === 2);

    // CREATED DURING THE RUN -> added. This is C3: without it the run certifies
    // a project containing a record it provably never examined.
    list($store, $runId, $epoch) = $inCatchUp(['A']);
    $log = new Log();
    $log->changed = ['A' => '50', 'NEW' => '400'];
    $cu = new CatchUp($store, ['fence' => $log, 'hash' => $hash]);
    $r = $cu->step(800, $runId, $epoch, 100);
    check('catchup: a record created during the run is added to the manifest', $r['added'] === 1);
    check('catchup: the total moves with it, so completeness is not measured '
        . 'against a number known to be wrong',
        (int) $store->run(800, $runId)['manifest_total'] === 2);
    check('catchup: and the run is no longer finished', $store->manifestComplete($runId) === false);

    // ...BUT NOT IF IT IS OUT OF SCOPE. A DAG-scoped run that widened itself
    // here would put another group's records into a report its reader may not
    // see - the same leak the page-level DAG filter exists to prevent.
    list($store, $runId, $epoch) = $inCatchUp(['A']);
    $log = new Log();
    $log->changed = ['OTHERDAG' => '400'];
    $cu = new CatchUp($store, ['fence' => $log, 'hash' => $hash,
        'scope' => function ($ids) { return array_fill_keys($ids, false); }]);
    $r = $cu->step(800, $runId, $epoch, 100);
    check('catchup: a new record outside this run\'s scope is NOT added', $r['added'] === 0);

    // DELETED DURING THE RUN -> tombstoned, never requeued. A deleted record can
    // never be read, so requeueing it holds the run open forever.
    list($store, $runId, $epoch) = $inCatchUp(['A', 'B']);
    $log = new Log();
    $log->changed = ['B' => '400'];
    $cu = new CatchUp($store, ['fence' => $log, 'hash' => $hash,
        'exists' => function ($ids) {
            $o = []; foreach ($ids as $i) $o[$i] = ($i !== 'B'); return $o;
        }]);
    $r = $cu->step(800, $runId, $epoch, 100);
    check('catchup: a record deleted during the run is tombstoned', $r['gone'] === 1);
    check('catchup: not requeued, which would hold the run open forever',
        $r['requeued'] === 0 && $store->recordState($runId, 2) === ScanStore::REC_TOMBSTONE);
    check('catchup: and a tombstone still counts as finished',
        $store->manifestComplete($runId) === true);

    // -- rounds ---------------------------------------------------------------
    //
    // A round that changed something must be followed by another, because the
    // records it requeued are scanned AFTER this reconciler saw them. A round
    // that changed nothing is the proof that the window is settled.
    list($store, $runId, $epoch) = $inCatchUp(['A']);
    $log = new Log();
    $log->changed = ['A' => '400'];
    $cu = new CatchUp($store, ['fence' => $log, 'hash' => $hash]);
    $r1 = $cu->step(800, $runId, $epoch, 100);        // page: requeues A
    check('catchup: the first page requeues', $r1['requeued'] === 1 && $r1['done'] === false);
    $r2 = $cu->step(800, $runId, $epoch, 100);        // page: end of window, dirty -> round 2
    check('catchup: reaching the end of a dirty window starts another round',
        $r2['done'] === false && $store->progressState($runId)['catchupRound'] === 2);
    // Pretend the worker re-scanned it at a version past the change.
    $store->requeue($runId, $epoch, []);
    $claimed = $store->claimPending($runId, 'w1', $epoch, 10);
    $store->commitBatch($runId, 'w1', $epoch, ['bytes' => 0, 'findings' => [],
        'records' => [['ordinal' => $claimed[0]['ordinal'], 'state' => ScanStore::REC_DONE,
                       'version' => '400', 'claim' => $claimed[0]['claim']]]]);
    $r3 = $cu->step(800, $runId, $epoch, 100);        // page: A now settled
    $r4 = $cu->step(800, $runId, $epoch, 100);        // end of a clean round
    check('catchup: a round that changes nothing settles the phase', $r4['done'] === true);

    // A record ALREADY waiting to be re-read is not requeued a second time, and
    // that is what lets the round settle rather than spin: the reconciler has
    // nothing left to say about it, and the worker has not got to it yet.
    list($store, $runId, $epoch) = $inCatchUp(['A']);
    $log = new Log();
    $log->changed = ['A' => '400'];
    $cu = new CatchUp($store, ['fence' => $log, 'hash' => $hash]);
    for ($i = 0; $i < 10; $i++) { $r = $cu->step(800, $runId, $epoch, 100); if ($r['done']) break; }
    check('catchup: a record already queued for re-reading settles the round',
        $r['done'] === true && $r['why'] === null);
    check('catchup: without the run being finishable, because it is still pending',
        $store->manifestComplete($runId) === false);

    // THE BACKSTOP. Records changing inside a FIXED window faster than they can
    // be re-scanned should be impossible, so it is reported rather than retried.
    // Driven properly: the worker re-scans, and the project edits it again
    // before the next round looks.
    list($store, $runId, $epoch) = $inCatchUp(['A']);
    $log = new Log();
    $log->changed = ['A' => '400'];
    $cu = new CatchUp($store, ['fence' => $log, 'hash' => $hash]);
    $bump = 400;
    $r = ['done' => false, 'why' => null];
    for ($i = 0; $i < 40; $i++) {
        $r = $cu->step(800, $runId, $epoch, 100);
        if ($r['done']) break;
        // Stand in for the worker: scan the pending record, then let the
        // project edit it again inside the same window.
        $claimed = $store->claimPending($runId, 'w1', $epoch, 10);
        if ($claimed) {
            $store->commitBatch($runId, 'w1', $epoch, ['bytes' => 0, 'findings' => [],
                'records' => [['ordinal' => $claimed[0]['ordinal'],
                               'state' => ScanStore::REC_DONE, 'version' => (string) $bump,
                               'claim' => $claimed[0]['claim']]]]);
            $bump += 100;
            $log->changed = ['A' => (string) $bump];
        }
    }
    check('catchup: an unsettleable window ends rather than looping', $r['done'] === true);
    check('catchup: and says why', strpos((string) $r['why'], 'kept changing') !== false);
    check('catchup: as a BLOCKING exclusion, so the run cannot claim coverage',
        $store->blockingAggregates($runId) > 0);

    // -- no window at all -----------------------------------------------------
    //
    // Not a failure: the records were examined. It is the difference between
    // "this is the project" and "this is the list we opened with", and the run
    // is required to be able to tell a reader which.
    list($store, $runId, $epoch) = $inCatchUp(['A']);
    $cu = new CatchUp($store, ['hash' => $hash]);
    $r = $cu->step(800, $runId, $epoch, 100);
    check('catchup: with no change log the phase settles at once',
        $r['done'] === true && $r['fenced'] === false);
    check('catchup: recording that the project could not be proved still, not blocking it',
        $store->blockingAggregates($runId) === 0);
    $kinds = [];
    foreach ($store->aggregates($runId) as $a) $kinds[] = $a['kind'];
    check('catchup: as a visible reason rather than a silent absence',
        in_array(CatchUp::K_NOFENCE, $kinds, true));

    // A log that no longer reaches back to the opening fence is the same answer:
    // there is a window, and nobody can see into it.
    list($store, $runId, $epoch) = $inCatchUp(['A']);
    $log = new Log();
    $log->keeps = false;
    $cu = new CatchUp($store, ['fence' => $log, 'hash' => $hash]);
    $r = $cu->step(800, $runId, $epoch, 100);
    check('catchup: a change log that no longer covers the run proves no fence',
        $r['done'] === true && $r['fenced'] === false);
    check('catchup: and no target fence is recorded, so nothing later reads one',
        $store->progressState($runId)['fenceTarget'] === null);

    // A LOG THAT COULD NOT BE READ IS NOT ONE THAT DOES NOT REACH BACK (H11).
    // Both reads answered a failure as the refusal above, so one blip at the
    // end of a scan gave the run up as fence-unprovable for good. The step now
    // throws, writes nothing, and the next step captures the target.
    foreach (['retained', 'now'] as $which) {
        list($store, $runId, $epoch) = $inCatchUp(['A']);
        $log = new Log();
        $log->broken = $which;
        $cu = new CatchUp($store, ['fence' => $log, 'hash' => $hash]);
        $threw = null;
        try { $cu->step(800, $runId, $epoch, 100); } catch (ScanStoreUnavailable $e) { $threw = $e; }
        check("H11 catchup: a failed $which() read stops the step as a storage failure",
            $threw !== null);
        $kinds = [];
        foreach ($store->aggregates($runId) as $a) $kinds[] = $a['kind'];
        check("H11 catchup: and a failed $which() read is not recorded as a fence that cannot be proved",
            !in_array(CatchUp::K_NOFENCE, $kinds, true)
            && $store->progressState($runId)['fenceTarget'] === null);
        $log->broken = null;
        $r = $cu->step(800, $runId, $epoch, 100);
        check("H11 catchup: once $which() reads again, the run is fenced at the target",
            $r['fenced'] === true && $store->progressState($runId)['fenceTarget'] === '500');
    }
    // Through the worker: the request stops as storage, the run stays in catch-up.
    list($store, $runId, $epoch) = $inCatchUp(['A']);
    $log = new Log();
    $log->broken = 'now';
    $w = new ScanWorker($store, ['fence' => new Versions(), 'read' => $reader,
        'evaluate' => $finder(0), 'budget' => $wide(), 'owner' => 'w1', 'attempts' => 3,
        'finalizer' => $fin(), 'catchup' => new CatchUp($store, ['fence' => $log, 'hash' => $hash])]);
    $res = $w->work(800, $runId);
    check('H11 catchup: a worker whose change log failed to read stops on storage, still in catch-up',
        $res['ok'] === false && $res['stop'] === 'storage'
        && $store->run(800, $runId)['phase'] === ScanPhase::CATCH_UP);

    // The fence itself, over a database that answers what the test says (H11).
    $logDb = function ($top = '140', $bottom = '100', $failOn = null) {
        $db = new ScriptedDb();
        $db->rows = ['FROM redcap_projects' => [['redcap_log_event']],
                     'SELECT MAX(log_event_id)' => $top === false ? [] : [[$top]],
                     'SELECT MIN(log_event_id)' => [[$bottom]]];
        if ($failOn !== null) {
            $db->failAt($failOn, 1, new \RuntimeException('MySQL server has gone away', 2006));
        }
        return $db;
    };
    $throws = function (callable $f, $class = ScanStoreUnavailable::class) {
        try { $f(); } catch (\Throwable $e) { return $e instanceof $class; }
        return false;
    };
    $ff = SourceFence::forProject($logDb(), 800);
    check('H11 fence: a readable log is a fence', $ff['ok'] === true && $ff['unreadable'] === false);
    $fence = SourceFence::forProject($logDb('140', '100', 'SELECT MIN(log_event_id)'), 800)['fence'];
    check('H11 fence: a failed read of the bottom throws, rather than answering a pruned log',
        $throws(function () use ($fence) { $fence->retained('120'); }));
    check('H11 fence: and the next read of the bottom answers again',
        $fence->retained('120')['ok'] === true);
    $blip = SourceFence::forProject($logDb('140', '100', 'SELECT MAX(log_event_id)'), 800);
    check('H11 fence: a project whose log top failed to read is unreadable, not unfenceable',
        $blip['ok'] === false && $blip['unreadable'] === true && $blip['fence'] === null);
    $blip = SourceFence::forProject($logDb('140', '100', 'FROM redcap_projects'), 800);
    check('H11 fence: so is one whose log table could not be looked up',
        $blip['ok'] === false && $blip['unreadable'] === true);
    $empty = SourceFence::forProject($logDb(null, null), 800);
    check('H11 fence: a log with no entries for the project is a fact, not a failure',
        $empty['ok'] === false && $empty['unreadable'] === false);
    check('H11 fence: a top that is not a log position throws',
        SourceFence::forProject($logDb('14x'), 800)['unreadable'] === true);
    check('H11 fence: and so does a top with no row at all',
        SourceFence::forProject($logDb(false), 800)['unreadable'] === true);
    $emptied = SourceFence::forProject($logDb('140', null), 800)['fence'];
    check('H11 fence: an emptied log does not reach back, which is an answer',
        $emptied->retained('120')['ok'] === false);
    check('H11 fence: a bottom that is not a log position throws',
        $throws(function () use ($logDb) {
            SourceFence::forProject($logDb('140', 'x1'), 800)['fence']->retained('120'); }));
    check('H11 fence: a window with no opening position is refused, not answered as empty',
        $throws(function () use ($ff) { $ff['fence']->changedSince('', '140', null, 10); },
            \InvalidArgumentException::class));

    // The window is captured ONCE. A window that moved every round would be a
    // phase chasing a project people are still using.
    list($store, $runId, $epoch) = $inCatchUp(['A']);
    $log = new Log();
    $cu = new CatchUp($store, ['fence' => $log, 'hash' => $hash]);
    $cu->step(800, $runId, $epoch, 100);
    $first = $store->progressState($runId)['fenceTarget'];
    $log->top = '9999';
    $cu->step(800, $runId, $epoch, 100);
    check('catchup: the target fence is captured once and does not chase the project',
        $store->progressState($runId)['fenceTarget'] === $first);

    // A SQUEEZED REQUEST READS A PAGE OF THE CHANGE LOG, NOT ONE ROW, and says
    // it moved (review R2S-2). Past the reserve the let-through used to hand
    // catch-up one change-log row per request, reported as nothing at all.
    $thirty = [];
    for ($i = 1; $i <= 30; $i++) $thirty[] = sprintf('C%02d', $i);
    list($store, $runId, $epoch) = $inCatchUp($thirty, '900');
    $log = new Log();
    foreach ($thirty as $id) $log->changed[$id] = '400';    // all inside the reading we have
    $w = new ScanWorker($store, ['fence' => new Versions(), 'read' => $reader,
        'evaluate' => $finder(0), 'owner' => 'w1', 'attempts' => 3, 'finalizer' => $fin(),
        'catchup' => new CatchUp($store, ['fence' => $log, 'hash' => $hash]),
        'budget' => new WorkBudget(['mode' => 'browser', 'memoryLimit' => 100 * $MB,
            'timeLimit' => null, 'startedAt' => microtime(true),
            'probe' => $scripted([[65, 65, 66]])->fn])]);
    $res = $w->work(800, $runId);
    check('squeezed: catch-up reads its ordinary first page of the change log, and reports it',
        $res['ok'] === true && $res['phase'] === ScanPhase::CATCH_UP
        && isset($res['listed']) && $res['listed'] === 25);

    // A GROUP-SCOPED RUN REPORTS ONLY ITS OWN SCOPE'S ACTIVITY (review R5-1).
    // The change log holds every group's rows, and catch-up's page count was
    // the `listed` a work answer carries, so one group's reader watched the
    // other groups' activity. Two of the run's records changed; five records
    // of another group changed too, and one record moved INTO the group.
    list($store, $runId, $epoch) = $inCatchUp(['A', 'B'], '900');
    $log = new Log();
    $log->changed = ['A' => '400', 'B' => '400', 'IN1' => '300',
                     'X1' => '300', 'X2' => '300', 'X3' => '300', 'X4' => '300', 'X5' => '300'];
    $ownGroup = function (array $ids) {
        $out = [];
        foreach ($ids as $id) $out[$id] = ($id[0] !== 'X');
        return $out;
    };
    $scoped = new CatchUp($store, ['fence' => $log, 'hash' => $hash, 'scope' => $ownGroup]);
    $r = $scoped->step(800, $runId, $epoch, 100);
    check('R5-1 catchup: a scoped page lists only the rows inside the run\'s scope',
        $r['listed'] === 3 && $r['scanned'] === 8);
    // Through the worker, to the end: the same scoped run, once with the other
    // group's activity in the log and once without it, lists the same.
    $listedBy = function (array $changed) use ($inCatchUp, $reader, $finder, $wide, $fin, $hash, $ownGroup) {
        list($store, $runId, $epoch) = $inCatchUp(['A', 'B'], '900');
        $log = new Log();
        $log->changed = $changed;
        $w = new ScanWorker($store, ['fence' => new Versions(), 'read' => $reader,
            'evaluate' => $finder(0), 'budget' => $wide(), 'owner' => 'w1', 'attempts' => 3,
            'finalizer' => $fin(),
            'catchup' => new CatchUp($store, ['fence' => $log, 'hash' => $hash, 'scope' => $ownGroup])]);
        $seen = 0;
        for ($i = 0; $i < 10; $i++) {
            $res = $w->work(800, $runId);
            $seen += isset($res['listed']) ? (int) $res['listed'] : 0;
            if (!empty($res['done']) || empty($res['ok'])) break;
        }
        return $seen;
    };
    $own = ['A' => '400', 'B' => '400', 'IN1' => '300'];
    $withOthers = $listedBy($log->changed);
    check('R5-1 catchup: a scoped run\'s work answers never list more than its own scope moved',
        $withOthers > 0 && $withOthers === $listedBy($own));

    // -- promotion: the one place a run may become finished -------------------
    //
    // This is the file the whole rebuild is for. The legacy scan assigned
    // 'complete' at the bottom of a loop a `continue` could skip, so a run that
    // examined nothing produced the same string as one that examined everything.
    $ok = ['blockingAggregates' => 0, 'gapCount' => 0, 'ruleProblems' => 0,
           'uniqueDone' => true, 'uniqueBlocking' => 0, 'rollupDone' => true];
    $run = ['phase' => ScanPhase::ROLLUP, 'fingerprint' => str_repeat('a', 64),
            'policy_revision' => 3, 'fence_target' => '500', 'detail_rows' => 0,
            'detail_bytes' => 0, 'cancel_requested_at' => null];
    $all = [ScanStore::REC_DONE => 10];

    $f = ScanPromotion::facts($run, $all, $ok);
    check('promote: a fenced, finished, unblocked run is ready', $f['ready'] === true);
    check('promote: and earns complete coverage through its fence',
        ScanOutcome::derive($f['facts'])['coverage'] === ScanOutcome::FENCED);

    // Each of these on its own must stop it, and each is a defect the review
    // named by number.
    $no = function ($states, $in, $label) use ($run, $ok, &$n, &$fail) {
        $f = ScanPromotion::facts($run, $states, array_merge($ok, $in));
        \check($label, $f['ready'] === false && is_string($f['why']));
    };
    $no([ScanStore::REC_DONE => 9, ScanStore::REC_PENDING => 1], [],
        'promote: a manifest with a record still waiting is not ready');
    $no([ScanStore::REC_DONE => 9, ScanStore::REC_CLAIMED => 1], [],
        'promote: nor one with a record claimed but never committed');
    $no($all, ['uniqueDone' => false],
        'promote: nor a run whose duplicate groups are still being decided');
    $no($all, ['rollupDone' => false], 'promote: nor one whose summary is unfinished');

    // AN EMPTY CENSUS SATISFIED `pending === 0`, which read as a finished
    // manifest - so a run that listed nothing promoted to
    // complete-through-fence and clean, the same certificate a run that checked
    // everything gets. Reproduced end to end before this block was written.
    $emptyRun = array_merge($run, ['manifest_total' => 0]);
    $ef = ScanPromotion::facts($emptyRun, [], $ok);
    check('promote: a run whose scope held no records is marked empty-scope',
        $ef['facts']['emptyScope'] === true);
    check('promote: and manifestDone is STILL true, which is why the fact was needed',
        $ef['facts']['manifestDone'] === true);
    check('promote: so it cannot be clean however complete the run looks',
        ScanOutcome::mayClaimClean(ScanOutcome::derive($ef['facts'])) === false
        && ScanOutcome::derive($ef['facts'])['coverage'] === ScanOutcome::EMPTY_SCOPE);

    // BOTH SOURCES MUST SAY EMPTY. manifest_total is 0 for the whole of
    // planning and rows exist before they are frozen, so a run mid-plan with
    // records queued is NOT an empty scope.
    $midPlan = ScanPromotion::facts(array_merge($run, ['manifest_total' => 0]),
        [ScanStore::REC_PENDING => 3], $ok);
    check('promote: a manifest with rows queued but not yet frozen is not an empty scope',
        $midPlan['facts']['emptyScope'] === false);

    check('promote: an empty scope does not outrank a cancellation or a failure',
        ScanOutcome::derive(['emptyScope' => true, 'cancelled' => true])['terminal']
            === ScanOutcome::CANCELLED
        && ScanOutcome::derive(['emptyScope' => true, 'failed' => true])['terminal']
            === ScanOutcome::FAILED);
    check('promote: but it does outrank blocked, because nothing can block on nothing',
        ScanOutcome::derive(['emptyScope' => true, 'blocked' => true])['coverage']
            === ScanOutcome::EMPTY_SCOPE);

    // PROVENANCE: the value is OBTAINED from the production producer rather
    // than constructed. `ruleProblems` was read from an aggregate kind nothing
    // ever wrote, so `clean` really meant `violations === 0` and a project
    // whose rules enforce nothing was certified.
    $rp = new ArrayScanStore();
    $rpRun = $rp->startRun(803, ['created_by' => 'alice']);
    $rpId = (int) $rpRun['run']['run_id'];
    check('rule problems: a run with none records no aggregate at all',
        ScanPromotion::noteRuleProblems($rp, $rpId, []) === 0);
    ScanPromotion::noteRuleProblems($rp, $rpId, [
        ['rule' => 1, 'fields' => ['dob'], 'why' => 'configuration error - this rule validates nothing'],
        ['rule' => 4, 'fields' => ['nid'], 'why' => 'instrument xray is not designated to any event'],
    ]);
    $rpTotal = 0;
    foreach ($rp->aggregates($rpId) as $a) {
        if ($a['kind'] === ScanPromotion::RULE_KINDS) $rpTotal += (int) $a['cnt'];
    }
    check('rule problems: the producer writes the kind the clean predicate reads',
        $rpTotal === 2);
    check('rule problems: and it does not block coverage, only clean',
        (int) $rp->blockingAggregates($rpId) === 0);

    // A RULE PROBLEM IS RECORDED ONCE (B4b). The producer wrote one row whose
    // count was the number of problems, through a verb that ADDS - so a plan
    // that ran twice reported twice as many, and the same verb could never have
    // taken the per-record problems, which every record repeats.
    $rpSum = function ($store, $id) {
        return ScanPromotion::tally($store->aggregates($id))['ruleProblems'];
    };
    ScanPromotion::noteRuleProblems($rp, $rpId, [
        ['rule' => 1, 'fields' => ['dob'], 'why' => 'configuration error - this rule validates nothing'],
        ['rule' => 4, 'fields' => ['nid'], 'why' => 'instrument xray is not designated to any event'],
    ]);
    check('rule problems: noted a second time, the same two are still two', $rpSum($rp, $rpId) === 2);
    check('rule problems: and the producer answers with how many DISTINCT ones it was given',
        ScanPromotion::noteRuleProblems($rp, $rpId, [
            ['rule' => 7, 'why' => 'x'], ['rule' => 7, 'why' => 'x'], ['rule' => 7, 'why' => 'y']]) === 2
        && $rpSum($rp, $rpId) === 4);
    check('rule problems: each keeps the sentence that explains it',
        count(array_filter($rp->aggregates($rpId), function ($a) {
            return $a['kind'] === ScanPromotion::RULE_KINDS && is_string($a['samples'])
                && $a['samples'] !== '';
        })) === 4);

    // THE KEY. The rule and what is wrong with it; the sentence only until the
    // code survives the trip from UniversalValidator.
    check('problem key: a code is used as it is',
        ScanPromotion::problemKey(['rule' => 3, 'code' => 'unlocatable', 'why' => 'a'])
            === ['3', 'unlocatable']);
    check('problem key: the sentence does not matter once there is a code',
        ScanPromotion::problemKey(['rule' => 3, 'code' => 'unlocatable', 'why' => 'a'])
            === ScanPromotion::problemKey(['rule' => 3, 'code' => 'unlocatable', 'why' => 'b']));
    $kw = ScanPromotion::problemKey(['rule' => 3, 'why' => 'the same sentence']);
    check('problem key: with no code the sentence stands in, hashed to fit an axis',
        $kw[0] === '3' && strpos($kw[1], 'w:') === 0 && strlen($kw[1]) === 18
        && $kw === ScanPromotion::problemKey(['rule' => 3, 'why' => 'the same sentence'])
        && $kw !== ScanPromotion::problemKey(['rule' => 3, 'why' => 'another sentence'])
        && $kw !== ScanPromotion::problemKey(['rule' => 4, 'why' => 'the same sentence']));
    check('problem key: a problem with nothing in it still has a key, and fits the column',
        ScanPromotion::problemKey([])[0] === '0'
        && strlen(ScanPromotion::problemKey(['rule' => 1, 'code' => str_repeat('c', 900)])[1]) === 255);
    check('marks: a rule problem forbids clean and never caps coverage',
        ScanPromotion::marksFor([['rule' => 1, 'why' => 'x']])[0]['blocks'] === 0);
    check('marks: and a long explanation is bounded before it reaches a TEXT column',
        strlen(ScanPromotion::marksFor([['rule' => 1, 'why' => str_repeat('w', 5000)]])[0]['samples'])
            === ScanPromotion::SAMPLE_BYTES);
    // A strict server refuses a utf8mb4 value that ends inside a character
    // (1366), and the mark travels inside commitBatch(), so a byte cut would
    // roll back the whole batch on every retry. 499 ASCII bytes put the
    // two-byte 'ô' across the 500-byte limit.
    $straddle = ScanPromotion::marksFor([['rule' => 1,
        'why' => str_repeat('w', ScanPromotion::SAMPLE_BYTES - 2) . "C\xC3\xB4te d'Ivoire"]])[0]['samples'];
    check('marks: a cut that falls inside a character backs off to the character before it',
        mb_check_encoding($straddle, 'UTF-8') && strlen($straddle) === ScanPromotion::SAMPLE_BYTES - 1
        && substr($straddle, -1) === 'C');
    $latin1 = ScanPromotion::marksFor([['rule' => 1, 'why' => "caf\xE9 au lait"]])[0]['samples'];
    check('marks: bytes that were never UTF-8 are scrubbed, not stored',
        mb_check_encoding($latin1, 'UTF-8') && strpos($latin1, 'caf') === 0);
    check('marks: a problem with no sentence keeps no sample',
        ScanPromotion::marksFor([['rule' => 1]])[0]['samples'] === null);
    check('rule problems: a fenced, finished, finding-free run with one is NOT clean',
        ScanOutcome::mayClaimClean(ScanOutcome::derive(ScanPromotion::facts(
            $run, $all, array_merge($ok, ['ruleProblems' => $rpTotal]))['facts'])) === false);
    check('rule problems: CONTROL - the same run with none IS clean',
        ScanOutcome::mayClaimClean(ScanOutcome::derive(ScanPromotion::facts(
            $run, $all, array_merge($ok, ['ruleProblems' => 0]))['facts'])) === true);

    // Ready, but with much less to claim.
    $blocked = ScanPromotion::facts($run, [ScanStore::REC_DONE => 9,
        ScanStore::REC_UNREADABLE => 1], $ok);
    check('promote: an unreadable record still finishes the run', $blocked['ready'] === true);
    $o = ScanOutcome::derive($blocked['facts']);
    check('promote: as PARTIAL coverage, never complete',
        $o['coverage'] === ScanOutcome::COV_PARTIAL && ScanOutcome::mayClaimClean($o) === false);

    $uns = ScanPromotion::facts($run, [ScanStore::REC_DONE => 9,
        ScanStore::REC_UNSTABLE => 1], $ok);
    check('promote: so does a record that would not hold still',
        ScanOutcome::derive($uns['facts'])['coverage'] === ScanOutcome::COV_PARTIAL);

    // A TOMBSTONE IS NOT A HOLE. A record deleted during the run cannot be read
    // and never will be; requiring it to reach `done` holds the run open
    // forever, which is C3's mirror case.
    $tomb = ScanPromotion::facts($run, [ScanStore::REC_DONE => 9,
        ScanStore::REC_TOMBSTONE => 1], $ok);
    check('promote: a deleted record does not hold the run open', $tomb['ready'] === true);
    check('promote: and does not block its coverage either',
        ScanOutcome::derive($tomb['facts'])['coverage'] === ScanOutcome::FENCED);

    // WHAT THE ROW WILL SAY WAS NOT EXAMINED is the same sum that blocks
    // `clean`, over the same list - ScanStore::NOT_EXAMINED - so the two cannot
    // come to disagree about a state one of them forgot.
    $mixed = ScanPromotion::facts($run, [ScanStore::REC_DONE => 6,
        ScanStore::REC_UNREADABLE => 1, ScanStore::REC_UNSTABLE => 1,
        ScanStore::REC_UNSTORED => 1, ScanStore::REC_TOMBSTONE => 1], $ok);
    check('promote: every not-examined state is counted for the row, once each',
        $mixed['notExamined'] === 3);
    check('promote: a deleted record is not one of them', $tomb['notExamined'] === 0);
    check('promote: and the count and the block are one question',
        ($mixed['notExamined'] > 0) === $mixed['facts']['blocked']
        && ($tomb['notExamined'] > 0) === $tomb['facts']['blocked']);

    $ug = ScanPromotion::facts($run, $all, array_merge($ok, ['uniqueBlocking' => 1]));
    check('promote: a duplicate group nobody could decide caps coverage',
        ScanOutcome::derive($ug['facts'])['coverage'] === ScanOutcome::COV_PARTIAL);

    // No target fence: every record examined, and no proof the project stood
    // still. That is manifest-complete, and it is a different sentence.
    $nf = ScanPromotion::facts(array_merge($run, ['fence_target' => null]), $all, $ok);
    check('promote: without a proved window the run is manifest-complete, not complete',
        ScanOutcome::derive($nf['facts'])['coverage'] === ScanOutcome::MANIFEST);

    // The configuration moved: FAILED, not partial. A run half-checked against
    // rules that no longer exist describes the project under neither.
    $fp = ScanPromotion::facts($run, $all,
        array_merge($ok, ['fingerprintNow' => str_repeat('b', 64)]));
    check('promote: a changed fingerprint fails the run',
        ScanOutcome::derive($fp['facts'])['terminal'] === ScanOutcome::FAILED);
    $pol = ScanPromotion::facts($run, $all, array_merge($ok, ['policyRevisionNow' => 4]));
    check('promote: so does a privacy policy that tightened mid-run',
        ScanOutcome::derive($pol['facts'])['terminal'] === ScanOutcome::FAILED);
    check('promote: and facts() says which moved, for the stored reason (W7-N11)',
        $fp['configChanged'] === 'rules' && $pol['configChanged'] === 'privacy' && $nf['configChanged'] === null);

    // A cancelled or failed run ends WHATEVER is outstanding. Waiting for a
    // finalizer on a run nobody wants is how a cancelled scan keeps its slot.
    $can = ScanPromotion::facts(array_merge($run, ['cancel_requested_at' => '2026-01-01']),
        [ScanStore::REC_DONE => 5, ScanStore::REC_PENDING => 5],
        array_merge($ok, ['uniqueDone' => false, 'rollupDone' => false]));
    check('promote: a cancelled run finishes even with work outstanding', $can['ready'] === true);
    check('promote: as cancelled',
        ScanOutcome::derive($can['facts'])['terminal'] === ScanOutcome::CANCELLED);

    // The detail budget: every record examined, and the report the reader holds
    // is not the report the run produced.
    $tr = ScanPromotion::facts(array_merge($run, ['detail_rows' => 1000]), $all,
        array_merge($ok, ['maxFindings' => 1000]));
    $o = ScanOutcome::derive($tr['facts']);
    check('promote: a truncated detail budget keeps full coverage',
        $o['coverage'] === ScanOutcome::FENCED);
    check('promote: but forbids clean and says _TRUNCATED',
        ScanOutcome::mayClaimClean($o) === false && ScanOutcome::suffix($o) === '_TRUNCATED');
    // W7-N8: WITH THE COUNT OF WHAT WAS LEFT OUT, truncated is whether there
    // was any. The counters at the budget say nothing about a finding dropped.
    $at = ScanPromotion::facts(array_merge($run, ['detail_rows' => 1000]), $all,
        array_merge($ok, ['maxFindings' => 1000, 'unstored' => 0, 'violations' => 1000]));
    check('W7-N8: a run that stored exactly its budget and left nothing out is not truncated',
        $at['facts']['truncated'] === false && $at['facts']['violations'] === 1000);
    $past = ScanPromotion::facts(array_merge($run, ['detail_rows' => 1000]), $all,
        array_merge($ok, ['maxFindings' => 1000, 'unstored' => 7, 'violations' => 1007]));
    check('W7-N8: one that left any out is, and its violations are every finding found',
        $past['facts']['truncated'] === true && $past['facts']['violations'] === 1007);
    // A summary that never finished is a floor over what was stored, and the
    // ones left out are added to it, never compared with it.
    $floor = ScanPromotion::facts(array_merge($run, ['detail_rows' => 1000]), $all,
        array_merge($ok, ['rollupDone' => false, 'unstored' => 7, 'violations' => 207]));
    check('W7-N8: without a finished summary, violations are the stored floor plus those left out',
        $floor['facts']['violations'] === 1007);

    // Gaps are reported, never violations, and never blocking.
    $gp = ScanPromotion::facts($run, $all, array_merge($ok, ['gapCount' => 3860]));
    $o = ScanOutcome::derive($gp['facts']);
    // LABEL DEGRADATION COMES FROM THE CALLER. facts() read it from the run row,
    // which has no such column, so it was false on every run and the sentence
    // it selects was unreachable.
    $ld = ScanPromotion::facts($run, $all, array_merge($ok, ['labelDegraded' => true]));
    check('promote: label degradation reaches the outcome when the caller reports it',
        $ld['facts']['labelDegraded'] === true
        && strpos(ScanOutcome::derive($ld['facts'])['why'], 'labels could not be read') !== false);
    check('promote: and still does not stop a clean result',
        ScanOutcome::mayClaimClean(ScanOutcome::derive($ld['facts'])) === true);
    check('promote: a key of that name on the run row is not what is read',
        ScanPromotion::facts(array_merge($run, ['labelDegraded' => true]), $all, $ok)
            ['facts']['labelDegraded'] === false);

    check('promote: collection gaps do not stop a clean result',
        ScanOutcome::mayClaimClean($o) === true);
    check('promote: but a caller may not render it without mentioning them',
        $o['mustShowGaps'] === true);

    // -- promotion end to end, through a real store --------------------------
    list($store, $runId, $epoch) = $inCatchUp(['A', 'B']);
    $store->advancePhase($runId, $epoch, ScanPhase::UNIQUE);
    $store->advancePhase($runId, $epoch, ScanPhase::ROLLUP);
    $store->setProgressState($runId, $epoch, ['fenceTarget' => '500']);
    $p = ScanPromotion::promote($store, 800, $runId, $ok);
    check('promote: the run finishes', $p['promoted'] === true);
    check('promote: with a terminal state stored',
        $store->run(800, $runId)['terminal'] === ScanOutcome::COMPLETE);
    // H15. The decision used to exist only in $p; the row said how the run
    // ended and nothing said what it had concluded.
    $after = $store->run(800, $runId);
    check('promote: and the decision is on the row, not only in the answer',
        $after['clean'] !== null && (int) $after['clean'] === 1
        && ScanOutcome::mayClaimClean($p['outcome']) === true);
    check('promote: beside the sentence that explains it',
        $after['terminal_reason'] === $p['outcome']['why']);
    check('promote: and the counts it was decided on',
        (int) $after['not_examined'] === 0 && (int) $after['rule_problem_count'] === 0
        && (int) $after['gap_count'] === 0);
    check('promote: and the project slot released for the next scan',
        $store->startRun(800, ['created_by' => 'bob'])['ok'] === true);
    $again = ScanPromotion::promote($store, 800, $runId, $ok);
    check('promote: a retried finaliser cannot reopen it',
        $again['promoted'] === false && strpos($again['why'], 'already finished') !== false);

    // A run id from another project is not a locator here either.
    $p = ScanPromotion::promote($store, 801, $runId, $ok);
    check('promote: a cross-project run id is refused with the "no such run" wording',
        $p['promoted'] === false && strpos($p['why'], 'no scan with that reference') !== false);

    // THE SAME, WITH A HOLE IN IT. One of three records could not be read, so
    // the row has to say 0 and say how many - through promote(), because that
    // is the only producer of the count and a row of zeroes looks plausible.
    {
        $store = new ArrayScanStore();
        $hid = (int) $store->startRun(802, ['created_by' => 'alice', 'fence_open' => '1'])['run']['run_id'];
        $recs = [];
        foreach (['A', 'B', 'C'] as $id) $recs[] = ['id_bin' => $id, 'hash' => $hash($id), 'dag' => null];
        $store->writeManifest($hid, $recs);
        $hep = (int) $store->run(802, $hid)['lease_epoch'];
        $batch = ['bytes' => 0, 'records' => [], 'findings' => []];
        foreach ($store->claim($hid, 'w1', $hep, 3) as $c) {
            $batch['records'][] = ['ordinal' => $c['ordinal'], 'claim' => $c['claim'], 'version' => '100',
                'state' => $c['ordinal'] === 2 ? ScanStore::REC_UNREADABLE : ScanStore::REC_DONE];
        }
        $store->commitBatch($hid, 'w1', $hep, $batch);
        foreach ([ScanPhase::CATCH_UP, ScanPhase::UNIQUE, ScanPhase::ROLLUP] as $ph) {
            $store->advancePhase($hid, $hep, $ph);
        }
        $store->setProgressState($hid, $hep, ['fenceTarget' => '500']);
        // IN THE STORE, not in the array: promote() counts gaps itself now.
        $store->addAggregate($hid, ScanPromotion::GAP_KINDS, 'fa', '', 4);
        $p = ScanPromotion::promote($store, 802, $hid, $ok);
        $after = $store->run(802, $hid);
        check('promote: a run with an unread record finishes partial',
            $p['promoted'] === true && $after['terminal'] === ScanOutcome::PARTIAL);
        check('promote: recorded as NOT clean, which is a 0 and not an absence',
            $after['clean'] !== null && (int) $after['clean'] === 0);
        check('promote: with the one record it never examined counted on the row',
            (int) $after['not_examined'] === 1);
        check('promote: and the gaps it saw', (int) $after['gap_count'] === 4);
    }

    /* =====================================================================
     * WHAT THE EVALUATION COULD NOT EVALUATE REACHES THE RUN (B4b).
     *
     * durableEvaluateRecord() has always returned `problems` and the worker has
     * never read them. A rule that only fails at run time left every record it
     * met looking examined, and the run free to say clean.
     * ===================================================================== */
    {
        $problemFor = function (array $byRecord) {
            return function ($id, $node) use ($byRecord) {
                return ['findings' => [], 'candidates' => [], 'bytes' => 0, 'why' => null,
                        'problems' => isset($byRecord[$id]) ? $byRecord[$id] : []];
            };
        };
        $P1 = ['rule' => 2, 'fields' => ['a'], 'why' => 'rule 2 refers to a field on no event'];
        $P2 = ['rule' => 5, 'fields' => ['b'], 'why' => 'rule 5 ran out of its evaluation budget'];
        $ids = ['A', 'B', 'C', 'D', 'E', 'F'];
        $every = [];
        foreach ($ids as $id) $every[$id] = [$P1];
        $every['E'][] = $P2;

        // Two records at a time, so one worker meets the same rule in three batches.
        $twoAt = function () {
            return new WorkBudget(['mode' => 'cron', 'memoryLimit' => null, 'timeLimit' => null,
                'min' => 2, 'max' => 2, 'first' => 2, 'startedAt' => microtime(true)]);
        };
        // The same, stopped by its budget after the first batch - see the seam tests.
        $oneBatch = function () use ($scripted, $MB) {
            return new WorkBudget(['mode' => 'cron', 'memoryLimit' => 100 * $MB, 'timeLimit' => null,
                'min' => 2, 'max' => 2, 'first' => 2, 'startedAt' => microtime(true),
                'probe' => $scripted([[10, 10, 12], [10, 10, 12], [10, 10, 12], [10, 10, 12],
                                      [10, 10, 12], [10, 10, 95]])->fn]);
        };
        list($store, $runId) = $fixture($ids);
        $w = new ScanWorker($store, ['fence' => new Versions(), 'read' => $reader,
            'evaluate' => $problemFor($every), 'budget' => $twoAt(), 'owner' => 'w1',
            'attempts' => 3, 'finalizer' => $fin()]);
        $res = $w->work(800, $runId);
        check('problems: the fixture is three batches of one worker', $res['worked'] === 6);
        $rows = array_values(array_filter($store->aggregates($runId), function ($a) {
            return $a['kind'] === ScanPromotion::RULE_KINDS;
        }));
        check('problems: six records meeting one broken rule is ONE rule problem, plus the other',
            ScanPromotion::tally($store->aggregates($runId))['ruleProblems'] === 2);
        check('problems: each is a presence - a row whose count is 1',
            count($rows) === 2 && (int) $rows[0]['cnt'] === 1 && (int) $rows[1]['cnt'] === 1);
        check('problems: keyed by the rule that has it',
            in_array('2', array_column($rows, 'axis1'), true)
            && in_array('5', array_column($rows, 'axis1'), true));
        check('problems: and they cap nothing - every record was still examined',
            (int) $store->blockingAggregates($runId) === 0
            && $store->recordStates($runId) === [ScanStore::REC_DONE => 6]);

        // A SECOND WORKER, which has no memory of the first, says it all again.
        list($store2, $run2) = $fixture($ids, 816);
        $did = 0;
        foreach (['w1', 'w2'] as $owner) {
            $one = new ScanWorker($store2, ['fence' => new Versions(), 'read' => $reader,
                'evaluate' => $problemFor($every), 'budget' => $oneBatch(), 'owner' => $owner,
                'attempts' => 3, 'finalizer' => $fin()]);
            $did += $one->work(816, $run2)['worked'];
        }
        check('problems: two workers, one batch each, both meeting rule 2', $did === 4);
        check('problems: and it is recorded once between them',
            ScanPromotion::tally($store2->aggregates($run2))['ruleProblems'] === 1);

        // NOTHING WRITTEN, NOTHING REMEMBERED. A refused batch kept no mark, so
        // the worker must offer it again with the next one that commits.
        list($inner, $run3) = $fixture(['A', 'B', 'C', 'D'], 817);
        require_once __DIR__ . '/scan_fault_support.php';
        $spy = new class($inner) extends FaultyStore {
            public $offered = [];
            public $refuse = 1;
            public function commitBatch($runId, $owner, $epoch, array $batch, &$heldCount = null,
                                        &$heldOrdinals = null) {
                $this->offered[] = isset($batch['marks']) ? count($batch['marks']) : -1;
                if ($this->refuse-- > 0) return 'refused for the test, and said so at some length';
                return parent::commitBatch($runId, $owner, $epoch, $batch, $heldCount, $heldOrdinals);
            }
        };
        $w3 = new ScanWorker($spy, ['fence' => new Versions(), 'read' => $reader,
            'evaluate' => $problemFor(['A' => [$P1], 'B' => [$P1], 'C' => [$P1], 'D' => [$P1]]),
            'budget' => $twoAt(), 'owner' => 'w1', 'attempts' => 5, 'finalizer' => $fin()]);
        $w3->work(817, $run3);
        $w3->work(817, $run3);
        check('problems: a mark in a refused batch is offered again, not assumed written',
            count($spy->offered) >= 2 && $spy->offered[0] === 1 && $spy->offered[1] === 1);
        // ONCE PER WORKER is an economy, not the guarantee - the store is the
        // guarantee - but it is one statement per batch for the life of a run.
        check('problems: and once it HAS been written this worker stops offering it',
            count($spy->offered) === 3 && $spy->offered[2] === 0);
        check('problems: it landed once',
            ScanPromotion::tally($inner->aggregates($run3))['ruleProblems'] === 1
            && $inner->recordStates($run3) === [ScanStore::REC_DONE => 4]);

        // HELD NOTHING, WROTE NOTHING (review ST-4). A batch whose every claim
        // another worker had already taken commits as `true` - nothing was
        // refused - and writes no mark, because marks travel behind the record
        // fences. The store says so through the held count; the worker must
        // offer the mark again rather than remember it as written.
        list($inner5, $run5) = $fixture(['A', 'B', 'C', 'D'], 819);
        $lost = new class($inner5) extends FaultyStore {
            public $offered = [];
            public $lose = 1;
            public function commitBatch($runId, $owner, $epoch, array $batch, &$heldCount = null,
                                        &$heldOrdinals = null) {
                $this->offered[] = isset($batch['marks']) ? count($batch['marks']) : -1;
                if ($this->lose-- > 0) { $heldCount = 0; $heldOrdinals = []; return true; }
                return parent::commitBatch($runId, $owner, $epoch, $batch, $heldCount, $heldOrdinals);
            }
        };
        $w5 = new ScanWorker($lost, ['fence' => new Versions(), 'read' => $reader,
            'evaluate' => $problemFor(['A' => [$P1], 'B' => [$P1], 'C' => [$P1], 'D' => [$P1]]),
            'budget' => $twoAt(), 'owner' => 'w1', 'attempts' => 5, 'finalizer' => $fin()]);
        $w5->work(819, $run5);
        check('problems: a mark in a batch that held none of its records is offered again',
            count($lost->offered) >= 2 && $lost->offered[0] === 1 && $lost->offered[1] === 1);
        check('problems: and it is recorded once the next batch commits what it holds',
            ScanPromotion::tally($inner5->aggregates($run5))['ruleProblems'] === 1);

        // A RECORD THAT KILLS PHP. A worker killed by memory_limit or
        // max_execution_time inside the read or the evaluation runs nothing
        // after the fatal, so it counts no attempt and leaves its claims. The
        // worker that takes the rows over must count that attempt and take
        // each one ALONE, or a record that kills PHP every time is retried for
        // ever and charges every record that shares its batch.
        $quiet = function ($id, $node) {
            return ['findings' => [], 'candidates' => [], 'bytes' => 0, 'why' => null, 'problems' => []];
        };
        list($store6, $run6) = $fixture(['K1', 'K2', 'K3'], 820);
        $ep6 = (int) $store6->run(820, $run6)['lease_epoch'];
        $store6->claim($run6, 'dead', $ep6, 3);              // and then nothing ran
        ageClaims($store6, $run6, 1000);                     // ... for longer than a live batch takes
        $batches = [];
        $counting = function (array $ids) use ($reader, &$batches) {
            $batches[] = count($ids);
            return $reader($ids);
        };
        $w6 = new ScanWorker($store6, ['fence' => new Versions(), 'read' => $counting,
            'evaluate' => $quiet, 'budget' => $twoAt(), 'owner' => 'w2', 'attempts' => 3,
            'finalizer' => $fin()]);
        $w6->work(820, $run6);
        check('dead worker: the rows it left are taken over one at a time',
            $batches === [1, 1, 1]);
        check('dead worker: and each is examined',
            $store6->recordStates($run6) === [ScanStore::REC_DONE => 3]);

        list($store7, $run7) = $fixture(['K4', 'K5'], 821);
        $ep7 = (int) $store7->run(821, $run7)['lease_epoch'];
        $store7->claim($run7, 'dead', $ep7, 2);
        ageClaims($store7, $run7, 1000);
        $evaluated7 = 0;
        $notes7 = [];
        $w7 = new ScanWorker($store7, ['fence' => new Versions(), 'read' => $reader,
            'evaluate' => function ($id, $node) use ($quiet, &$evaluated7) {
                $evaluated7++;
                return $quiet($id, $node);
            }, 'budget' => $twoAt(), 'owner' => 'w2', 'attempts' => 1, 'finalizer' => $fin(),
            'note' => function ($event, $ctx) use (&$notes7) { $notes7[] = $event; }]);
        $res7 = $w7->work(821, $run7);
        check('dead worker: out of attempts, its rows are retired as not examined, not evaluated again',
            $evaluated7 === 0
            && $store7->recordStates($run7) === [ScanStore::REC_UNREADABLE => 2]);
        // AND THE REQUEST SAYS SO (review R2S-1). A row retired inside the
        // straggler sweep never reaches a batch, so the request reported
        // blocked=0 while two records became blocking exclusions, and nothing
        // anywhere said why.
        check('dead worker: the records the sweep retired are reported as blocked',
            $res7['blocked'] === 2);
        check('dead worker: and the reason is written where an administrator can read it',
            in_array('scan records retired', $notes7, true));

        // A LIVE WORKER IS NOT A DEAD ONE (review R2S-1). W1 has claimed every
        // record in scanning and is evaluating the first when a second driver of
        // the same run - another tab, a reload, Continue pressed while W1's
        // request is still running - reaches catch-up. W1's rows are PENDING and
        // stamped, which is what a dead worker's rows looked like, so W2 charged
        // them; at an attempt limit of 1 it retired all four unexamined. Now
        // W2 takes them over uncharged and examines them, and W1, whose commit
        // then holds nothing, says it examined nothing.
        list($store8, $run8) = $fixture(['L1', 'L2', 'L3', 'L4'], 822);
        $w2res = null;
        $w2 = new ScanWorker($store8, ['fence' => new Versions(), 'read' => $reader,
            'evaluate' => $finder(1), 'budget' => $wide(), 'owner' => 'w2', 'attempts' => 1,
            'finalizer' => $fin()]);
        $one = $finder(1);
        $w1 = new ScanWorker($store8, ['fence' => new Versions(), 'read' => $reader,
            'evaluate' => function ($id, $node) use (&$w2res, $w2, $run8, $one) {
                if ($w2res === null) $w2res = $w2->work(822, $run8);
                return $one($id, $node);
            }, 'budget' => $wide(), 'owner' => 'w1', 'attempts' => 1, 'finalizer' => $fin()]);
        $w1res = $w1->work(822, $run8);
        check('live worker: a second driver arriving mid-batch retires none of the live rows',
            $store8->recordStates($run8) === [ScanStore::REC_DONE => 4]);
        check('live worker: it takes them over and examines every one',
            is_array($w2res) && $w2res['worked'] === 4 && $w2res['blocked'] === 0);
        $active8 = 0;
        foreach ($store8->allFindings() as $f) if ((int) $f['active_slot'] === 1) $active8++;
        check('live worker: so every healthy record has its finding stored, once', $active8 === 4);
        check('live worker: and the worker whose commit held nothing reports nothing examined',
            $w1res['ok'] === true && $w1res['worked'] === 0 && $w1res['findings'] === 0);

        // MORE DEAD ROWS THAN ONE SWEEP WALKS (review R2S-5). The store retires
        // at most SWEEP_WINDOWS windows a call and then answers [] - with rows
        // retired, and live rows still behind them. The worker read every []
        // as "records are still out with another worker" and stopped; it now
        // asks again, because a call that retired something moved the run.
        $deadN = ScanStore::SWEEP_WINDOWS + 4;
        $ids9 = [];
        for ($i = 1; $i <= $deadN + 3; $i++) $ids9[] = 'D' . $i;
        list($store9, $run9) = $fixture($ids9, 823);
        $ep9 = (int) $store9->run(823, $run9)['lease_epoch'];
        $store9->claim($run9, 'dead', $ep9, $deadN);         // and then nothing ran
        $store9->advancePhase($run9, $ep9, ScanPhase::CATCH_UP);
        ageClaims($store9, $run9, 1000);
        $w9 = new ScanWorker($store9, ['fence' => new Versions(), 'read' => $reader,
            'evaluate' => $quiet, 'owner' => 'w2', 'attempts' => 1, 'finalizer' => $fin(),
            'budget' => new WorkBudget(['mode' => 'cron', 'memoryLimit' => null, 'timeLimit' => null,
                'min' => 1, 'max' => 1, 'first' => 1, 'startedAt' => microtime(true)])]);
        $res9 = $w9->work(823, $run9);
        check('sweep: a call that only retired is asked again, not read as waiting',
            $res9['stop'] !== 'waiting' && $res9['worked'] === 3 && $res9['blocked'] === $deadN);
        check('sweep: so the dead rows are retired and the live ones behind them examined',
            $store9->recordStates($run9) === [ScanStore::REC_UNREADABLE => $deadN,
                                              ScanStore::REC_DONE => 3]);

        // A batch with nothing to say carries an empty list, not a missing key.
        list($store4, $run4) = $fixture(['A'], 818);
        $w4 = new ScanWorker($store4, ['fence' => new Versions(), 'read' => $reader,
            'evaluate' => $problemFor([]), 'budget' => $twoAt(), 'owner' => 'w1',
            'attempts' => 3, 'finalizer' => $fin()]);
        $w4->work(818, $run4);
        check('problems: records with none leave no rule-problem row behind',
            ScanPromotion::tally($store4->aggregates($run4))['ruleProblems'] === 0);
    }

    /* =====================================================================
     * VIOLATIONS ARE ACTIVE FINDINGS (B4a).
     *
     * The verdict's `violations` term was the run row's detail_rows, which
     * counts what commitBatch() stored. UniqueFinalizer writes its duplicate
     * findings straight into the findings table, so a project whose only
     * problem was duplicated values had detail_rows = 0 and was certified
     * clean. The summary counts active findings whoever wrote them.
     * ===================================================================== */
    {
        $K = RollupBuilder::KINDS;
        $t = ScanPromotion::tally([
            ['kind' => $K[0], 'axis1' => 'fa', 'cnt' => 3], ['kind' => $K[0], 'axis1' => 'fb', 'cnt' => 2],
            ['kind' => $K[1], 'axis1' => 'duplicate', 'cnt' => 5],
            ['kind' => ScanPromotion::RULE_KINDS, 'cnt' => 2], ['kind' => ScanPromotion::RULE_KINDS, 'cnt' => 1],
            ['kind' => ScanPromotion::GAP_KINDS, 'cnt' => 9],
            ['kind' => 'reconciled-added', 'cnt' => 400], ['cnt' => 7]]);
        check('tally: active findings are the sum over ONE axis, not over all of them',
            $t['violations'] === 5);
        check('tally: rule problems and gaps are summed by their own kinds',
            $t['ruleProblems'] === 3 && $t['gaps'] === 9);
        $skew = ScanPromotion::tally([['kind' => $K[0], 'cnt' => 1], ['kind' => $K[3], 'cnt' => 6]]);
        check('tally: axes that disagree answer with the LARGER, which cannot certify',
            $skew['violations'] === 6);
        check('tally: every rollup axis is read, so a new one cannot be forgotten here',
            count($K) === 4 && ScanPromotion::tally([['kind' => $K[2], 'cnt' => 2]])['violations'] === 2);
        // H9: the findings the detail budget left out are findings, beside the
        // summary of the ones stored; the summary cannot count a row nobody
        // wrote.
        $cut = ScanPromotion::tally([['kind' => $K[0], 'cnt' => 4],
                                     ['kind' => ScanStore::DETAIL_UNSTORED, 'cnt' => 3]]);
        check('tally: findings left out by the detail budget are counted beside the summary (H9)',
            $cut['violations'] === 7 && $cut['stored'] === 4 && $cut['unstored'] === 3);
        check('tally: nothing found is zeroes',
            ScanPromotion::tally([]) === ['violations' => 0, 'stored' => 0, 'unstored' => 0,
                                          'ruleProblems' => 0, 'gaps' => 0]);

        // The term, in facts(). A finished summary is believed over the counter
        // in BOTH directions; an unfinished one only ever raises it.
        $vf = function (array $runOver, array $in) use ($run, $all, $ok) {
            return ScanPromotion::facts(array_merge($run, $runOver), $all,
                array_merge($ok, $in))['facts']['violations'];
        };
        check('facts: with no count supplied the stored-row counter still stands',
            $vf(['detail_rows' => 12], []) === 12);
        check('facts: a finished summary that found some is believed over a counter of zero',
            $vf(['detail_rows' => 0], ['violations' => 4]) === 4);
        check('facts: and over a counter still holding findings that were superseded',
            $vf(['detail_rows' => 12], ['violations' => 0]) === 0);
        check('facts: an UNFINISHED summary can raise the count',
            $vf(['detail_rows' => 2], ['violations' => 9, 'rollupDone' => false]) === 9);
        check('facts: but never lower it, because half a summary undercounts',
            $vf(['detail_rows' => 12], ['violations' => 1, 'rollupDone' => false]) === 12);

        // END TO END. The duplicates-only project: nothing a worker stored,
        // findings the finalizer wrote, a summary that counted them.
        $promoted = function ($pid, callable $seed, array $in = []) use ($inCatchUp, $ok) {
            $store = new ArrayScanStore();
            $rid = (int) $store->startRun($pid, ['created_by' => 'alice', 'fence_open' => '1'])['run']['run_id'];
            $store->writeManifest($rid, [['id_bin' => 'A', 'hash' => hash('sha256', 'A', true), 'dag' => null]]);
            $ep = (int) $store->run($pid, $rid)['lease_epoch'];
            $c = $store->claim($rid, 'w1', $ep, 1);
            $store->commitBatch($rid, 'w1', $ep, ['bytes' => 0, 'findings' => [], 'records' => [[
                'ordinal' => $c[0]['ordinal'], 'claim' => $c[0]['claim'], 'version' => '100',
                'state' => ScanStore::REC_DONE]]]);
            foreach ([ScanPhase::CATCH_UP, ScanPhase::UNIQUE, ScanPhase::ROLLUP] as $ph) {
                $store->advancePhase($rid, $ep, $ph);
            }
            $store->setProgressState($rid, $ep, ['fenceTarget' => '500']);
            $seed($store, $rid);
            $p = ScanPromotion::promote($store, $pid, $rid, array_merge($ok, $in));
            return [$p, $store->run($pid, $rid), $store];
        };
        list($p, $row, $store810) = $promoted(810, function ($store, $rid) use ($K) {
            foreach ($K as $kind) $store->addAggregate($rid, $kind, 'x', null, 1);
        });
        check('duplicates only: the worker stored nothing', (int) $row['detail_rows'] === 0);
        check('duplicates only: the run still finishes complete',
            $p['promoted'] === true && $row['terminal'] === ScanOutcome::COMPLETE);
        check('duplicates only: and the page is told ONE finding, not the counter\'s zero',
            ScanPromotion::findingsOf($store810, $row) === 1);
        check('duplicates only: and is NOT clean, because the summary found what the counter missed',
            $row['clean'] !== null && (int) $row['clean'] === 0
            && ScanOutcome::mayClaimClean($p['outcome']) === false);
        list($p, $row) = $promoted(811, function () {});
        check('duplicates only: the control - the same run with no summary rows - is clean',
            $p['promoted'] === true && (int) $row['clean'] === 1);

        // WHAT THE PAGE IS TOLD WHILE THE RUN IS STILL WORKING is the counter,
        // because there is no summary yet - and the summary is not read for it.
        $live = new ArrayScanStore();
        $lid = (int) $live->startRun(815, ['created_by' => 'alice'])['run']['run_id'];
        $live->addAggregate($lid, $K[0], 'x', null, 40);
        check('findings: a run still working reports what it has stored so far',
            ScanPromotion::findingsOf($live, array_merge($live->run(815, $lid), ['detail_rows' => 7])) === 7);
        // OC-4: a run that ended without a verdict answers with the LARGER of
        // its counter and whatever summary it built, because the counter misses
        // every duplicate the uniqueness finalizer wrote.
        check('findings: one that ended without a verdict answers with the larger of its counter and its summary',
            ScanPromotion::findingsOf($live, array_merge($live->run(815, $lid),
                ['detail_rows' => 7, 'terminal' => ScanOutcome::CANCELLED])) === 40);
        check('findings: and one that reached a verdict answers from the summary',
            ScanPromotion::findingsOf($live, array_merge($live->run(815, $lid),
                ['detail_rows' => 7, 'terminal' => ScanOutcome::PARTIAL])) === 40);

        // A CALLER CANNOT TALK THE VERDICT ROUND. These three used to arrive in
        // the array, and the production caller fed one of them from the wrong place.
        list($p, $row) = $promoted(812, function ($store, $rid) {
            ScanPromotion::noteRuleProblems($store, $rid, [['rule' => 1, 'fields' => ['a'], 'why' => 'x']]);
        }, ['ruleProblems' => 0, 'violations' => 0, 'gapCount' => 0]);
        check('promote: a caller saying "no rule problems" does not outvote the store',
            (int) $row['clean'] === 0 && (int) $row['rule_problem_count'] === 1);
        list($p, $row) = $promoted(813, function () {}, ['ruleProblems' => 5, 'violations' => 5]);
        check('promote: nor does one inventing some', (int) $row['clean'] === 1
            && (int) $row['rule_problem_count'] === 0);

        // NOT READY COSTS NO READ. Promotion is attempted on every pass.
        // Shown with a store whose aggregates() THROWS: had promote() asked, the
        // refusal below would have been an exception instead.
        require_once __DIR__ . '/scan_fault_support.php';
        $spy = (new FaultyStore(new ArrayScanStore()))->failFrom('aggregates');
        $sid = (int) $spy->startRun(814, ['created_by' => 'alice'])['run']['run_id'];
        $spy->writeManifest($sid, [['id_bin' => 'A', 'hash' => hash('sha256', 'A', true), 'dag' => null]]);
        $asked = false;
        try {
            $p = ScanPromotion::promote($spy, 814, $sid, $ok);
        } catch (ScanStoreUnavailable $e) {
            $asked = true;
            $p = ['promoted' => null];
        }
        check('promote: a run with work left is refused without reading a single aggregate',
            $asked === false && $p['promoted'] === false);
        $threw = false;
        try { $spy->aggregates($sid); } catch (ScanStoreUnavailable $e) { $threw = true; }
        check('promote: and that store really does refuse the read, so the check above can fail',
            $threw === true);
    }

    /* =====================================================================
     * NEVER WALK PAST RECORDS NOBODY EXAMINED.
     *
     * The first live pilot: a 39-record run reached rollup-finalize with THREE
     * records examined, and scan-work answered `done: true, worked: 0`. Two
     * faults compounded. A cancelled run returned [] from claimPending, which
     * reads as "this phase is finished" rather than "you may not claim"; and the
     * worker advanced on an empty claim without asking whether the manifest was
     * actually finished.
     *
     * Coverage would still have been reported `partial`, so the run could not
     * have called itself complete - the safety net held. But it abandoned 36
     * records it could have examined and told the client it was done, and both
     * of those are lies of a kind this module exists to not tell.
     * ===================================================================== */
    {
        list($store, $runId) = $fixture(['A', 'B', 'C', 'D', 'E']);

        // Cancellation is what made the pilot's claims come back empty.
        $store->cancel(800, $runId, 'admin');
        $epoch = (int) $store->run(800, $runId)['lease_epoch'];
        check('walk: a cancelled run refuses work rather than reporting none left',
            $store->claimPending($runId, 'w1', $epoch, 5) === false);

        // A worker on a phase whose claims are REFUSED stops where it is.
        list($store2, $runId2) = $fixture(['A', 'B', 'C']);
        $store2->advancePhase($runId2, (int) $store2->run(800, $runId2)['lease_epoch'],
            ScanPhase::CATCH_UP);
        $store2->cancel(800, $runId2, 'admin');
        $w = new ScanWorker($store2, ['fence' => new Versions(), 'read' => $reader,
            'evaluate' => $finder(0), 'budget' => $wide(), 'owner' => 'w1', 'attempts' => 3,
            'finalizer' => $fin()]);
        $res = $w->work(800, $runId2);
        // A CANCELLED RUN IS FINISHED BY WHOEVER ARRIVES NEXT. The two-step
        // exists so the epoch bump lands before the terminal write; nothing
        // owned the second step, so the run held the project's one active slot
        // and pressing Stop then Start reported the project busy with the user's
        // own cancelled run. Indefinitely.
        $after2 = $store2->run(800, $runId2);
        check('walk: a cancelled run is carried to terminal rather than left holding the slot',
            $after2['phase'] === ScanPhase::TERMINAL
            && $after2['terminal'] === ScanOutcome::CANCELLED);
        check('walk: it takes no work on the way', $res['worked'] === 0);
        check('walk: nor claims to be done over a manifest it never finished',
            $res['done'] === false);
        check('walk: and the project may start a new scan straight afterwards',
            $store2->startRun(800, ['created_by' => 'bob'])['ok'] === true);
        // Idempotent: two workers arriving on the same cancelled run is ordinary,
        // and the second must not reopen or re-finish it.
        $again = $w->work(800, $runId2);
        check('walk: a second arrival changes nothing',
            $store2->run(800, $runId2)['terminal'] === ScanOutcome::CANCELLED
            && $again['worked'] === 0);

        // The other half: claims genuinely empty, but records still out with
        // another worker. Catch-up sweeps stragglers BY STATE, and a row claimed
        // moments ago is invisible to that sweep until its claim goes stale -
        // so advancing here abandons it.
        list($store3, $runId3) = $fixture(['A', 'B', 'C']);
        $ep3 = (int) $store3->run(800, $runId3)['lease_epoch'];
        $store3->advancePhase($runId3, $ep3, ScanPhase::CATCH_UP);
        $store3->claimPending($runId3, 'other-worker', $ep3, 3);   // taken, not committed
        $w3 = new ScanWorker($store3, ['fence' => new Versions(), 'read' => $reader,
            'evaluate' => $finder(0), 'budget' => $wide(), 'owner' => 'w1', 'attempts' => 3,
            'finalizer' => $fin()]);
        $res3 = $w3->work(800, $runId3);
        check('walk: records held by another worker keep the phase where it is',
            $store3->run(800, $runId3)['phase'] === ScanPhase::CATCH_UP);
        check('walk: the run waits rather than finishing', $res3['done'] === false
            && $res3['stop'] === 'waiting');
        check('walk: and says so in words a person can act on',
            strpos($res3['why'], 'still being examined') !== false);
        check('walk: nothing was marked examined by waiting',
            $store3->manifestComplete($runId3) === false);

        // And the control: with every record genuinely terminal, the same worker
        // does advance and does finish. Otherwise the guard above would pass by
        // making the scan never finish at all.
        list($store4, $runId4) = $fixture(['A', 'B']);
        $w4 = new ScanWorker($store4, ['fence' => new Versions(), 'read' => $reader,
            'evaluate' => $finder(0), 'budget' => $wide(), 'owner' => 'w1', 'attempts' => 3,
            'finalizer' => $fin()]);
        $res4 = $w4->work(800, $runId4);
        check('walk: a manifest that IS finished still reaches the end of the chain',
            $res4['done'] === true && $store4->manifestComplete($runId4) === true);
    }

    // -- WHEN THE DATABASE FAILS ---------------------------------------------
    //
    // Four store methods used to answer a deadlock with the value that means
    // "the fence refused you", and the worker believed them. claim() and
    // claimPending() came back through refused(): ok TRUE, stop 'fenced', "this
    // scan could not take more work just now" - so the browser, reading ok
    // true, re-issued scan-work at once against a database already in trouble.
    // advancePhase() was worse: false reads as "there is no next phase", and
    // the run was reported DONE over a transaction that never ran.
    //
    // The store now throws ScanStoreUnavailable, and these are the assertions
    // about what the worker does with it.

    require_once __DIR__ . '/scan_fault_support.php';

    /** A frozen five-record run behind a store that fails where the test says. */
    $faulty = function ($methods) use ($fixture) {
        list($inner, $runId) = $fixture(['A', 'B', 'C', 'D', 'E']);
        $store = new FaultyStore($inner);
        $store->failFrom($methods);
        return [$store, $runId, $inner];
    };

    list($store, $runId) = $faulty('claim');
    $notes = [];
    $recorder = function ($event, array $ctx) use (&$notes) { $notes[] = [$event, $ctx]; };
    $w = new ScanWorker($store, ['fence' => new Versions(), 'read' => $reader,
        'evaluate' => $finder(1), 'budget' => $wide(), 'owner' => 'w1', 'attempts' => 3,
        'finalizer' => $fin(), 'note' => $recorder]);
    $res = $w->work(800, $runId);
    check('storage: a failed claim stops the run rather than being fenced out',
        $res['ok'] === false && $res['stop'] === 'storage');
    check('storage: and never says the run is done', $res['done'] === false);
    check('storage: the sentence is not the one a genuine fence gets',
        strpos($res['why'], 'could not take more work just now') === false);
    check('storage: it names no table, column, value or error number',
        preg_match('/\d/', $res['why']) === 0
        && stripos($res['why'], 'uv_') === false && stripos($res['why'], 'sql') === false);
    check('storage: nothing was counted as examined', $res['worked'] === 0);

    // WHAT THE SERVER SAID GOES TO THE LOG, and to nowhere else. A payload key
    // the caller is trusted to remove before answering is a key the second
    // caller forgets.
    check('storage: the failure reaches the module log',
        count($notes) === 1 && $notes[0][0] === 'scan storage failure'
        && strpos($notes[0][1]['detail'], 'Deadlock') !== false);
    check('storage: and the answer the browser gets carries none of it',
        !array_key_exists('fault', $res) && !array_key_exists('detail', $res)
        && strpos(json_encode($res), 'Deadlock') === false);

    // THE ONE THAT REPORTED DONE. The manifest is walked, the batches commit,
    // and the phase advance is where the database gives out.
    list($store, $runId) = $faulty('advancePhase');
    $w = new ScanWorker($store, ['fence' => new Versions(), 'read' => $reader,
        'evaluate' => $finder(1), 'budget' => $wide(), 'owner' => 'w1', 'attempts' => 3,
        'finalizer' => $fin()]);
    $res = $w->work(800, $runId);
    check('storage: a failed phase advance does not certify the run as finished',
        $res['done'] === false && $res['ok'] === false && $res['stop'] === 'storage');

    list($store, $runId) = $faulty('claimPending');
    $w = new ScanWorker($store, ['fence' => new Versions(), 'read' => $reader,
        'evaluate' => $finder(1), 'budget' => $wide(), 'owner' => 'w1', 'attempts' => 3,
        'finalizer' => $fin()]);
    $res = $w->work(800, $runId);
    check('storage: a failed straggler sweep stops rather than reporting none left',
        $res['ok'] === false && $res['stop'] === 'storage' && $res['done'] === false);

    // A WORKER WITH NO `note` STILL ANSWERS. The composition root supplies one;
    // a cron entrypoint that forgets must not turn a database fault into a
    // fatal on top of it.
    list($store, $runId) = $faulty('claim');
    $w = new ScanWorker($store, ['fence' => new Versions(), 'read' => $reader,
        'evaluate' => $finder(1), 'budget' => $wide(), 'owner' => 'w1', 'attempts' => 3,
        'finalizer' => $fin()]);
    check('storage: a worker with nowhere to report to still answers cleanly',
        $w->work(800, $runId)['stop'] === 'storage');

    // A GENUINE FENCE IS UNTOUCHED. This is the half that is easy to lose: only
    // failure moved, and a cancellation still reads as a cancellation.
    list($store5, $runId5) = $fixture(['A', 'B']);
    $store5->cancel(800, $runId5, 'admin');
    $w = new ScanWorker($store5, ['fence' => new Versions(), 'read' => $reader,
        'evaluate' => $finder(1), 'budget' => $wide(), 'owner' => 'w1', 'attempts' => 3,
        'finalizer' => $fin()]);
    $res = $w->work(800, $runId5);
    check('storage: a cancelled run is still a cancellation, not a storage failure',
        $res['stop'] === 'cancelled');

    // A FAILURE IS CHARGED ONCE (review R2S-4). A failed read counted the
    // attempt in one transaction and handed the records back in a second; when
    // the second failed, the rows kept their token on an attempt already
    // counted, aged into "abandoned", and the straggler sweep charged the same
    // failure again. The hand-back is now part of the counting write, so a
    // release that would have failed is never asked for.
    list($store6, $runId6, $inner6) = $faulty('releaseClaims');
    $w = new ScanWorker($store6, ['fence' => new Versions(), 'owner' => 'w1', 'attempts' => 3,
        'read' => function ($ids) { return ['ok' => false, 'data' => [], 'why' => 'export hiccup']; },
        'evaluate' => $finder(1), 'budget' => $wide(), 'finalizer' => $fin()]);
    $res6 = $w->work(800, $runId6);
    check('give-back: a failed read is reported as a failed read, not a storage failure',
        $res6['ok'] === false && $res6['stop'] === 'read');
    ageClaims($inner6, $runId6, 1000);
    $ep6 = (int) $inner6->run(800, $runId6)['lease_epoch'];
    $again6 = $inner6->claimPending($runId6, 'w2', $ep6, 10, 900, 3);
    check('give-back: and its records come back charged once, not taken for a dead worker\'s',
        is_array($again6) && count($again6) === 5 && $again6[0]['abandoned'] === false
        && (int) $again6[0]['attempts'] === 1 && (int) $again6[4]['attempts'] === 1);

    // -- THE SEMAPHORE FAILS THE SAME WAY ------------------------------------
    //
    // WorkerSlots::acquire() is a compare-and-set like everything else, and it
    // is the exact place the 1.9.5 pilot lost a round: a slot that was taken
    // but reported as null produced "this server is running as many scans as it
    // allows at once" over a pool that was free. With ModuleDb refusing to
    // deliver an unknown row count as a number, that arrives as a storage
    // failure instead of as a lie about capacity.
    list($store, $runId) = $fixture(['A']);
    $chatty = new RecordingFramework();
    $chatty->chatty = true;                       // every ROW_COUNT() answers -1
    // One free slot for acquire() to aim at, so the failure is the row count
    // and not an empty pool.
    $chatty->canned = ['FROM ' . Schema::table('scan_worker_slot') => [[1, 0]]];
    $notes = [];
    $w = new ScanWorker($store, ['fence' => new Versions(), 'read' => $reader,
        'evaluate' => $finder(1), 'budget' => $wide(), 'owner' => 'w1', 'attempts' => 3,
        'finalizer' => $fin(), 'slots' => new WorkerSlots(new ModuleDb($chatty)),
        'note' => $recorder]);
    $res = $w->work(800, $runId);
    check('storage: a slot table that cannot answer is not reported as a full server',
        $res['stop'] === 'storage');
    check('storage: and the run is not certified on the way out', $res['done'] === false);
    check('storage: with the slot failure recorded where an administrator can see it',
        count($notes) === 1 && $notes[0][0] === 'scan storage failure');

    /* =====================================================================
     * H9  THE DETAIL BUDGET GATES WHERE THE FINDINGS ARE MERGED
     *
     * ScanPolicy::budgetSpent() had no caller, so a run stored every finding
     * however far past the setting it went. The gate is between evaluate()
     * and the commit: every record is still examined and every finding still
     * counted, and past the budget the finding is left out of the batch and
     * counted as unstored.
     * ===================================================================== */
    {
        $leftOut = function (ScanStore $st, $rid) {
            $n = 0;
            foreach ($st->aggregates($rid) as $a) {
                if ((string) $a['kind'] === ScanStore::DETAIL_UNSTORED) $n += (int) $a['cnt'];
            }
            return $n;
        };
        $gated = function ($evaluate, array $limits) use ($reader, $wide, $fin) {
            return ['fence' => new Versions(), 'read' => $reader, 'evaluate' => $evaluate,
                    'budget' => $wide(), 'owner' => 'w1', 'attempts' => 3, 'finalizer' => $fin(),
                    'limits' => $limits];
        };
        list($store, $runId) = $fixture(['A', 'B', 'C', 'D', 'E']);
        $res = (new ScanWorker($store, $gated($finder(1), ['maxFindings' => 3, 'maxBytes' => 1000000])))
            ->work(800, $runId);
        check('H9: past the budget the worker stores no more findings',
            (int) $store->run(800, $runId)['detail_rows'] === 3 && count($store->allFindings()) === 3);
        check('H9: and counts every finding it found, in its answer and in the store',
            $res['findings'] === 5 && $leftOut($store, $runId) === 2);
        check('H9: every record is still examined',
            $res['worked'] === 5 && (int) $store->run(800, $runId)['manifest_done'] === 5);

        // THE NEXT REQUEST KNOWS. The gate starts from what the run row says
        // is stored, not from zero, so a budget spent by an earlier request
        // stays spent.
        list($store, $runId) = $fixture(['A', 'B']);
        $runsProp = new \ReflectionProperty(ArrayScanStore::class, 'runs');
        $runsProp->setAccessible(true);
        $rows = $runsProp->getValue($store);
        $rows[$runId]['detail_rows'] = 3;
        $runsProp->setValue($store, $rows);
        $res = (new ScanWorker($store, $gated($finder(1), ['maxFindings' => 3, 'maxBytes' => 1000000])))
            ->work(800, $runId);
        check('H9: a request after the budget was spent stores nothing, and counts what it found',
            count($store->allFindings()) === 0 && (int) $store->run(800, $runId)['detail_rows'] === 3
            && $res['findings'] === 2 && $leftOut($store, $runId) === 2);

        // THE BYTES LIMIT, measured as the store measures it: the previews
        // kept. Ten bytes a finding against twenty-five: the third is stored
        // (twenty is under the limit) and the fourth is not.
        $valued = function ($id, $node) use ($finder) {
            $r = $finder(1)($id, $node);
            $r['findings'][0]['value_bin'] = 'abcdefghij';
            return $r;
        };
        list($store, $runId) = $fixture(['A', 'B', 'C', 'D']);
        $res = (new ScanWorker($store, $gated($valued, ['maxFindings' => 100, 'maxBytes' => 25])))
            ->work(800, $runId);
        check('H9: the bytes limit stops storage too, one preview past it at most',
            (int) $store->run(800, $runId)['detail_rows'] === 3
            && (int) $store->run(800, $runId)['detail_bytes'] === 30
            && $res['findings'] === 4 && $leftOut($store, $runId) === 1);

        // No budget handed over is no gate, which is what a worker built
        // without the service's limits always was.
        list($store, $runId) = $fixture(['A', 'B', 'C']);
        (new ScanWorker($store, $gated($finder(2), [])))->work(800, $runId);
        check('H9: a worker given no budget stores everything',
            count($store->allFindings()) === 6 && $leftOut($store, $runId) === 0);

        // TWO WORKERS ON ONE RUN (review rv10b1-4). Worker A claims its first
        // batch, and while it reads those records worker B runs a whole
        // request of three batches and stops. A judged its batches against the
        // stored count its request began with, 0, and stored a whole budget
        // of its own on top of B's. Read for every batch, the count holds
        // what B committed, and A stores nothing past the budget.
        $recs = [];
        for ($i = 0; $i < 12; $i++) $recs[] = sprintf('R%02d', $i);
        list($store, $runId) = $fixture($recs, 807);
        $pairs = function ($probe = null) {
            $o = ['mode' => 'cron', 'memoryLimit' => $probe ? 1000000 : null, 'timeLimit' => null,
                  'min' => 2, 'max' => 2, 'first' => 2, 'startedAt' => microtime(true)];
            if ($probe) $o['probe'] = $probe;
            return new WorkBudget($o);
        };
        // B stops itself once six records are done: a request that ends after
        // a few batches, as a browser pass does.
        $bStops = function ($reset) use ($store, $runId) {
            $u = ((int) $store->run(807, $runId)['manifest_done'] >= 6) ? 990000 : 0;
            return ['used' => $u, 'peak' => $u, 'arena' => $u];
        };
        $limits4 = ['maxFindings' => 4, 'maxBytes' => 1000000];
        $B = new ScanWorker($store, ['fence' => new Versions(), 'read' => $reader, 'evaluate' => $finder(1),
            'budget' => $pairs($bStops), 'owner' => 'tab-B', 'attempts' => 3, 'finalizer' => $fin(),
            'limits' => $limits4]);
        $bRes = null;
        $aRead = function ($ids) use (&$bRes, $B, $runId, $reader) {
            if ($bRes === null) $bRes = $B->work(807, $runId);
            return $reader($ids);
        };
        $aRes = (new ScanWorker($store, ['fence' => new Versions(), 'read' => $aRead, 'evaluate' => $finder(1),
            'budget' => $pairs(), 'owner' => 'tab-A', 'attempts' => 3, 'finalizer' => $fin(),
            'limits' => $limits4]))->work(807, $runId);
        check('rv10b1-4: the fixture ran B inside A\'s first batch, and every record was examined',
            is_array($bRes) && (int) $bRes['worked'] === 6 && (int) $store->run(807, $runId)['manifest_done'] === 12);
        check('rv10b1-4: two workers sharing a run store no more than its budget between them',
            count($store->allFindings()) === 4 && (int) $store->run(807, $runId)['detail_rows'] === 4);
        check('rv10b1-4: and every finding either of them found is counted',
            $leftOut($store, $runId) === 8 && (int) $aRes['findings'] + (int) $bRes['findings'] === 12);

        // A RECORD TAKEN OVER WHILE IT WAS JUDGED, PAST THE BUDGET (review
        // rv10b1-5 M4). Its findings were left out and are the new holder's to
        // count, in the store and in the answer: the store counts the held
        // record only, and the answer, counting both, reported a finding twice
        // between the two workers.
        list($store, $runId) = $fixture(['A', 'B'], 808);
        $ep808 = (int) $store->run(808, $runId)['lease_epoch'];
        $took = false;
        $takeOne = function ($id, $node) use ($store, $runId, $ep808, $finder, &$took) {
            if (!$took) { $store->claimPending($runId, 'w2', $ep808, 1); $took = true; }
            return $finder(1)($id, $node);
        };
        $res = (new ScanWorker($store, $gated($takeOne, ['maxFindings' => 0, 'maxBytes' => 1000000])))
            ->work(808, $runId);
        check('rv10b1-5: a record taken over mid-batch is not this worker\'s to count, stored or not',
            (int) $res['worked'] === 1 && (int) $res['findings'] === 1 && $leftOut($store, $runId) === 1
            && count($store->allFindings()) === 0);
    }

    /* =====================================================================
     * W7-N6  A RECORD EXAMINED ONLY IN PART IS A HOLE IN COVERAGE
     *
     * When the extended-evaluation budget runs out, the evaluator reports
     * `temporal-budget` - "this record was not fully checked" - and that
     * reached the run as a rule problem only: clean was refused and coverage
     * stayed whole, so a proved run said every record was examined.
     * ===================================================================== */
    {
        $partly = function ($id, $node) {
            return ['findings' => [], 'candidates' => [], 'bytes' => 0, 'why' => null,
                    'problems' => $id !== 'B' ? [] : [['rule' => 4, 'code' => 'temporal-budget',
                        'fields' => ['a'], 'why' => 'Extended record evaluation budget exhausted; this '
                                                    . 'record was not fully checked.']]];
        };
        list($store, $runId) = $fixture(['A', 'B', 'C']);
        // A proved window, so only the hole can keep the run from complete.
        $store->setProgressState($runId, (int) $store->run(800, $runId)['lease_epoch'], ['fenceTarget' => '9']);
        (new ScanWorker($store, ['fence' => new Versions(), 'read' => $reader, 'evaluate' => $partly,
            'budget' => $wide(), 'owner' => 'w1', 'attempts' => 3, 'finalizer' => $fin()]))->work(800, $runId);
        check('W7-N6: a record whose extended evaluation ran out leaves a blocking mark',
            $store->blockingAggregates($runId) === 1);
        check('W7-N6: still counted as a rule problem, with the record examined and its findings kept',
            ScanPromotion::tally($store->aggregates($runId))['ruleProblems'] === 1
            && $store->recordStates($runId) === [ScanStore::REC_DONE => 3]);
        ScanPromotion::promote($store, 800, $runId, ['uniqueDone' => true, 'rollupDone' => true]);
        $row = $store->run(800, $runId);
        check('W7-N6: so a proved run is partial, never complete, and says a record was examined in part',
            $row['terminal'] === ScanOutcome::PARTIAL && $row['coverage'] === ScanOutcome::COV_PARTIAL
            && (int) $row['clean'] === 0 && strpos((string) $row['terminal_reason'], 'only in part') !== false);
        check('W7-N6: any other problem code still caps nothing',
            ScanPromotion::marksFor([['rule' => 4, 'code' => 'temporal', 'why' => 'x']])[0]['blocks'] === 0
            && ScanPromotion::marksFor([['rule' => 4, 'why' => 'temporal-budget']])[0]['blocks'] === 0);

        // THE DESIGN QUESTION (review rv10b1): a rule that cannot be judged at
        // one event or instance is an answer, not a record left unexamined.
        // The run is complete and never clean, and says a rule could not be
        // evaluated; see the note on ScanPromotion::PARTLY_EXAMINED.
        foreach (['temporal', 'temporal-group'] as $code) {
            $unjudged = function ($id, $node) use ($code) {
                return ['findings' => [], 'candidates' => [], 'bytes' => 0, 'why' => null,
                        'problems' => $id !== 'B' ? [] : [['rule' => 4, 'code' => $code, 'fields' => ['a'],
                            'why' => 'Extended validation unavailable at event 41, instance 2: unresolved reference']]];
            };
            list($store, $runId) = $fixture(['A', 'B', 'C'], $code === 'temporal' ? 809 : 810);
            $pidT = $code === 'temporal' ? 809 : 810;
            $store->setProgressState($runId, (int) $store->run($pidT, $runId)['lease_epoch'], ['fenceTarget' => '9']);
            (new ScanWorker($store, ['fence' => new Versions(), 'read' => $reader, 'evaluate' => $unjudged,
                'budget' => $wide(), 'owner' => 'w1', 'attempts' => 3, 'finalizer' => $fin()]))->work($pidT, $runId);
            ScanPromotion::promote($store, $pidT, $runId, ['uniqueDone' => true, 'rollupDone' => true]);
            $row = $store->run($pidT, $runId);
            check("rv10b1 design: a `$code` problem leaves coverage complete, the run not clean, the rule counted",
                $store->blockingAggregates($runId) === 0
                && $row['terminal'] === ScanOutcome::COMPLETE && $row['coverage'] === ScanOutcome::FENCED
                && (int) $row['clean'] === 0
                && (int) $row['rule_problem_count'] === 1);
        }
    }

    /* =====================================================================
     * W7-N9, R3S-N4  THE DUPLICATE PHASE HAS A PROGRESS CLOCK, AND A STEP
     *                THAT DID NOTHING AND SAYS WHY ENDS THE REQUEST
     *
     * progress_at moved only when records finished, and unique-finalize
     * finishes none, so a long one read as stalled and, to the reaper, as
     * abandoned. And a step's why was dropped: a finalizer that could not
     * write was stepped again until the request's budget ran out.
     * ===================================================================== */
    /** Answers from a script, the last one repeated; a hook may act first. */
    class ScriptedFinalizer implements DuplicateFinalizer
    {
        public $script;
        public $calls = 0;
        public $hook;
        public function __construct(array $script, $hook = null)
        {
            $this->script = $script;
            $this->hook = $hook;
        }
        public function step($generationId, $limit = 500, $readLimit = null)
        {
            $i = min($this->calls, count($this->script) - 1);
            $this->calls++;
            if (is_callable($this->hook)) ($this->hook)($this->calls);
            // A bound on a loop that never stops: done at the 20th step.
            if ($this->calls >= 20) return ['done' => true] + self::zero();
            return $this->script[$i] + self::zero();
        }
        public static function zero()
        {
            return ['done' => false, 'groups' => 0, 'verified' => 0, 'emitted' => 0,
                    'published' => 0, 'collisions' => 0, 'read' => 0, 'why' => null];
        }
    }
    {
        $poke = function (ArrayScanStore $store, $runId, array $set) {
            $p = new \ReflectionProperty(ArrayScanStore::class, 'runs');
            $p->setAccessible(true);
            $runs = $p->getValue($store);
            foreach ($set as $k => $v) $runs[$runId][$k] = $v;
            $p->setValue($store, $runs);
        };
        $OLD = '2000-01-01 00:00:00';
        $inUnique = function ($pid) use ($fixture, $poke, $OLD) {
            list($store, $runId) = $fixture(['A'], $pid);
            $ep = (int) $store->run($pid, $runId)['lease_epoch'];
            foreach ([ScanPhase::CATCH_UP, ScanPhase::UNIQUE] as $p) $store->advancePhase($runId, $ep, $p);
            $poke($store, $runId, ['progress_at' => $OLD, 'updated_at' => $OLD]);
            return [$store, $runId];
        };
        $drive = function ($store, $pid, $runId, $f) use ($reader, $finder, $wide) {
            return (new ScanWorker($store, ['fence' => new Versions(), 'read' => $reader,
                'evaluate' => $finder(0), 'budget' => $wide(), 'owner' => 'w1', 'attempts' => 3,
                'finalizer' => $f]))->work($pid, $runId);
        };

        // A step that found groups moves the clock, through the store's fence.
        list($store, $runId) = $inUnique(840);
        $f = new ScriptedFinalizer([['groups' => 2], ['done' => true]]);
        $res = $drive($store, 840, $runId, $f);
        $row = $store->run(840, $runId);
        check('W7-N9: a duplicate step that found groups moves progress_at and updated_at',
            $res['ok'] === true && $f->calls === 2 && $row['progress_at'] !== $OLD
            && $row['updated_at'] !== $OLD);

        // A step that only re-read records (its group restarted) moves nothing
        // forward: the clock stays, and the loop goes on, because it said why
        // AND did work.
        list($store, $runId) = $inUnique(841);
        $f = new ScriptedFinalizer([['read' => 3, 'why' => 'a record in this duplicate group changed '
                                     . 'while it was being checked, so the group is being checked again'],
                                    ['done' => true]]);
        $res = $drive($store, 841, $runId, $f);
        check('W7-N9: a step that only re-read records leaves progress_at where it was',
            $store->run(841, $runId)['progress_at'] === $OLD);
        check('R3S-N4: and is not a refusal: the next step is taken',
            $res['ok'] === true && $f->calls === 2 && $res['stop'] !== 'finalizer');

        // A step that did nothing and said why ends the request with that why.
        list($store, $runId) = $inUnique(842);
        $f = new ScriptedFinalizer([['why' => UniqueFinalizer::NO_SEQ]]);
        $res = $drive($store, 842, $runId, $f);
        check('R3S-N4: a finalizer step that did nothing and says why is taken once, not until the budget ends',
            $f->calls === 1);
        check('R3S-N4: and the request stops with that why, ok:false, still in the duplicate phase',
            $res['ok'] === false && $res['stop'] === 'finalizer' && $res['why'] === UniqueFinalizer::NO_SEQ
            && $res['done'] === false && $res['phase'] === ScanPhase::UNIQUE
            && $store->run(842, $runId)['phase'] === ScanPhase::UNIQUE);
        check('W7-N9: and a refused step does not move the clock',
            $store->run(842, $runId)['progress_at'] === $OLD);
        // The same words with no why are a step that moved state the counts do
        // not show (a verified group going on to be written), and go on.
        list($store, $runId) = $inUnique(843);
        $f = new ScriptedFinalizer([[], ['done' => true]]);
        $res = $drive($store, 843, $runId, $f);
        check('R3S-N4: a step with no counts and no why is still stepped past', $f->calls === 2
            && $res['ok'] === true);

        // The clock is fenced: a worker whose lease moved during the step stops
        // as any refused fence does, and takes no second step.
        list($store, $runId) = $inUnique(844);
        $f = new ScriptedFinalizer([['groups' => 1], ['done' => true]],
            function ($n) use ($poke, $store, $runId) {
                if ($n === 1) $poke($store, $runId, ['lease_epoch' => 99]);
            });
        $res = $drive($store, 844, $runId, $f);
        check('W7-N9: a progress write the lease refuses ends the request as a refused fence',
            $res['ok'] === true && $res['stop'] === 'fenced' && $f->calls === 1
            && $store->run(844, $runId)['progress_at'] === $OLD);
    }

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
