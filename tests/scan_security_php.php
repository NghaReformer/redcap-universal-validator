<?php
/**
 * scan_security_php.php — the permission matrix, the terminal-state table, the
 * HMAC purpose separation, and the policy floor.
 *
 * All four are PURE: they take rights arrays, run facts, bytes and settings, and
 * return answers. That is deliberate design, not luck — a security decision that
 * needs a database to test is a security decision that will be tested against a
 * mock of a database, which is how this module previously shipped a control that
 * passed every test and did nothing.
 *
 * Every denial below is checked twice: that it denies, and that it denies
 * WITHOUT saying something it should not. A refusal that distinguishes "no such
 * run" from "not yours" is an oracle.
 *
 * Run:  php tests/scan_security_php.php
 */

namespace {
    require_once __DIR__ . '/../php/ScanPageView.php';
    require_once __DIR__ . '/../php/Scan/ScanOutcome.php';
    // ScanAuthorization composes the store contract's refusal wording; the
    // sentence itself belongs to the contract, so that has to be loaded too.
    require_once __DIR__ . '/../php/Scan/ScanStore.php';
    // promotion's facts() is the producer of the outcome table's inputs, and is
    // pure: a run row and a census in, facts out.
    require_once __DIR__ . '/../php/Scan/ScanPhase.php';
    require_once __DIR__ . '/../php/Scan/ScanPromotion.php';
    require_once __DIR__ . '/../php/Scan/ScanAuthorization.php';
    require_once __DIR__ . '/../php/Scan/Hmac.php';
    require_once __DIR__ . '/../php/Scan/ScanPolicy.php';
    require_once __DIR__ . '/../php/Scan/DbError.php';
    // R2O-1 drives promotion over the real in-memory store, and the rollup's
    // axis list is what the summary it reads is keyed by.
    require_once __DIR__ . '/../php/Scan/Schema.php';
    require_once __DIR__ . '/../php/Scan/ScanDb.php';
    require_once __DIR__ . '/../php/Scan/ArrayScanStore.php';
    require_once __DIR__ . '/../php/Scan/RollupBuilder.php';

    $n = 0; $fail = 0;
    function check($label, $cond) {
        global $n, $fail; $n++;
        if (!$cond) { $fail++; fwrite(STDERR, "FAIL: $label\n"); }
    }
}

namespace ExternalModules {
    /**
     * A framework user that answers the one question that matters here.
     *
     * Declared rather than mocked loosely on purpose: isSuperUser() is a real
     * method on the framework's User, and the module asks it through
     * is_callable - never method_exists, which answers false for anything the
     * framework serves via __call() and is how v1.4.0 shipped a dead feature.
     */
    class SuperUser {
        private $super;
        public function __construct($super) { $this->super = (bool) $super; }
        public function isSuperUser() { return $this->super; }
    }
}

namespace INSPIRE\UniversalValidator\Scan {

    use function check;

    /** A rights row: design, full export, all three forms, unrestricted. */
    function rights($over = []) {
        return array_merge([
            'design' => true,
            'data_export_tool' => '1',
            'group_id' => null,
            'forms' => ['fa' => '1', 'fb' => '2', 'fc' => '3'],
        ], $over);
    }
    $ENT = ['fa', 'fb', 'fc'];

    /* =====================================================================
     * START  design + every entitled form + FULL export + resolvable scope
     * ===================================================================== */
    {
        check('start: a fully entitled user may start',
            ScanAuthorization::mayStart(rights(), $ENT)['ok'] === true);

        check('start: no design rights, no scan',
            ScanAuthorization::mayStart(rights(['design' => false]), $ENT)['ok'] === false);
        check('start: an unreadable rights shape is a denial, not a default',
            ScanAuthorization::mayStart('not-an-array', $ENT)['ok'] === false);
        check('start: and so is a null one',
            ScanAuthorization::mayStart(null, $ENT)['ok'] === false);

        // The plan requires FULL identified-data export rights, because the run
        // STORES values and what is stored outlives the level the reader had.
        foreach (['0', '2', '3', '', 'x'] as $lvl) {
            check("start: export level '$lvl' may not start a run",
                ScanAuthorization::mayStart(rights(['data_export_tool' => $lvl]), $ENT)['ok'] === false);
        }
        check('start: only Full Data Set may',
            ScanAuthorization::mayStart(rights(['data_export_tool' => '1']), $ENT)['ok'] === true);
        check('start: a missing export key is a denial',
            ScanAuthorization::mayStart(['design' => true, 'forms' => ['fa' => '1']], ['fa'])['ok'] === false);

        // THE API SHAPE. The framework's User::getRights() returns the
        // `redcap_user_rights` column `data_export_tool`; REDCap's own API
        // payloads carry the same value as `data_export`. A build handing back
        // the API shape read as "no export rights at all" - a denial
        // indistinguishable from a correctly refused user, and the reason the
        // first live pilot was refused by an account REDCap's own User Rights
        // page showed as Full Data Set.
        $api = ['design' => true, 'forms' => ['fa' => '1'], 'data_export' => '1'];
        check('start: Full Data Set under the API key starts a run',
            ScanAuthorization::mayStart($api, ['fa'])['ok'] === true);
        check('start: and a restricted level under that key still does not',
            ScanAuthorization::mayStart(array_merge($api, ['data_export' => '2']), ['fa'])['ok'] === false);
        // Both keys is not a way to smuggle a level past the column REDCap
        // actually stores: the stored column wins.
        check('start: the stored column outranks the API alias',
            ScanAuthorization::mayStart(array_merge($api,
                ['data_export_tool' => '2', 'data_export' => '1']), ['fa'])['ok'] === false);

        // A REFUSAL THAT CANNOT BE DIAGNOSED IS A SUPPORT TICKET. The message
        // says what was actually read, which a user can already see on their own
        // User Rights page, so it discloses nothing and it is the difference
        // between "ask your administrator" and a fix.
        $whyDeid = ScanAuthorization::mayStart(rights(['data_export_tool' => '2']), $ENT)['why'];
        check('start: the refusal names the level it read',
            strpos($whyDeid, 'De-Identified') !== false);
        $whyNone = ScanAuthorization::mayStart(
            ['design' => true, 'forms' => ['fa' => '1']], ['fa'])['why'];
        check('start: and says so when the level was absent entirely',
            strpos($whyNone, 'no export level was present') !== false);
        check('start: naming both keys it looked under, for whoever has to fix it',
            strpos($whyNone, 'data_export_tool') !== false
            && strpos($whyNone, 'data_export') !== false);
        $whyOdd = ScanAuthorization::mayStart(rights(['data_export_tool' => 'x']), $ENT)['why'];
        check('start: an unrecognised level is refused AND quoted',
            strpos($whyOdd, 'unrecognised') !== false && strpos($whyOdd, 'x') !== false);

        // -- the administrator, who has no rights row to read -----------------
        //
        // A REDCap super-user bypasses project rights entirely, so the framework
        // hands back design rights and NO export level and NO per-instrument
        // map. Every gate read those absences as "no rights" and refused the one
        // category of user who can already export the whole project from
        // REDCap's own exporter. The first live pilot was refused exactly this
        // way, twice over: once on the export level, once on the form map.
        $PV = '\INSPIRE\UniversalValidator\ScanPageView';
        $adminUser = new \ExternalModules\SuperUser(true);
        $plainUser = new \ExternalModules\SuperUser(false);

        check('admin: the framework is ASKED, never inferred from absence',
            $PV::isAdministrator($adminUser) === true
            && $PV::isAdministrator($plainUser) === false
            && $PV::isAdministrator(null) === false);

        $bare = ['design' => true];               // what a super-user's rights look like
        $asAdmin = $PV::normalizeRights($bare, $adminUser);
        check('admin: an administrator resolves to full export rights',
            $PV::exportLevel($asAdmin) === '1');
        check('admin: and is marked as one, by name rather than by inference',
            $asAdmin['superUser'] === true);
        check('admin: so a run may start with no rights row at all',
            ScanAuthorization::mayStart($asAdmin, ['fa', 'fb', 'fc'])['ok'] === true);
        check('admin: and every instrument is readable, there being no map',
            ScanAuthorization::mayRead($asAdmin, ['fa', 'fb'], null)['ok'] === true);

        // THE INVERSE, which is where the risk actually lives. The same absence
        // in a NON-administrator must still be a refusal - otherwise this fix is
        // a hole, not a fix.
        $asPlain = $PV::normalizeRights($bare, $plainUser);
        check('admin: the same bare rights for an ordinary user still refuse',
            ScanAuthorization::mayStart($asPlain, ['fa'])['ok'] === false);
        check('admin: and that user is not marked as an administrator',
            $asPlain['superUser'] === false);
        check('admin: an ordinary user still cannot read a form with no entry',
            ScanAuthorization::mayRead($asPlain, ['fa'], null)['ok'] === false);

        // An administrator who DOES have an explicit rights row keeps it. A
        // deliberate restriction somebody typed outranks an inference we made.
        $restricted = $PV::normalizeRights(
            ['design' => true, 'data_export_tool' => '2'], $adminUser);
        check('admin: an explicit export level is never overwritten',
            $PV::exportLevel($restricted) === '2');
        check('admin: so an explicitly de-identified administrator may not start',
            ScanAuthorization::mayStart($restricted, ['fa'])['ok'] === false);

        // Unreadable stays unreadable. Manufacturing an entitlement out of a
        // shape we could not read is the fail-open direction, administrator or
        // not, and it is the one thing this must never do.
        check('admin: an unreadable rights shape is still unreadable',
            $PV::normalizeRights(null, $adminUser) === null);
        check('admin: and a scan over it is still refused',
            ScanAuthorization::mayStart(null, ['fa'])['ok'] === false);

        // One reader, three callers. The value ceiling and the download gate
        // must agree with the start gate about where the level lives, or a user
        // is refused a run and granted a raw-value export in the same request.
        check('rights: the value ceiling reads the API key too',
            \INSPIRE\UniversalValidator\ScanPageView::valueCeilingFor(
                ['data_export' => '1']) === 'raw');
        check('rights: and so does the download gate',
            \INSPIRE\UniversalValidator\ScanPageView::mayExportFor(['data_export' => '1']) === true);
        check('rights: an absent level is still locations-only',
            \INSPIRE\UniversalValidator\ScanPageView::valueCeilingFor(['design' => true]) === 'locations');
        check('rights: and still no download',
            \INSPIRE\UniversalValidator\ScanPageView::mayExportFor(['design' => true]) === false);

        // WHOLE-REPORT DENIAL. One barred instrument refuses the run - filtering
        // rows would leak through counts, rollups, filter options and filenames.
        check('start: ONE inaccessible instrument refuses the WHOLE run',
            ScanAuthorization::mayStart(rights(['forms' => ['fa' => '1', 'fb' => '0', 'fc' => '1']]),
                $ENT)['ok'] === false);
        check('start: a form with NO entry is barred, not assumed open',
            ScanAuthorization::mayStart(rights(['forms' => ['fa' => '1', 'fb' => '1']]),
                $ENT)['ok'] === false);
        check('start: an unreadable forms map clears nothing',
            ScanAuthorization::mayStart(rights(['forms' => 'broken']), $ENT)['ok'] === false);
        // Levels 2 and 3 can READ a form, so they do not bar it.
        check('start: read-only and edit-survey levels can read a form',
            ScanAuthorization::mayStart(rights(), ['fb', 'fc'])['ok'] === true);

        // A dependency whose owning form is unknown cannot be checked, so it is
        // refused rather than omitted from the entitlement set.
        check('start: unknown field ownership refuses the run',
            ScanAuthorization::mayStart(rights(), $ENT, true)['ok'] === false);

        // The entitlement set is EVERY form the run reads, not just rule hosts:
        // a condition operand on a barred form decides the verdict too.
        check('start: a dependency-only instrument counts toward entitlement',
            ScanAuthorization::mayStart(rights(['forms' => ['fa' => '1', 'fb' => '1', 'fc' => '0']]),
                ['fa', 'fb', 'fc'])['ok'] === false);
    }

