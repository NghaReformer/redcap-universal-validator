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
    require_once __DIR__ . '/../php/Scan/ScanAuthorization.php';
    require_once __DIR__ . '/../php/Scan/Hmac.php';
    require_once __DIR__ . '/../php/Scan/ScanPolicy.php';

    $n = 0; $fail = 0;
    function check($label, $cond) {
        global $n, $fail; $n++;
        if (!$cond) { $fail++; fwrite(STDERR, "FAIL: $label\n"); }
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
        $dagUser = rights(['group_id' => 'north']);

        check('read: an unrestricted user may read a project-wide run',
            ScanAuthorization::mayRead(rights(), $ENT, null)['ok'] === true);
        check('read: and a DAG-scoped one',
            ScanAuthorization::mayRead(rights(), $ENT, 'north')['ok'] === true);

        check('read: a DAG user may read their OWN group\'s run',
            ScanAuthorization::mayRead($dagUser, $ENT, 'north')['ok'] === true);
        check('read: but NOT another group\'s',
            ScanAuthorization::mayRead($dagUser, $ENT, 'south')['ok'] === false);
        check('read: nor a project-wide run, which is wider than their scope',
            ScanAuthorization::mayRead($dagUser, $ENT, null)['ok'] === false);

        // NON-DISCLOSING: "another group's run" and "no such run" must read the
        // same, or the message is an existence oracle.
        $other = ScanAuthorization::mayRead($dagUser, $ENT, 'south');
        check('read: the cross-scope refusal does not confirm the run exists',
            strpos($other['why'], 'south') === false && strpos($other['why'], 'exist') === false
            && strpos($other['why'], 'another') === false);

        // Rights revoked mid-run stop reads; the run id does not restore them.
        check('read: revoked design rights stop reading an existing run',
            ScanAuthorization::mayRead(rights(['design' => false]), $ENT, null)['ok'] === false);
        check('read: a downgraded export level stops reading it too',
            ScanAuthorization::mayRead(rights(['data_export_tool' => '2']), $ENT, null)['ok'] === false);
        check('read: and losing access to one instrument refuses the whole run',
            ScanAuthorization::mayRead(rights(['forms' => ['fa' => '1', 'fb' => '0', 'fc' => '1']]),
                $ENT, null)['ok'] === false);

        check('work: carries exactly the read entitlement',
            ScanAuthorization::mayWork($dagUser, $ENT, 'north')['ok'] === true
            && ScanAuthorization::mayWork($dagUser, $ENT, 'south')['ok'] === false);
    }

    /* =====================================================================
     * CANCEL  wider than working, and never across scopes
     * ===================================================================== */
    {
        $dagUser = rights(['group_id' => 'north']);
        check('cancel: an unrestricted user may cancel a global run',
            ScanAuthorization::mayCancel(rights(), $ENT, null)['ok'] === true);
        check('cancel: a DAG user may NOT cancel a global run',
            ScanAuthorization::mayCancel($dagUser, $ENT, null)['ok'] === false);
        check('cancel: a DAG user may cancel their own group\'s run',
            ScanAuthorization::mayCancel($dagUser, $ENT, 'north')['ok'] === true);
        check('cancel: even one another user in that group started',
            ScanAuthorization::mayCancel($dagUser, $ENT, 'north', 'someone_else', 'me')['ok'] === true);
        check('cancel: but not another group\'s run',
            ScanAuthorization::mayCancel($dagUser, $ENT, 'south')['ok'] === false);
        check('cancel: an unrestricted user may cancel any DAG run',
            ScanAuthorization::mayCancel(rights(), $ENT, 'south')['ok'] === true);
        check('cancel: an unentitled user may cancel nothing',
            ScanAuthorization::mayCancel(rights(['design' => false]), $ENT, 'north')['ok'] === false);
    }

    /* =====================================================================
     * NON-DISCLOSURE  busy, pre-fence status, DAG drift
     * ===================================================================== */
    {
        $busy = ScanAuthorization::busy();
        check('busy: refuses', $busy['ok'] === false && $busy['busy'] === true);
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
        check('busy: is identical for every caller', ScanAuthorization::busy() == $busy);

        // Before the target fence a DAG projection is not yet provable, so
        // counts would be claims about a scope that has not been established.
        $full = ['run_owned' => true, 'phase' => 'scanning', 'heartbeat_at' => 't',
                 'last_progress_at' => 't', 'may_resume' => true, 'may_cancel' => true,
                 'error_category' => null,
                 'records_done' => 500, 'records_total' => 4000, 'violations' => 91,
                 'percent' => 12.5, 'rollups' => ['a' => 1], 'samples' => ['x'], 'eta' => '3m'];
        $safe = ScanAuthorization::preFenceStatus($full);
        foreach (['records_done', 'records_total', 'violations', 'percent', 'rollups',
                  'samples', 'eta'] as $k) {
            check("pre-fence: withholds $k", !array_key_exists($k, $safe));
        }
        foreach (['phase', 'may_cancel', 'may_resume', 'heartbeat_at'] as $k) {
            check("pre-fence: keeps $k, so progress can still render", array_key_exists($k, $safe));
        }
        check('pre-fence: says the detail is withheld, so absence is not read as zero',
            !empty($safe['detail_withheld']) && strpos($safe['detail_withheld_why'], 'proved') !== false);

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

        // A caller that forgets a field gets the WEAKER claim.
        $empty = ScanOutcome::derive([]);
        check('outcome: an empty fact set is never clean and never complete',
            $empty['clean'] === false && $empty['terminal'] !== 'complete');
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
        check('policy: collection gaps are separate, with no off switch',
            $p['collectionGaps'] === 'separate');

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

    echo "scan_security_php: $n checks, $fail failure(s)\n";
    exit($fail ? 1 : 0);
}