    /* =====================================================================
     * READ / WORK  the same entitlement, re-evaluated, plus exact scope
     * ===================================================================== */
    {
        // THE NUMERIC GROUP ID ON BOTH SIDES, because that is the only axis
        // production has. This matrix used to be internally consistent on DAG
        // NAMES while the planning suite was internally consistent on ids, and
        // neither suite ever held both sides - which is how 1,228 green checks
        // sat over a page that produced a name and a store that compared an id.
        // Reseeding it here is not cosmetic: readable() derives the scope from
        // $rights['group_id'], so a name in that key tests a value production
        // can never contain.
        $dagUser = rights(['group_id' => 7]);

        check('read: an unrestricted user may read a project-wide run',
            ScanAuthorization::mayRead(rights(), $ENT, null)['ok'] === true);
        check('read: and a DAG-scoped one',
            ScanAuthorization::mayRead(rights(), $ENT, '7')['ok'] === true);

        check('read: a DAG user may read their OWN group\'s run',
            ScanAuthorization::mayRead($dagUser, $ENT, '7')['ok'] === true);
        check('read: but NOT another group\'s',
            ScanAuthorization::mayRead($dagUser, $ENT, '31')['ok'] === false);
        check('read: nor a project-wide run, which is wider than their scope',
            ScanAuthorization::mayRead($dagUser, $ENT, null)['ok'] === false);

        // NON-DISCLOSING: "another group's run" and "no such run" must read the
        // same, or the message is an existence oracle.
        $other = ScanAuthorization::mayRead($dagUser, $ENT, '31');
        check('read: the cross-scope refusal does not confirm the run exists',
            strpos($other['why'], '31') === false && strpos($other['why'], 'exist') === false
            && strpos($other['why'], 'another') === false);
        // WORD FOR WORD (review rv5b-4): the comment above was the intent, and
        // three different sentences were the code.
        foreach (['read' => function ($scope) use ($dagUser, $ENT) {
                      return ScanAuthorization::mayRead($dagUser, $ENT, $scope);
                  },
                  'work' => function ($scope) use ($dagUser, $ENT) {
                      return ScanAuthorization::mayWork($dagUser, $ENT, $scope);
                  },
                  'cancel' => function ($scope) use ($dagUser, $ENT) {
                      return ScanAuthorization::mayCancel($dagUser, $ENT, $scope);
                  }] as $verb => $ask) {
            check("rv5b-4 $verb: another group's run is refused in the words a missing run gets",
                $ask('31')['ok'] === false && $ask('31')['why'] === ScanAuthorization::NO_RUN);
            check("rv5b-4 $verb: and so is a whole-project run",
                $ask(null)['ok'] === false && $ask(null)['why'] === ScanAuthorization::NO_RUN);
            check("rv5b-4 $verb control: the reader's own group's run is not refused",
                $ask('7')['ok'] === true);
        }
        check('rv5b-4: the scope pre-check refuses the same way',
            ScanAuthorization::mayTouchScope($dagUser, '31')['why'] === ScanAuthorization::NO_RUN
            && ScanAuthorization::mayTouchScope($dagUser, null)['why'] === ScanAuthorization::NO_RUN
            && ScanAuthorization::mayTouchScope($dagUser, '7')['ok'] === true
            && ScanAuthorization::mayTouchScope(rights(), null)['ok'] === true);

        // Rights revoked mid-run stop reads; the run id does not restore them.
        check('read: revoked design rights stop reading an existing run',
            ScanAuthorization::mayRead(rights(['design' => false]), $ENT, null)['ok'] === false);
        check('read: a downgraded export level stops reading it too',
            ScanAuthorization::mayRead(rights(['data_export_tool' => '2']), $ENT, null)['ok'] === false);
        check('read: and losing access to one instrument refuses the whole run',
            ScanAuthorization::mayRead(rights(['forms' => ['fa' => '1', 'fb' => '0', 'fc' => '1']]),
                $ENT, null)['ok'] === false);

        check('work: carries exactly the read entitlement',
            ScanAuthorization::mayWork($dagUser, $ENT, '7')['ok'] === true
            && ScanAuthorization::mayWork($dagUser, $ENT, '31')['ok'] === false);

        // B3: THE AXIS AS A PROPERTY, not as a reseeded fixture. The second
        // half is the load-bearing one - it asserts that a NAME in scope_dag is
        // REFUSED, so a revert that puts the name back fails here loudly rather
        // than merely failing to match somewhere quieter. This is the check a
        // future author reads to learn which value belongs in that column.
        check('B3: the rights key this class reads and the scope it compares are ONE axis '
            . '- the numeric group id',
            ScanAuthorization::mayWork(rights(['group_id' => 7]), $ENT, '7')['ok'] === true
            && ScanAuthorization::mayWork(rights(['group_id' => 7]), $ENT, 'north')['ok'] === false);
    }

    /* =====================================================================
     * CANCEL  wider than working, and never across scopes
     * ===================================================================== */
    {
        $dagUser = rights(['group_id' => 7]);
        check('cancel: an unrestricted user may cancel a global run',
            ScanAuthorization::mayCancel(rights(), $ENT, null)['ok'] === true);
        check('cancel: a DAG user may NOT cancel a global run',
            ScanAuthorization::mayCancel($dagUser, $ENT, null)['ok'] === false);
        check('cancel: a DAG user may cancel their own group\'s run',
            ScanAuthorization::mayCancel($dagUser, $ENT, '7')['ok'] === true);
        // The rule is SCOPE, not ownership - stated without the two parameters
        // that used to carry it. They were inert: mayCancel compared them and
        // then returned the same expression either way, so this check passed
        // over a control that decided nothing. Asserting the rule directly is
        // what makes it a test rather than a decoration.
        check('cancel: even one another user in that group started',
            ScanAuthorization::mayCancel($dagUser, $ENT, '7')['ok'] === true);
        check('cancel: but not another group\'s run',
            ScanAuthorization::mayCancel($dagUser, $ENT, '31')['ok'] === false);
        check('cancel: an unrestricted user may cancel any DAG run',
            ScanAuthorization::mayCancel(rights(), $ENT, '31')['ok'] === true);
        check('cancel: an unentitled user may cancel nothing',
            ScanAuthorization::mayCancel(rights(['design' => false]), $ENT, '7')['ok'] === false);
    }

    /* =====================================================================
     * NON-DISCLOSURE  busy, pre-fence status, DAG drift
     * ===================================================================== */
    {
        // ON THE SENTENCE, which is what an operator reads, rather than on a
        // wrapper that reshapes it. ScanAuthorization::busy() used to own this
        // and nothing called it: both stores wrote their own copy, one of which
        // had already lost a sentence. The wording now belongs to the store
        // contract and both stores answer with it, so these properties are
        // asserted where they are actually produced.
        $busy = ['ok' => false, 'busy' => true, 'why' => ScanStore::BUSY_WHY, 'scope' => null];
        check('busy: the contract owns one refusal sentence',
            is_string(ScanStore::BUSY_WHY) && ScanStore::BUSY_WHY !== '');
        // On the PROPERTY, not on substrings: 'id' matches inside "validation"
        // and 'run' inside "running", so a substring check fails while the
        // property holds - the direction that teaches you to loosen a real test.
        // What must not appear is an identifier, a scope, an owner or a number.
        check('busy: carries no run identifier of any kind',
            !array_key_exists('run', $busy) && !array_key_exists('run_id', $busy)
            && $busy['scope'] === null);
        check('busy: and no digit, so no id, count, percentage or estimate leaks',
            preg_match('/\d/', $busy['why']) === 0);
        foreach (['north', 'south', 'owner', 'group', '%'] as $leak) {
            check("busy: says nothing about '$leak'", stripos($busy['why'], $leak) === false);
        }
        // The same words whoever asks: two different refusals are an oracle.
        // The same words whoever asks: two different refusals are an oracle.
        // It is a constant now, so "identical for every caller" is structural
        // rather than something a second implementation could get wrong.
        check('busy: is one constant, not a value each caller derives',
            defined('\INSPIRE\UniversalValidator\Scan\ScanStore::BUSY_WHY'));

        // Before the target fence a DAG projection is not yet provable, so
        // counts would be claims about a scope that has not been established.
        // The shape is ScanService::status()'s own (H10 part 1): this list
        // named keys no status carried until it was wired.
        $full = ['ok' => true, 'run_id' => 9, 'phase' => 'scanning', 'terminal' => null,
                 'coverage' => null, 'detail' => null, 'values' => 'none', 'scope' => 'group',
                 'active' => true, 'mayCancel' => true, 'stalled' => false, 'paused' => false,
                 'stopping' => false, 'idleSeconds' => 4, 'clean' => null, 'why' => null,
                 'total' => 4000, 'done' => 500, 'excluded' => 2, 'removed' => 1, 'settled' => 503,
                 'findings' => 91, 'detailRows' => 91, 'detailBytes' => 9100, 'ruleProblems' => 1,
                 'notExamined' => 3, 'percent' => 12.5, 'samples' => ['x']];
        $safe = ScanAuthorization::preFenceStatus($full);
        foreach (['total', 'done', 'excluded', 'removed', 'settled', 'findings', 'detailRows',
                  'detailBytes', 'ruleProblems', 'notExamined', 'percent', 'samples'] as $k) {
            check("pre-fence: withholds $k", !array_key_exists($k, $safe));
        }
        foreach (['ok', 'run_id', 'phase', 'terminal', 'coverage', 'active', 'mayCancel', 'stalled',
                  'paused', 'stopping', 'idleSeconds', 'clean'] as $k) {
            check("pre-fence: keeps $k, so progress and Stop can still render", array_key_exists($k, $safe));
        }
        check('pre-fence: says the counts are withheld, so absence is not read as zero',
            $safe['withheld'] === true && $safe['withheldWhy'] === ScanAuthorization::WITHHELD);
        $work = ScanAuthorization::preFenceWork(['ok' => true, 'worked' => 3, 'requeued' => 1,
            'blocked' => 1, 'findings' => 2, 'listed' => 900, 'phase' => 'scanning', 'done' => false,
            'stop' => 'time', 'why' => 'x'], true);
        check('pre-fence: a work answer keeps no counter, and says only that the run moved',
            !array_intersect(['worked', 'requeued', 'blocked', 'findings', 'listed'], array_keys($work))
            && $work['moved'] === true && $work['withheld'] === true && $work['phase'] === 'scanning'
            && $work['stop'] === 'time');

        // WHEN THE SCOPE IS PROVED: a fence target, and catch-up behind the run.
        $r = ['scope_dag' => '7', 'fence_target' => '500', 'phase' => 'unique-finalize', 'terminal' => null];
        check('proved: a run over the whole project has nothing to prove',
            ScanAuthorization::scopeProved(['scope_dag' => null, 'phase' => 'planning']) === true);
        check('proved: a group run with no target fence has not proved it, in any phase',
            ScanAuthorization::scopeProved(array_merge($r, ['fence_target' => null])) === false
            && ScanAuthorization::scopeProved(array_merge($r, ['fence_target' => '', 'phase' => 'terminal',
                'terminal' => ScanOutcome::PARTIAL])) === false);
        foreach (['planning' => false, 'scanning' => false, 'catch-up' => false, 'cancelling' => false,
                  'unique-finalize' => true, 'rollup-finalize' => true] as $ph => $want) {
            check('proved: a group run with a target fence, in ' . $ph . ', ' . ($want ? 'has' : 'has not'),
                ScanAuthorization::scopeProved(array_merge($r, ['phase' => $ph])) === $want);
        }
        foreach ([ScanOutcome::COMPLETE => true, ScanOutcome::PARTIAL => true, ScanOutcome::CANCELLED => false,
                  ScanOutcome::FAILED => false, ScanOutcome::EXPIRED => false] as $t => $want) {
            check('proved: a group run finished ' . $t . ' with a target fence ' . ($want ? 'has' : 'has not'),
                ScanAuthorization::scopeProved(array_merge($r, ['phase' => 'terminal', 'terminal' => $t])) === $want);
        }

        // A DAG run may only START where membership changes are provable.
        check('dag-start: refused when the server cannot prove DAG changes',
            ScanAuthorization::mayStartDagScoped(false)['ok'] === false);
        check('dag-start: allowed when it can',
            ScanAuthorization::mayStartDagScoped(true)['ok'] === true);

        // Drift invalidates the WHOLE response - it never silently changes a count.
        check('drift: no movement keeps the projection valid',
            ScanAuthorization::projectionStillValid(0, 0)['ok'] === true);
        check('drift: a record moved IN invalidates it',
            ScanAuthorization::projectionStillValid(1, 0)['ok'] === false);
        check('drift: a record moved OUT invalidates it',
            ScanAuthorization::projectionStillValid(0, 1)['ok'] === false);
    }

    /* =====================================================================
     * OUTCOME  every row of the plan's terminal-state table
     * ===================================================================== */
    {
        $O = 'INSPIRE\\UniversalValidator\\Scan\\ScanOutcome';
        $base = ['fenced' => true, 'manifestDone' => true, 'blocked' => false, 'truncated' => false,
                 'violations' => 0, 'ruleProblems' => 0, 'gaps' => 0];

        $r = ScanOutcome::derive($base);
        check('outcome: fenced + whole + nothing found = complete and CLEAN',
            $r['terminal'] === 'complete' && $r['coverage'] === 'complete-through-fence'
            && $r['detail'] === 'complete' && $r['clean'] === true);
        check('outcome: and its export needs no suffix', ScanOutcome::suffix($r) === '');
        check('outcome: and it may use the word clean', ScanOutcome::mayClaimClean($r) === true);

        $v = ScanOutcome::derive(array_merge($base, ['violations' => 3]));
        check('outcome: a violation is complete but not clean',
            $v['terminal'] === 'complete' && $v['clean'] === false);
        check('outcome: and may NOT use the word clean', ScanOutcome::mayClaimClean($v) === false);

        $u = ScanOutcome::derive(array_merge($base, ['ruleProblems' => 1]));
        check('outcome: a rule problem alone blocks clean',
            $u['terminal'] === 'complete' && $u['clean'] === false);

        $t = ScanOutcome::derive(array_merge($base, ['truncated' => true]));
        check('outcome: fenced but truncated is PARTIAL with fenced coverage',
            $t['terminal'] === 'partial' && $t['coverage'] === 'complete-through-fence'
            && $t['detail'] === 'truncated' && $t['clean'] === false);
        check('outcome: and says _TRUNCATED', ScanOutcome::suffix($t) === '_TRUNCATED');

        $m = ScanOutcome::derive(array_merge($base, ['fenced' => false]));
        check('outcome: no fence caps coverage at manifest-complete',
            $m['terminal'] === 'partial' && $m['coverage'] === 'manifest-complete'
            && $m['clean'] === false);
        check('outcome: and says _MANIFEST_ONLY', ScanOutcome::suffix($m) === '_MANIFEST_ONLY');
        // `manifest-complete` is a claim about the manifest, so an unfinished
        // manifest cannot earn it - with or without a fence.
        $nn = ScanOutcome::derive(array_merge($base, ['fenced' => false, 'manifestDone' => false]));
        check('outcome: neither a finished manifest nor a fence is PARTIAL, not manifest-complete',
            $nn['coverage'] === 'partial' && $nn['coverage'] !== 'manifest-complete'
            && strpos($nn['why'], 'was examined') === false
            && ScanOutcome::suffix($nn) === '_INCOMPLETE');
        $mt = ScanOutcome::derive(array_merge($base, ['fenced' => false, 'truncated' => true]));
        check('outcome: suffixes COMPOSE, because one alone misleads',
            ScanOutcome::suffix($mt) === '_MANIFEST_ONLY_TRUNCATED');

        $b = ScanOutcome::derive(array_merge($base, ['blocked' => true]));
        check('outcome: an unread record caps coverage at partial even when fenced',
            $b['terminal'] === 'partial' && $b['coverage'] === 'partial');
        check('outcome: and says _INCOMPLETE', ScanOutcome::suffix($b) === '_INCOMPLETE');

        $c = ScanOutcome::derive(array_merge($base, ['cancelled' => true]));
        check('outcome: cancellation is its own terminal state',
            $c['terminal'] === 'cancelled' && $c['clean'] === false
            && ScanOutcome::suffix($c) === '_CANCELLED');
        $f = ScanOutcome::derive(array_merge($base, ['failed' => true]));
        check('outcome: an unrecoverable failure outranks everything',
            $f['terminal'] === 'failed' && $f['coverage'] === 'failed'
            && ScanOutcome::suffix($f) === '_FAILED');
        check('outcome: failure outranks cancellation, which outranks expiry',
            ScanOutcome::derive(array_merge($base, ['failed' => true, 'cancelled' => true,
                'expired' => true]))['terminal'] === 'failed'
            && ScanOutcome::derive(array_merge($base, ['cancelled' => true,
                'expired' => true]))['terminal'] === 'cancelled');
        $e = ScanOutcome::derive(array_merge($base, ['expired' => true]));
        check('outcome: abandonment is expired, not complete',
            $e['terminal'] === 'expired' && ScanOutcome::suffix($e) === '_EXPIRED');

        // Label degradation is a worse REPORT, not a worse scan. Blocking on it
        // would make the tick unreachable on any install with a metadata gap.
        $d = ScanOutcome::derive(array_merge($base, ['labelDegraded' => true]));
        check('outcome: label degradation alone still permits clean',
            $d['terminal'] === 'complete' && $d['clean'] === true);
        check('outcome: and says so in the reason', strpos($d['why'], 'labels') !== false);

        // Collection gaps never block clean and never go unmentioned.
        $g = ScanOutcome::derive(array_merge($base, ['gaps' => 3860]));
        check('outcome: collection gaps do NOT block clean', $g['clean'] === true);
        check('outcome: but the caller is obliged to show them', $g['mustShowGaps'] === true);
        check('outcome: and none means no obligation', $r['mustShowGaps'] === false);

        // An empty manifest satisfied `pending === 0`, read as a finished
        // manifest, and took the clean row - so a run that examined nothing
        // produced the same certificate as one that examined everything.
        $es = ScanOutcome::derive(array_merge($base, ['emptyScope' => true]));
        check('outcome: an empty scope is never clean and never complete',
            $es['clean'] === false && $es['terminal'] !== ScanOutcome::COMPLETE);
        check('outcome: it is not called a failure, because an empty project is not one',
            $es['terminal'] !== ScanOutcome::FAILED
            && $es['coverage'] !== ScanOutcome::COV_FAILED);
        check('outcome: nor manifest-complete, which would claim a manifest that does not exist',
            $es['coverage'] !== ScanOutcome::MANIFEST
            && $es['coverage'] === ScanOutcome::EMPTY_SCOPE);
        check('outcome: and its export says _NO_RECORDS rather than nothing at all',
            ScanOutcome::suffix($es) === '_NO_RECORDS');

        // OC-1. EMPTY MEANS NOTHING WAS EXAMINED, and promotion is what decides
        // that. It asked `manifest_total === 0 && every state summed === 0`,
        // and the sum counted tombstones: a run whose every record was deleted
        // before it could be read was not "empty", took the clean row, and was
        // stored complete, complete-through-fence, clean=1. The manifest total
        // is written once, at freeze, so a manifest emptied afterwards was not
        // empty either. Facts below are a finished, fenced run with nothing
        // found - the most reassuring input there is - so the census is the
        // only thing that can refuse it.
        $P = function (array $states, array $run = []) {
            return ScanPromotion::facts(array_merge(['manifest_total' => 2, 'fence_target' => '500',
                'detail_rows' => 0], $run), $states,
                ['uniqueDone' => true, 'rollupDone' => true, 'violations' => 0, 'ruleProblems' => 0]);
        };
        $tomb = $P([ScanStore::REC_TOMBSTONE => 2]);
        $to = ScanOutcome::derive($tomb['facts']);
        check('outcome: a run whose every record was deleted examined NOTHING, so it is an empty scope',
            $tomb['ready'] === true && $tomb['facts']['emptyScope'] === true
            && $to['coverage'] === ScanOutcome::EMPTY_SCOPE);
        check('outcome: and it is stored neither complete nor clean',
            $to['terminal'] !== ScanOutcome::COMPLETE && $to['clean'] === false
            && ScanOutcome::mayClaimClean($to) === false && ScanOutcome::cleanColumn($to) === 0);
        check('outcome: its reason is true of a run whose records were deleted, and claims no examination',
            strpos($to['why'], 'deleted') !== false && strpos($to['why'], 'was examined and') === false);
        $gone = $P([], ['manifest_total' => 5]);
        check('outcome: a manifest emptied after it froze is an empty scope, not a pass',
            $gone['facts']['emptyScope'] === true
            && ScanOutcome::derive($gone['facts'])['clean'] === false);
        $unread = $P([ScanStore::REC_UNREADABLE => 2]);
        check('outcome: a run where every record was UNREADABLE is blocked, not empty - the records are there',
            $unread['facts']['emptyScope'] === false && $unread['facts']['blocked'] === true
            && ScanOutcome::derive($unread['facts'])['coverage'] === ScanOutcome::COV_PARTIAL);
        $held = ScanPromotion::facts(['manifest_total' => 2, 'fence_target' => '500'],
            [ScanStore::REC_TOMBSTONE => 2], ['uniqueDone' => true, 'rollupDone' => true,
            'violations' => 0, 'ruleProblems' => 0, 'blockingAggregates' => 1]);
        check('outcome: and anything blocking is reported as blocked, not folded into empty',
            $held['facts']['emptyScope'] === false && $held['facts']['blocked'] === true);
        check('outcome: records still waiting are not an empty scope',
            $P([ScanStore::REC_PENDING => 2])['facts']['emptyScope'] === false);
        $one = $P([ScanStore::REC_DONE => 1, ScanStore::REC_TOMBSTONE => 1]);
        check('outcome: one record examined and one deleted is not empty, and may still be clean',
            $one['facts']['emptyScope'] === false
            && ScanOutcome::derive($one['facts'])['clean'] === true);
        check('outcome: a run still planning, with nothing listed yet, is empty as it always was',
            $P([], ['manifest_total' => 0])['facts']['emptyScope'] === true);

        // EE-4. What the stored column means, row by row: 1 certified, 0
        // decided and NOT certified - for any reason, not only "found
        // something" - and NULL no decision at all.
        check('outcome: the clean column is 1 only for a certified run',
            ScanOutcome::cleanColumn($r) === 1);
        foreach (['found something' => $v, 'a rule problem' => $u, 'truncated' => $t,
                  'manifest only' => $m, 'blocked' => $b, 'empty scope' => $es] as $why => $o) {
            check("outcome: a run not certified because of $why stores 0, a decision",
                ScanOutcome::cleanColumn($o) === 0);
        }
        foreach (['cancelled' => $c, 'failed' => $f, 'expired' => $e] as $why => $o) {
            check("outcome: a $why run stores NULL, no decision",
                ScanOutcome::cleanColumn($o) === null);
        }

        // OC-7. A COUNT THAT WAS NOT SUPPLIED IS UNKNOWN, NOT ZERO. derive()
        // read a missing `violations` or `ruleProblems` as 0, so a caller that
        // forgot either - with every other fact at its most reassuring - got a
        // clean certificate.
        $whole = ['fenced' => true, 'manifestDone' => true];
        $nc = ScanOutcome::derive($whole);
        check('outcome: a whole, fenced run with NO counts supplied is not clean',
            $nc['clean'] === false && ScanOutcome::mayClaimClean($nc) === false
            && ScanOutcome::cleanColumn($nc) === 0);
        check('outcome: and does not claim there is something to act on, which nobody knows either',
            strpos($nc['why'], 'not counted') !== false);
        check('outcome: one count without the other is still not clean',
            ScanOutcome::derive($whole + ['violations' => 0])['clean'] === false
            && ScanOutcome::derive($whole + ['ruleProblems' => 0])['clean'] === false);
        foreach ([null, -1, 'none', '', 0.0, false, []] as $junk) {
            check('outcome: a count that is not a count is unknown, and refuses clean',
                ScanOutcome::derive($whole + ['violations' => $junk, 'ruleProblems' => 0])['clean'] === false
                && ScanOutcome::derive($whole + ['violations' => 0, 'ruleProblems' => $junk])['clean'] === false);
        }
        check('outcome: both counted as zero, as integers or as the strings a row carries, is clean',
            ScanOutcome::derive($whole + ['violations' => 0, 'ruleProblems' => 0])['clean'] === true
            && ScanOutcome::derive($whole + ['violations' => '0', 'ruleProblems' => '0'])['clean'] === true);

        // A caller that forgets a field gets the WEAKER claim.
        $empty = ScanOutcome::derive([]);
        check('outcome: an empty fact set is never clean and never complete',
            $empty['clean'] === false && $empty['terminal'] !== 'complete');
        check('outcome: and claims the WEAKEST coverage, not that a manifest was finished',
            $empty['coverage'] === 'partial');
    }

    /* =====================================================================
     * R2O-1  EVERY RECORD DELETED, CHECKED OR NOT
     *
     * Since OC-1 an empty scope means no record is left `done`. Catch-up
     * tombstones a record deleted after it was EXAMINED, and its findings stay
     * active - so the commonest way in stored "no record was examined" beside a
     * findings count that examination produced. Both shapes are driven through
     * the real store and the real promotion: records examined (with a finding
     * each) and then deleted, and records deleted before anything read them.
     * The classification must be the same - never clean, never complete - and
     * the stored sentence must be true of both.
     * ===================================================================== */
    {
        $shape = function ($examineFirst) {
            $pid = 801;
            $h = function ($id) { return hash('sha256', $id, true); };
            $store = new ArrayScanStore();
            $runId = (int) $store->startRun($pid, ['created_by' => 'alice', 'fence_open' => '1'])
                ['run']['run_id'];
            $gen = (int) $store->run($pid, $runId)['generation_id'];
            $store->writeManifest($runId, [['id_bin' => 'A', 'hash' => $h('A'), 'dag' => null],
                                           ['id_bin' => 'B', 'hash' => $h('B'), 'dag' => null]]);
            $epoch = (int) $store->run($pid, $runId)['lease_epoch'];
            if ($examineFirst) {
                $batch = ['bytes' => 0, 'records' => [], 'findings' => []];
                foreach ($store->claim($runId, 'w1', $epoch, 2) as $c) {
                    $batch['records'][] = ['ordinal' => $c['ordinal'], 'state' => ScanStore::REC_DONE,
                                           'version' => '100', 'claim' => $c['claim']];
                    $batch['findings'][] = ['ordinal' => $c['ordinal'], 'project_id' => $pid,
                        'generation_id' => $gen, 'identity' => $h('f' . $c['id_bin']),
                        'record_hash' => $h($c['id_bin']), 'record_id_bin' => $c['id_bin'],
                        'host_form' => 'fa', 'field' => 'a_val', 'rule_source_id' => 'r1',
                        'rule_revision' => str_repeat('0', 64), 'rule_ord' => 1,
                        'check_type' => 'required', 'reason_code' => 'required-blank',
                        'event_id' => 1, 'instance' => 1, 'dag_key' => null, 'valid_from_seq' => 1];
                }
                $store->commitBatch($runId, 'w1', $epoch, $batch);
            }
            // The store path CatchUp::step() takes for a record the project no
            // longer has, whatever state it is in.
            $store->advancePhase($runId, $epoch, ScanPhase::CATCH_UP);
            $gone = $store->tombstone($runId, $epoch, [$h('A'), $h('B')]);
            // What the rollup would have summarised: one active finding per
            // examined record, on every axis.
            if ($examineFirst) {
                foreach (RollupBuilder::KINDS as $k) {
                    $store->addAggregate($runId, $k, 'x', '', 2);
                }
            }
            $p = ScanPromotion::promote($store, $pid, $runId,
                ['uniqueDone' => true, 'rollupDone' => true]);
            $run = $store->run($pid, $runId);
            return ['p' => $p, 'run' => $run, 'gone' => $gone, 'detail' => (int) $run['detail_rows'],
                    'found' => ScanPromotion::findingsOf($store, $run)];
        };
        foreach (['examined, then deleted' => true, 'deleted before it was read' => false] as $how => $first) {
            $s = $shape($first);
            $o = $s['p']['outcome'];
            check("R2O-1 ($how): every record became a tombstone", $s['gone'] === 2);
            check("R2O-1 ($how): the run is an empty scope - partial, never complete, never clean",
                $s['p']['promoted'] === true && $o['terminal'] === ScanOutcome::PARTIAL
                && $o['coverage'] === ScanOutcome::EMPTY_SCOPE && $o['clean'] === false
                && ScanOutcome::cleanColumn($o) === 0 && $o['suffix'] === '_NO_RECORDS');
            check("R2O-1 ($how): the stored reason does not say no record was examined",
                stripos($s['run']['terminal_reason'], 'no record was examined') === false
                && stripos($s['run']['terminal_reason'], 'before it could be read') === false);
            check("R2O-1 ($how): it says no record is left, that they were deleted checked or not, "
                . 'and that it says nothing about the project',
                strpos($s['run']['terminal_reason'], 'no record is left') === 0
                && strpos($s['run']['terminal_reason'], 'whether or not it had been examined') !== false
                && strpos($s['run']['terminal_reason'], 'says nothing about the project') !== false);
            check("R2O-1 ($how): and it is stored whole, not cut at the column",
                $s['run']['terminal_reason'] === $o['why'] && strlen($o['why']) <= 255);
        }
        $ex = $shape(true);
        check('R2O-1: the examined shape really did examine and find - this is the case the sentence lied about',
            $ex['detail'] === 2 && $ex['found'] === 2);
        check('R2O-1: and its reason accounts for those findings instead of denying the examination',
            strpos($ex['run']['terminal_reason'], 'any findings it kept are on deleted records') !== false);
    }

    /* =====================================================================
     * HMAC  purpose and project separation
     * ===================================================================== */
    {
        $k = str_repeat('k', 32);
        $a = Hmac::raw(Hmac::P_RECORD, 1, 'ID-1', $k);
        check('hmac: 32 raw bytes', strlen($a) === 32);
        check('hmac: stable for the same inputs', $a === Hmac::raw(Hmac::P_RECORD, 1, 'ID-1', $k));
        check('hmac: a different PURPOSE gives a different value',
            $a !== Hmac::raw(Hmac::P_VALUE, 1, 'ID-1', $k));
        check('hmac: a different PROJECT gives a different value',
            $a !== Hmac::raw(Hmac::P_RECORD, 2, 'ID-1', $k));
        check('hmac: a different KEY gives a different value',
            $a !== Hmac::raw(Hmac::P_RECORD, 1, 'ID-1', str_repeat('j', 32)));
        // The separator matters: without it "record"+"1|x" and "record|1"+"x"
        // would collide, which is a cross-purpose collision by construction.
        check('hmac: purpose/project/data cannot be re-split to collide',
            Hmac::raw(Hmac::P_RECORD, 1, 'a', $k) !== Hmac::raw(Hmac::P_RECORD, '1' . chr(0) . 'a', '', $k));
        check('hmac: invalid UTF-8 is hashed as bytes, not rejected',
            strlen(Hmac::raw(Hmac::P_VALUE, 1, "\xff\xfe\x00bad", $k)) === 32);

        $threw = false;
        try { Hmac::raw('made-up', 1, 'x', $k); } catch (\InvalidArgumentException $e) { $threw = true; }
        check('hmac: an unknown purpose throws rather than sharing a space', $threw);

        // NEVER an unkeyed fallback: an unkeyed hash of a record id is a lookup
        // table for anyone holding the report.
        foreach ([null, '', 0] as $bad) {
            $threw = false;
            try { Hmac::raw(Hmac::P_RECORD, 1, 'x', $bad); } catch (\RuntimeException $e) { $threw = true; }
            check('hmac: a missing key throws rather than hashing unkeyed', $threw);
        }

        // Finding identity is location+rule+reason, NOT the value: a wrong value
        // that changed to another wrong value is the same finding.
        $loc = ['record' => '1', 'event_id' => 7, 'instance' => 1, 'host_form' => 'fa',
                'field' => 'x', 'rule_source_id' => 'r1', 'reason_code' => 'format'];
        $id1 = Hmac::findingIdentity(1, $loc, $k);
        check('identity: stable across a changed value',
            $id1 === Hmac::findingIdentity(1, array_merge($loc, ['value' => 'other']), $k));
        check('identity: a different field is a different finding',
            $id1 !== Hmac::findingIdentity(1, array_merge($loc, ['field' => 'y']), $k));
        check('identity: a different reason is a different finding',
            $id1 !== Hmac::findingIdentity(1, array_merge($loc, ['reason_code' => 'check-character']), $k));
        check('identity: a different instance is a different finding',
            $id1 !== Hmac::findingIdentity(1, array_merge($loc, ['instance' => 2]), $k));
    }

    /* =====================================================================
     * POLICY  effective = min(system, project), unknown fails toward safe
     * ===================================================================== */
    {
        $p = ScanPolicy::resolve([], []);
        check('policy: the default value mode is locations-only',
            $p['valueMode'] === 'locations');
        check('policy: and the documented defaults apply',
            $p['valueDays'] === 30 && $p['runDays'] === 90 && $p['maxProjects'] === 2
            && $p['recordAttempts'] === 3);
        // It said 'separate' while nothing detected a gap. A policy that names a
        // behaviour promises it.
        check('policy: collection gaps are reported as what they are - not implemented',
            $p['collectionGaps'] === ScanPolicy::GAPS_UNIMPLEMENTED
            && $p['collectionGaps'] !== 'separate');
        check('policy: and no setting, system or project, can say otherwise',
            ScanPolicy::resolve(['collectionGaps' => 'separate', 'scan-collection-gaps' => 'separate'],
                                ['collectionGaps' => 'separate', 'scan-collection-gaps' => 'separate'])
                ['collectionGaps'] === ScanPolicy::GAPS_UNIMPLEMENTED);

        check('policy: a project may shorten retention',
            ScanPolicy::resolve([], ['scan-value-retention-days' => '7'])['valueDays'] === 7);
        check('policy: but may NOT lengthen it past the system maximum',
            ScanPolicy::resolve(['scan-system-max-value-retention-days' => '14'],
                ['scan-value-retention-days' => '999'])['valueDays'] === 14);
        check('policy: a project cannot grant itself more of the server',
            ScanPolicy::resolve([], ['scan-system-max-concurrent-projects' => '99'])['maxProjects'] === 2);

        foreach (['', 'abc', '-5', '0', null, []] as $junk) {
            $r = ScanPolicy::resolve([], ['scan-value-retention-days' => $junk]);
            check('policy: malformed retention falls back to the default, not to forever',
                $r['valueDays'] === 30);
        }
        // CL-6. The attempt counter saturates at 254 in both stores, and the
        // worker ends a record when tries + 1 >= the setting - so a setting of
        // 256 or more is never reached and a record the read leaves out is
        // requeued for ever. The policy is where that number is decided.
        $att = function ($raw) {
            return ScanPolicy::resolve(['scan-system-record-attempts' => $raw], [])['recordAttempts'];
        };
        check('policy: record attempts up to the counter\'s ceiling are taken as given',
            $att('254') === 254 && $att(254) === 254 && $att('5') === 5 && $att(1) === 1);
        check('policy: and anything past it is held at the ceiling, where the counter stops',
            $att('255') === ScanPolicy::MAX_RECORD_ATTEMPTS && $att('300') === 254
            && $att(300) === 254 && $att('99999999999') === 254);
        check('policy: the ceiling is the 254 both stores saturate at',
            ScanPolicy::MAX_RECORD_ATTEMPTS === 254);
        foreach (['0', 0, '-5', -5, 'abc', '', null, '3.5', []] as $junk) {
            check('policy: malformed record attempts fall back to the default, never 0 or below',
                $att($junk) === ScanPolicy::D_RECORD_ATTEMPTS);
        }

        // R8-4. The concurrent-projects limit is the number of slot rows a
        // settings save writes, one placeholder each. It had no ceiling: 70000
        // was a statement the server refused (no slot written, every worker
        // refused as busy) and a billion was a fatal out-of-memory in range().
        $proj = function ($raw) {
            return ScanPolicy::resolve(['scan-system-max-concurrent-projects' => $raw], [])['maxProjects'];
        };
        check('policy R8-4: limits up to the ceiling are taken as given',
            $proj('2') === 2 && $proj('999') === 999 && $proj(ScanPolicy::MAX_PROJECTS) === ScanPolicy::MAX_PROJECTS);
        check('policy R8-4: and anything past it is held at the ceiling',
            $proj('1001') === ScanPolicy::MAX_PROJECTS && $proj('70000') === ScanPolicy::MAX_PROJECTS
            && $proj('1000000000') === ScanPolicy::MAX_PROJECTS && $proj('99999999999999999999') === ScanPolicy::MAX_PROJECTS
            && $proj(PHP_INT_MAX) === ScanPolicy::MAX_PROJECTS);
        check('policy R8-4: the ceiling fits the SMALLINT UNSIGNED slot number and one statement\'s placeholders',
            ScanPolicy::MAX_PROJECTS >= 1 && ScanPolicy::MAX_PROJECTS <= 65535);
        foreach (['0', '-5', 'abc', '', null, '3.5'] as $junk) {
            check('policy R8-4: a malformed limit is the default, never 0',
                $proj($junk) === ScanPolicy::D_MAX_PROJECTS);
        }

        foreach (['', 'wat', null, 'RAW'] as $junk) {
            check('policy: an unrecognised value mode discloses nothing',
                ScanPolicy::resolve([], ['scan-value-storage' => $junk])['valueMode'] === 'locations');
        }

        check('policy: unknown ranks lowest, so the floor is the safe one',
            ScanPolicy::floor('raw', 'wat') === 'locations'
            && ScanPolicy::floor('raw', 'identifier-redacted') === 'identifier-redacted'
            && ScanPolicy::floor('raw', 'raw') === 'raw');

        // Tightening takes effect immediately; loosening waits for the next run.
        check('policy: a reduction in disclosure counts as tightened',
            ScanPolicy::tightened(['valueMode' => 'raw'], ['valueMode' => 'locations']) === true);
        check('policy: an increase does not',
            ScanPolicy::tightened(['valueMode' => 'locations'], ['valueMode' => 'raw']) === false);
        check('policy: a shortened retention counts as tightened',
            ScanPolicy::tightened(['valueDays' => 30], ['valueDays' => 7]) === true);
        check('policy: switching to hashed record ids counts as tightened',
            ScanPolicy::tightened(['hashRecordIds' => false], ['hashRecordIds' => true]) === true);
        check('policy: an unchanged policy has not tightened',
            ScanPolicy::tightened(['valueMode' => 'raw', 'valueDays' => 30],
                                  ['valueMode' => 'raw', 'valueDays' => 30]) === false);

        check('policy: either budget alone spends it',
            ScanPolicy::budgetSpent(['maxFindings' => 10, 'maxBytes' => 1000], 10, 0) === true
            && ScanPolicy::budgetSpent(['maxFindings' => 10, 'maxBytes' => 1000], 0, 1000) === true
            && ScanPolicy::budgetSpent(['maxFindings' => 10, 'maxBytes' => 1000], 9, 999) === false);
    }

    /* =====================================================================
     * DRIFT  every setting the policy reads must exist in config.json
     *
     * A resolver that reads a key nobody can set always returns its default, so
     * the setting silently does not work - and nothing fails, which is exactly
     * why this has to be asserted rather than noticed.
     * ===================================================================== */
    {
        $cfg = json_decode(file_get_contents(__DIR__ . '/../config.json'), true);
        $declared = [];
        $sysKeys = [];
        foreach (['system-settings', 'project-settings'] as $group) {
            foreach (isset($cfg[$group]) ? $cfg[$group] : [] as $st) {
                if (!isset($st['key'])) continue;
                $declared[$st['key']] = true;
                if ($group === 'system-settings') $sysKeys[$st['key']] = true;
            }
        }
        $reads = ['scan-value-storage', 'scan-value-retention-days', 'scan-run-retention-days',
                  'scan-max-detail-findings', 'scan-max-detail-bytes',
                  'scan-system-max-value-retention-days', 'scan-system-max-run-retention-days',
                  'scan-system-max-detail-findings', 'scan-system-max-detail-bytes',
                  'scan-system-max-concurrent-projects', 'scan-system-stale-run-hours',
                  'scan-system-record-attempts'];
        foreach ($reads as $k) {
            check("drift: config.json declares $k", isset($declared[$k]));
            // A system maximum declared per project would let a project raise
            // its own ceiling, which is the one thing these exist to prevent.
            if (strpos($k, 'scan-system-') === 0) {
                check("drift: $k is a SYSTEM setting, not a project one", isset($sysKeys[$k]));
            }
        }
    }

    /* =====================================================================
     * S-10  a database error names the SCHEMA and never the DATA
     *
     * This text reaches a page. MySQL puts the offending VALUE in single
     * quotes - and, as it turns out, the IDENTIFIER too, in the same quotes -
     * so the blanket redaction that shipped through 1.9.10 destroyed the
     * diagnosis along with the value and three pilot rounds were spent on it.
     * The replacement REBUILDS a template from structural captures instead of
     * filtering the server's string, which is what lets the failing statement
     * and its bound parameters be dropped rather than trimmed.
     *
     * safeDbMessage had no test of any kind before this block: no hit for it,
     * for 'refused to store', for 'Duplicate entry' or for 'Data too long'
     * anywhere under tests/. That absence is why an `||` in the MySQL suite was
     * able to stand in for the guarantee for two releases.
     * ===================================================================== */
    {
        $D = '\INSPIRE\UniversalValidator\Scan\DbError';
        $err = function ($msg, $code = 0) { return new \RuntimeException($msg, $code); };

        // --- the two shapes that carry participant data ---
        $dup = $D::safe($err("Duplicate entry 'AB12-9' for key 'uv_finding.uq_active_identity'", 1062));
        check('S-10: a duplicate key names the KEY', strpos($dup, 'uq_active_identity') !== false);
        check('S-10: and never the duplicated value', strpos($dup, 'AB12-9') === false);
        check('S-10: and carries the errno, which names the fix on its own',
            strpos($dup, '1062') !== false);

        $inc = $D::safe($err("Incorrect integer value: 'Mrs Smith' for column 'instance' at row 4", 1366));
        check('S-10: an incorrect value names the column', strpos($inc, 'instance') !== false);
        check('S-10: and withholds the value', strpos($inc, 'Mrs Smith') === false);

        // --- the shapes whose quoted parts are all identifiers ---
        $long = $D::safe($err("Data too long for column 'reason_code' at row 1", 1406));
        check('S-10: data-too-long names the column, which is the whole diagnosis',
            strpos($long, 'reason_code') !== false);
        check('S-10: a null violation names its column',
            strpos($D::safe($err("Column 'host_form' cannot be null", 1048)), 'host_form') !== false);
        check('S-10: a missing default names its field',
            strpos($D::safe($err("Field 'run_id' doesn't have a default value", 1364)), 'run_id') !== false);
        check('S-10: an unknown column names itself',
            strpos($D::safe($err("Unknown column 'zzz' in 'field list'", 1054)), 'zzz') !== false);
        check('S-10: a missing table names itself',
            strpos($D::safe($err("Table 'redcap.uv_finding' doesn't exist", 1146)), 'uv_finding') !== false);

        // --- the value-free shapes the lease predicates need (H12) ---
        check('S-10: a deadlock says deadlock, so it is not read as a takeover',
            stripos($D::safe($err('Deadlock found when trying to get lock; try restarting transaction', 1213)),
                    'deadlock') !== false);
        check('S-10: a lock-wait timeout says so too',
            stripos($D::safe($err('Lock wait timeout exceeded; try restarting transaction', 1205)),
                    'lock wait timeout') !== false);

        // --- THE TAIL WINDOW. The framework prepends the failing STATEMENT, and
        // a findings batch is a multi-row INSERT with thousands of
        // placeholders, so the server's own text can sit a megabyte in. A
        // head-only cut finds nothing at all and reports a shrug.
        $huge = 'ExternalModules framework error running query: INSERT INTO uv_finding ('
              . str_repeat('?, ', 200000) . ') -- ' . "Duplicate entry 'SECRET' for key 'uv_finding.uq_active_identity'";
        $tail = $D::safe($err($huge, 1062));
        check('S-10: the diagnosis is found even when the statement pushes it past the head window',
            strpos($tail, 'uq_active_identity') !== false);
        check('S-10: and the megabyte of statement does not travel with it',
            strpos($tail, 'INSERT INTO') === false && strlen($tail) < 200);
        check('S-10: and the value at the far end is still withheld',
            strpos($tail, 'SECRET') === false);

        // --- REBUILDING IS THE SECURITY PROPERTY. On a recognised shape the
        // output is assembled from captures, so anything the wrapper appended -
        // statement, bound parameters - is dropped rather than trimmed.
        $withParams = "Duplicate entry 'X' for key 'uv_finding.uq_active_identity' "
                    . '[params: nhs_number=4857773456, dob=1974-02-11]';
        $rebuilt = $D::safe($err($withParams, 1062));
        check('S-10: bound parameters appended by the wrapper are dropped, not trimmed',
            strpos($rebuilt, '4857773456') === false && strpos($rebuilt, '1974-02-11') === false);

        // --- the ident() guard. A value carrying an apostrophe can re-frame the
        // text so a fragment of it lands in a capture group; identifier shape is
        // what makes that a withheld name rather than a disclosure.
        $crafted = $D::safe($err("Duplicate entry 'a' for key 'Smith, Jane (DOB 1974-02-11)'", 1062));
        check('S-10: a capture that is not identifier-shaped is withheld',
            strpos($crafted, 'Smith') === false && strpos($crafted, '1974') === false);
        check('S-10: and says so rather than printing nothing',
            strpos($crafted, 'withheld') !== false);

        // --- THE BINARY CASE, which is the one that actually leaked.
        //
        // uq_active_identity is keyed on a 32-byte HMAC, so the value MySQL
        // echoes back in a duplicate-entry error is raw binary - and roughly one
        // identity in eight contains an apostrophe byte (0x27) by chance. That
        // byte closes the redaction pattern's quoted run early and opens the
        // next one in the wrong place, so the old blanket redaction emitted the
        // bytes BETWEEN the two apostrophes verbatim while still destroying the
        // key name. It lost the diagnosis and disclosed at the same time. The
        // template rebuild is immune because it never echoes the value capture.
        $bin = "\x9f\x2c'" . "\x44\xa1SHOULD_NOT_APPEAR\x00\x11" . "'\xee\x03";
        $binMsg = $D::safe($err("Duplicate entry '" . $bin
                                . "' for key 'uv_finding.uq_active_identity'", 1062));
        check('S-10: a quote byte inside a binary key value cannot re-frame the redaction',
            strpos($binMsg, 'SHOULD_NOT_APPEAR') === false);
        check('S-10: and the key name survives it, which the old pattern also lost',
            strpos($binMsg, 'uq_active_identity') !== false);
        check('S-10: and no raw NUL reaches the page',
            strpos($binMsg, "\x00") === false);

        // --- an unrecognised message still answers, still bounded, still redacted.
        $odd = $D::safe($err("Some novel server condition involving 'AB12-9' and more", 9999));
        check('S-10: an unrecognised error still redacts its quoted runs',
            strpos($odd, 'AB12-9') === false);
        check('S-10: and still names its errno', strpos($odd, '9999') !== false);
        check('S-10: and is bounded', strlen($odd) <= 200);

        // --- and it never returns nothing. An empty message is the one input
        // that used to hand a bare class name to a page.
        check('S-10: an empty message still answers', $D::safe($err('', 1213)) !== '');
    }

    /* -----------------------------------------------------------------
     * R8-4  PROVISIONING A LIMIT TYPED INTO A TEXT BOX
     *
     * One INSERT with a placeholder a slot: above 65535 placeholders the
     * statement is refused and no slot is written, and a billion made
     * range() exhaust memory. provision() writes CHUNK rows a statement and
     * holds whatever it is handed to the slot_no range. Over a recording
     * ScanDb, so the statements themselves are seen.
     * ----------------------------------------------------------------- */
    {
        require_once __DIR__ . '/../php/Scan/WorkerSlots.php';
        require_once __DIR__ . '/../php/Scan/WorkBudget.php';
        require_once __DIR__ . '/../php/Scan/ScanWorker.php';
        $slotDb = function ($max, array $affected = []) {
            return new class($max, $affected) implements ScanDb {
                public $max; public $affectedQueue; public $inserts = []; private $last = 0;
                public function __construct($max, $q) { $this->max = $max; $this->affectedQueue = $q; }
                public function select($sql, array $params = []) {
                    return strpos($sql, 'MAX(slot_no)') !== false ? [[$this->max]] : [];
                }
                public function exec($sql, array $params = []) {
                    $this->inserts[] = [substr_count($sql, '(?, 0)'), $params];
                    $this->last = $this->affectedQueue ? array_shift($this->affectedQueue) : count($params);
                    return true;
                }
                public function execNoCount($sql, array $params = []) { return true; }
                public function affected() { return $this->last; }
                public function begin() {}
                public function commit() {}
                public function rollback() {}
            };
        };
        $byChunks = function ($db) {
            $ok = true; $next = null;
            foreach ($db->inserts as $ins) {
                list($marks, $params) = $ins;
                $ok = $ok && $marks === count($params) && $marks <= WorkerSlots::CHUNK && $marks > 0
                    && ($next === null || $params[0] === $next);
                $next = (int) end($params) + 1;
            }
            return $ok;
        };
        $d = $slotDb(0);
        $added = (new WorkerSlots($d))->provision(1200);
        check('R8-4 slots: 1200 slots go in three statements of at most CHUNK rows, in order',
            $added === 1200 && count($d->inserts) === 3 && $byChunks($d)
            && $d->inserts[0][1][0] === 1 && end($d->inserts)[1][count(end($d->inserts)[1]) - 1] === 1200);
        $d = $slotDb(0, [500, 3, 200]);
        check('R8-4 slots: the answer is every statement\'s affected count added up',
            (new WorkerSlots($d))->provision(1200) === 703);
        $d = $slotDb(499);
        check('R8-4 slots: a gap from 500 to 1000 is two statements, 500 and 1',
            (new WorkerSlots($d))->provision(1000) === 501 && count($d->inserts) === 2
            && $d->inserts[0][0] === 500 && $d->inserts[1][1] === [1000]);
        $d = $slotDb(0);
        $big = (new WorkerSlots($d))->provision(1000000000);
        $last = end($d->inserts);
        check('R8-4 slots: a billion handed straight to provision() stops at the slot_no range',
            $big === WorkerSlots::MAX_SLOT_NO && $byChunks($d)
            && $last[1][count($last[1]) - 1] === WorkerSlots::MAX_SLOT_NO
            && count($d->inserts) === (int) ceil(WorkerSlots::MAX_SLOT_NO / WorkerSlots::CHUNK));
        check('R8-4 slots: no statement ever carries more placeholders than a server accepts',
            WorkerSlots::CHUNK <= 65535 && WorkerSlots::MAX_SLOT_NO === 65535);
        $d = $slotDb(5);
        check('R8-4 slots: a table already at the limit sends nothing',
            (new WorkerSlots($d))->provision(5) === 0 && $d->inserts === []);

        // R4-4 / W1-N1: THE LIMIT REACHES acquire(), AND SHRINKING DELETES ONLY
        // WHAT IS IDLE. A slot table as rows: slot_no => held?. The fake answers
        // the free-slot read as the server would, so a limit that never reached
        // the statement shows up as a slot above it being taken.
        $table = function (array $held, $count) {
            return new class($held, $count) implements ScanDb {
                public $rows = []; public $log = []; private $last = 0;
                public function __construct(array $held, $count) {
                    for ($i = 1; $i <= $count; $i++) $this->rows[$i] = in_array($i, $held, true);
                }
                public function select($sql, array $params = []) {
                    $this->log[] = [$sql, $params];
                    $out = [];
                    if (strpos($sql, 'COUNT(*)') !== false) {
                        return [[count($this->rows), count(array_filter($this->rows))]];
                    }
                    if (strpos($sql, 'slot_no <= ?') !== false) {
                        foreach ($this->rows as $no => $h) if (!$h && $no <= $params[0]) $out[] = [$no, 0];
                    } elseif (strpos($sql, 'slot_no > ?') !== false) {
                        foreach ($this->rows as $no => $h) if (!$h && $no > $params[0]) $out[] = [$no];
                    } elseif (strpos($sql, 'SELECT slot_no, epoch') !== false) {
                        foreach ($this->rows as $no => $h) if (!$h) $out[] = [$no, 0];
                    }
                    return $out;
                }
                public function exec($sql, array $params = []) {
                    $this->log[] = [$sql, $params];
                    $this->last = 0;
                    if (strpos(ltrim($sql), 'UPDATE') === 0 && count($params) === 7) {
                        // acquire()'s compare-and-set; renew and release are
                        // answered as no change, which is all this needs.
                        $no = $params[4];
                        if (isset($this->rows[$no]) && !$this->rows[$no]) { $this->rows[$no] = true; $this->last = 1; }
                    } elseif (strpos(ltrim($sql), 'DELETE') === 0) {
                        $limit = $params[count($params) - 2];
                        foreach (array_slice($params, 0, count($params) - 2) as $no) {
                            if (isset($this->rows[$no]) && !$this->rows[$no] && $no > $limit) {
                                unset($this->rows[$no]);
                                $this->last++;
                            }
                        }
                    }
                }
                public function execNoCount($sql, array $params = []) {}
                public function affected() { return $this->last; }
                public function begin() {}
                public function commit() {}
                public function rollback() {}
            };
        };
        $t = $table([1, 2], 5);
        check('R4-4 slots: with the limit at 2 and both places held, a free slot 3 is not taken',
            (new WorkerSlots($t))->acquire('w', 1, 60, 2) === null);
        check('R4-4 slots: without a limit the free row would have been taken, which is the defect',
            (new WorkerSlots($table([1, 2], 5)))->acquire('w', 1, 60)['slot_no'] === 3);
        $t = $table([4], 6);
        check('W1-N1 slots: shrinking to 2 removes the free rows above it and keeps the held one',
            (new WorkerSlots($t))->shrinkTo(2) === 3 && array_keys($t->rows) === [1, 2, 4]);
        check('W1-N1 slots: and the delete repeats the free test, so a row leased meanwhile stays',
            strpos($t->log[count($t->log) - 1][0], 'owner IS NULL OR expires_at < ?') !== false);

        // The worker hands the limit over. A ScanWorker built with slotLimit 2
        // over a table whose two places are held takes nothing, and says the
        // server is at its limit rather than that it is unprovisioned.
        $t = $table([1, 2], 5);
        $store = new \INSPIRE\UniversalValidator\Scan\ArrayScanStore();
        $run = $store->startRun(41, ['created_by' => 'x']);
        $rid = (int) $run['run']['run_id'];
        $store->writeManifest($rid, [['id_bin' => 'R1', 'hash' => hash('sha256', 'R1', true), 'dag' => null]]);
        $w = new \INSPIRE\UniversalValidator\Scan\ScanWorker($store, [
            'slots' => new WorkerSlots($t), 'slotLimit' => 2, 'owner' => 'w1',
            'read' => function ($ids) { return ['ok' => true, 'data' => [], 'why' => null]; },
            'evaluate' => function ($id, $node) { return ['findings' => [], 'bytes' => 0, 'contexts' => 1, 'why' => null]; }]);
        $res = $w->work(41, $rid);
        check('R4-4 slots: a worker given the limit is held to it',
            $res['stop'] === 'capacity' && $t->rows[3] === false);
    }

    echo "scan_security_php: $n checks, $fail failure(s)\n";
    exit($fail ? 1 : 0);
}
