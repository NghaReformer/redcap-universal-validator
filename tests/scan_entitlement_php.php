<?php
/**
 * scan_entitlement_php.php — the gate is asked about what the run actually READS.
 *
 * COMPLIANCE-5. `durableScanContext()` built its ownership map by walking
 * `$plan['hostFields']`, which `ruleHostForms()` fills from `$rule['fields']`
 * alone. ScanService turns that map into the entitlement set
 * `ScanAuthorization::mayStart()` is asked about. But the READ is
 * `$plan['readSet']`: rule fields PLUS every field a `when` or `assert` operand
 * references PLUS every unique-composite `with` field.
 *
 * So the gate was asked where the RULES LIVE while getData was asked for the
 * OPERANDS, and a designer with explicit No Access to an instrument could start
 * a scan that read it — and whose findings differed by its values. It reproduced
 * on every rule kind, on branch operands, and on both configuration channels.
 * mayStart()'s own docblock already said the entitlement is "every form the run
 * will read"; the caller was the half that was wrong.
 *
 * WHY A SUITE OF ITS OWN. Nothing in the existing 29 could see this: every one
 * of them drives a module whose fixture user has access to everything, so the
 * entitlement set is never compared against a rights row that says no. Applying
 * the whole fix to a scratch tree left all 29 green, which is the finding
 * restated rather than a verification.
 *
 * EVERY SET HERE IS OBTAINED FROM PRODUCTION, NEVER WRITTEN DOWN. The asked-for
 * fields come from the REDCap mock's recorded getData parameters; the entitled
 * forms come from durableScanContext()'s own ownership map through the same loop
 * ScanService runs. A test that built either by hand would be asserting that two
 * copies of one idea agree.
 *
 * Run:  php tests/scan_entitlement_php.php
 */

namespace {
    $n = 0; $fail = 0;
    function check($label, $cond) {
        global $n, $fail; $n++;
        if (!$cond) { $fail++; fwrite(STDERR, "FAIL: $label\n"); }
    }
}

namespace ExternalModules {
    abstract class AbstractExternalModule {
        public $subSettings = [];
        public $projectSettings = [];
        public $systemSettings = [];
        public $projectIdReturn = 149;
        public $userReturn = null;
        public $logCalls = [];
        public function getSubSettings($k, $pid = null) { return isset($this->subSettings[$k]) ? $this->subSettings[$k] : []; }
        public function getProjectSetting($k, $pid = null) { return isset($this->projectSettings[$k]) ? $this->projectSettings[$k] : null; }
        public function getSystemSetting($k) { return isset($this->systemSettings[$k]) ? $this->systemSettings[$k] : null; }
        public function setProjectSetting($k, $v, $pid = null) { $this->projectSettings[$k] = $v; }
        public function getProjectId() { return $this->projectIdReturn; }
        public function getUrl($p) { return '/x/' . $p; }
        public function log($m, $p = []) { $this->logCalls[] = [$m, $p]; return count($this->logCalls); }
        public function getUser() { return $this->userReturn; }
        public function initializeJavascriptModuleObject() { return ''; }
        public function getJavascriptModuleObjectName() { return 'UV.mod'; }
    }

    /**
     * A user whose rights are handed in whole.
     *
     * hasDesignRights() and getRights() are what ScanPageView::scanScope() calls
     * through is_callable, so this has to be an object with both — an array
     * would exercise the refusal path and prove nothing about the entitlement.
     */
    class RightsUser {
        private $r;
        public function __construct(array $r) { $this->r = $r; }
        public function hasDesignRights() { return !empty($this->r['design']); }
        public function getRights($pid = null) { return $this->r; }
        public function isSuperUser() { return false; }
    }
}

namespace {
    class REDCap {
        public static $dictionary = [];
        public static $data = [];
        public static $lastGetDataParams = null;
        public static $groupNames = [];
        public static function getDataDictionary($pid = null, $fmt = 'array', $x = true, $f = null, $forms = null, $c = false) {
            return self::$dictionary;
        }
        public static function getData($params = []) {
            self::$lastGetDataParams = $params;
            return self::$data;
        }
        public static function getRecordIdField() { return 'record_id'; }
        public static function getGroupNames($u = false, $g = null) {
            if ($g === null) return self::$groupNames;
            return isset(self::$groupNames[(int) $g]) ? self::$groupNames[(int) $g] : '';
        }
        public static function getEventNames($u = false, $x = false, $e = null) { return 'event_' . $e . '_arm_1'; }
        public static function getInstrumentEventMappings($pid = null) { return []; }
        public static function getRepeatingFormsEvents($pid = null) { return []; }
        public static function isRepeatingForm($e = null, $f = null) { return false; }
        public static function getUserRights($pid = null, $u = null) { return []; }
    }

    require_once __DIR__ . '/../UniversalValidator.php';

    /** Enough of a result for ModuleDb, which reads through fetch_row(). */
    class UvRes {
        private $r; private $i = 0;
        public function __construct($r) { $this->r = $r; }
        public function fetch_row() { return isset($this->r[$this->i]) ? $this->r[$this->i++] : null; }
    }

    /**
     * The module the ScanService block drives.
     *
     * IT NEEDS A query(), and that is the point of the block. available() gates
     * on the flags, on Schema::health() and on the record-enumeration
     * capability, all of which read the database - so a module without one is
     * refused before the entitlement loop is ever reached, and a test that
     * stopped there would assert nothing about the loop. Every answer below is
     * the minimum that lets start() get as far as mayStart(); no run exists, so
     * the slot is free and the gate is the next thing it meets.
     */
    class UvEntModule extends \ExternalModules\AbstractExternalModule
    {
        /** A run row for cancel() to find, when a case wants one. */
        public $runRow = null;
        public function query($sql, $params = []) {
            if (strpos($sql, 'FROM ' . \INSPIRE\UniversalValidator\Scan\Schema::table('scan_run')) !== false
                    && strpos($sql, 'WHERE run_id = ?') !== false) {
                return new \UvRes($this->runRow === null ? [] : [$this->runRow]);
            }
            // ModuleDb::exec() asks for the row count straight after every
            // write, and treats a missing one as a store failure rather than as
            // zero - so a fake that answered only the write would throw out of
            // cancel() before the assertion could see it.
            if (strpos($sql, 'ROW_COUNT()') !== false) return new \UvRes([['1']]);
            if (strncasecmp(ltrim($sql), 'UPDATE', 6) === 0) return new \UvRes([]);
            if (strpos($sql, 'MAX(version)') !== false) {
                return new \UvRes([[(string) \INSPIRE\UniversalValidator\Scan\Schema::VERSION]]);
            }
            if (strpos($sql, 'information_schema.tables') !== false) return new \UvRes([['1']]);
            if (strpos($sql, 'SHOW TABLES') !== false)     return new \UvRes([['redcap_record_list']]);
            if (strpos($sql, 'SHOW GRANTS') !== false)     return new \UvRes([['GRANT ALL PRIVILEGES ON `rc`.* TO `u`@`h`']]);
            if (strpos($sql, 'log_event_table') !== false) return new \UvRes([['redcap_log_event']]);
            if (strpos($sql, 'FROM redcap_record_list') !== false) return new \UvRes([['1']]);
            if (strpos($sql, 'FROM redcap_data') !== false)        return new \UvRes([['1']]);
            return new \UvRes([]);
        }
        public function durableScanContext($pid, array $opts = [], $dagFilter = null) {
            $v = new \INSPIRE\UniversalValidator\UniversalValidator();
            $v->projectIdReturn = $pid;
            $v->projectSettings = $this->projectSettings;
            $v->subSettings = $this->subSettings;
            return $v->durableScanContext($pid, $opts, $dagFilter);
        }
    }

    // Defined once, because it is the only literal here that has to carry both
    // an escaped quote and a JSON body.
    define('ASSERT_ANNOT', '@UVASSERT={"assert":"[b_secret] = ' . chr(39) . 'x' . chr(39) . '"}');
    define('CHOICES_ANNOT', '@UVCHOICES={"when":"[b_secret] = ' . chr(39) . '1' . chr(39)
        . '","hide":["1"]}');

    /** A dictionary row, in the shape REDCap returns. */
    function fld($form, $annotation = '', $type = 'text') {
        return ['field_type' => $type, 'form_name' => $form, 'identifier' => '',
                'field_annotation' => $annotation, 'select_choices_or_calculations' => '',
                'branching_logic' => '', 'text_validation_type_or_show_slider_number' => ''];
    }

    /**
     * The plan for one dictionary, and the two things the gate is decided from.
     *
     * `asked` is what getData was told to fetch — read back off the mock rather
     * than predicted. `entitled` runs ScanService's own null-is-unknown loop over
     * durableScanContext()'s ownership map. Both come from production code.
     */
    function planOf(array $dict, array $opts = []) {
        \REDCap::$dictionary = $dict;
        \REDCap::$data = ['1' => [1 => ['record_id' => '1']]];
        \REDCap::$lastGetDataParams = null;
        $m = new \INSPIRE\UniversalValidator\UniversalValidator();
        $m->projectIdReturn = 149;
        $m->projectSettings = ['log-values' => ''];
        $m->subSettings = isset($opts['subs']) ? $opts['subs'] : [];
        $ctx = $m->durableScanContext(149, ['generation' => 1, 'runSeq' => 1], null);
        $asked = [];
        if (!empty($ctx['ok']) && is_callable($ctx['read'])) {
            $ctx['read'](['1']);
            $p = \REDCap::$lastGetDataParams;
            $asked = (is_array($p) && isset($p['fields'])) ? $p['fields'] : [];
        }
        $forms = [];
        $unknown = [];
        foreach ((isset($ctx['ownership']) ? $ctx['ownership'] : []) as $f => $form) {
            if ($form === null || $form === '') { $unknown[] = (string) $f; continue; }
            $forms[$form] = true;
        }
        sort($asked, SORT_STRING);
        return ['ctx' => $ctx, 'asked' => $asked, 'forms' => array_keys($forms),
                'unknown' => $unknown, 'module' => $m];
    }

    /**
     * A rights row as ScanPageView::scanScope() would hand it over.
     *
     * BUILT THROUGH normalizeRights(), not by hand. That function always sets
     * `superUser`, and barredForms() short-circuits to [] on a truthy one — so a
     * hand-made row without the key tests a value production never produces, and
     * a hand-made row WITH it set true tests nothing at all.
     */
    function rights(array $over = []) {
        $r = array_merge(['design' => true, 'data_export_tool' => '1',
                          'group_id' => null, 'forms' => ['fa' => '1', 'fb' => '1']], $over);
        return \INSPIRE\UniversalValidator\ScanPageView::normalizeRights(
            $r, new \ExternalModules\RightsUser($r));
    }

    function mayStart($rightsRow, $plan) {
        return \INSPIRE\UniversalValidator\Scan\ScanAuthorization::mayStart(
            $rightsRow, $plan['forms'], $plan['unknown']);
    }

    /* =====================================================================
     * THE HOLE, ONE RULE KIND AT A TIME
     *
     * Each block is the same shape: a rule on instrument `fa`, an operand on
     * instrument `fb`, and a designer with explicit No Access to `fb`. The pair
     * of checks is the whole finding — getData IS asked for the operand, and
     * the gate MUST be asked about its instrument.
     * ===================================================================== */
    {
        $bar = rights(['forms' => ['fa' => '1', 'fb' => '0']]);

        // 1. @UVASSERT — the operand is in the assertion.
        $p = planOf(['record_id' => fld('fa'),
                     'a_val' => fld('fa', '@UVASSERT={"assert":"[b_secret] = \'x\'"}'),
                     'b_secret' => fld('fb')]);
        check('C5 assert: the operand IS read — getData is asked for it',
            in_array('b_secret', $p['asked'], true));
        check('C5 assert: so the instrument that owns it is in the entitlement',
            in_array('fb', $p['forms'], true));
        check('C5 assert: and a designer barred from it cannot start the scan',
            mayStart($bar, $p)['ok'] === false);

        // 2. @UVREQUIRED with a `when`.
        $p = planOf(['record_id' => fld('fa'),
                     'a_val' => fld('fa', '@UVREQUIRED={"when":"[b_secret] = \'1\'"}'),
                     'b_secret' => fld('fb')]);
        check('C5 required-when: the operand is read',
            in_array('b_secret', $p['asked'], true));
        check('C5 required-when: and its instrument is entitled',
            in_array('fb', $p['forms'], true));
        check('C5 required-when: and a barred designer is refused',
            mayStart($bar, $p)['ok'] === false);

        // 3. @UVUNIQUE with a composite partner on the barred instrument.
        $p = planOf(['record_id' => fld('fa'),
                     'sid' => fld('fa', '@UVUNIQUE={"with":["b_secret"]}'),
                     'b_secret' => fld('fb')]);
        check('C5 unique-with: the composite partner is read',
            in_array('b_secret', $p['asked'], true));
        check('C5 unique-with: and its instrument is entitled',
            in_array('fb', $p['forms'], true));
        check('C5 unique-with: and a barred designer is refused',
            mayStart($bar, $p)['ok'] === false);

        // 4. @UVCHOICES IS THE OTHER DIRECTION, and it belongs here because the
        //    obvious wrong fix is "entitle every instrument any annotation
        //    mentions". @UVCHOICES is a browser-side display rule; the scan does
        //    not evaluate it, so its operand is never read and its instrument
        //    must NOT be entitled. Measured: the plan holds zero live rules for
        //    this dictionary.
        $p = planOf(['record_id' => fld('fa'),
                     'c_val' => fld('fa', CHOICES_ANNOT, 'dropdown'),
                     'b_secret' => fld('fb')]);
        check('C5 choices: a display-only rule contributes no live scan rule',
            count($p['ctx']['rules']) === 0);
        check('C5 choices: so its operand is not read',
            !in_array('b_secret', $p['asked'], true));
        check('C5 choices: and its instrument is not entitled',
            !in_array('fb', $p['forms'], true));

        // 5. A BRANCH operand — the shape a fix that read only a rule's top-level
        //    `when`/`assert` keys would miss. Branches are not written down:
        //    Branching::resolve() SYNTHESISES them from two conditional rules
        //    that share a field, so the operand ends up one level deeper than
        //    any annotation puts it.
        $p = planOf(['record_id' => fld('fa'), 'a_val' => fld('fa'), 'b_secret' => fld('fb')],
            ['subs' => ['rules' => [
                ['rule-type' => 'constraint', 'fields' => ['a_val'],
                 'when' => "[a_val] <> ''", 'assert' => "[a_val] <> 'z'"],
                ['rule-type' => 'constraint', 'fields' => ['a_val'],
                 'when' => "[b_secret] = '1'", 'assert' => "[a_val] <> 'y'"],
            ]]]);
        check('C5 branch: the fixture really did produce a branched rule',
            count($p['ctx']['rules']) === 1
            && isset($p['ctx']['rules'][0]['branches'])
            && count($p['ctx']['rules'][0]['branches']) === 2);
        check('C5 branch: a branch condition operand is read',
            in_array('b_secret', $p['asked'], true));
        check('C5 branch: and its instrument is entitled',
            in_array('fb', $p['forms'], true));
        check('C5 branch: and a barred designer is refused',
            mayStart($bar, $p)['ok'] === false);

        // 6. THE SETTINGS CHANNEL, not the annotation one. A rule configured
        //    through the module dialog reaches the same planner by a different
        //    road, and a fix that special-cased annotations would miss it.
        $p = planOf(['record_id' => fld('fa'), 'a_val' => fld('fa'), 'b_secret' => fld('fb')],
            ['subs' => ['rules' => [[
                'rule-type' => 'constraint', 'fields' => ['a_val'],
                'assert' => "[b_secret] = 'x'"]]]]);
        check('C5 settings channel: an operand configured through the dialog is read',
            in_array('b_secret', $p['asked'], true));
        check('C5 settings channel: and its instrument is entitled',
            in_array('fb', $p['forms'], true));
        check('C5 settings channel: and a barred designer is refused',
            mayStart($bar, $p)['ok'] === false);
    }

    /* =====================================================================
     * THE PROPERTY, RATHER THAN SIX INSTANCES OF IT
     * ===================================================================== */
    {
        $p = planOf(['record_id' => fld('fa'),
                     'a_val' => fld('fa', '@UVASSERT={"assert":"[b_secret] = \'x\'"}'),
                     'b_secret' => fld('fb'), 'c_idle' => fld('fc')]);

        // THE EXACT SET, both directions. This is the check that catches a
        // future column enricher reading a field it never added to the read set,
        // and equally a derivation that entitles more than the run reads.
        $ownKeys = array_keys($p['ctx']['ownership']);
        sort($ownKeys, SORT_STRING);
        check('C5: the entitlement is derived from exactly the fields getData is asked for',
            $ownKeys === $p['asked']);
        // THE UPPER BOUND, and it is the check standing between this fix and a
        // module that refuses every scan on every project.
        check('C5: an instrument no rule reads is NOT in the entitlement set',
            !in_array('fc', $p['forms'], true));
        check('C5: and the rule host is still in it — the fix widens, it does not replace',
            in_array('fa', $p['forms'], true));

        // THE CONTROL. Without it every refusal above is satisfied by a gate
        // that refuses everybody.
        check('C5 control: a designer who CAN read the operand instrument still starts',
            mayStart(rights(), $p)['ok'] === true);
    }

    /* =====================================================================
     * WHICH REFUSAL, AND WHAT IT SAYS
     * ===================================================================== */
    {
        $p = planOf(['record_id' => fld('fa'),
                     'a_val' => fld('fa', '@UVASSERT={"assert":"[b_secret] = \'x\'"}'),
                     'b_secret' => fld('fb')]);
        $why = mayStart(rights(['forms' => ['fa' => '1', 'fb' => '0']]), $p)['why'];
        check('C5: it is refused as an ACCESS problem, not as unknown ownership',
            strpos($why, 'do not have access') !== false
            && strpos($why, 'could not be located') === false);
        // NAMED. Without this, the message change ships with no assertion at all
        // and the suite cannot tell which variant is live.
        check('C5: and the refusal names the instrument, so it is actionable',
            strpos($why, 'fb') !== false);
        check('C5: and does not name an instrument the user CAN read',
            strpos($why, 'fa') === false);

        // A RIGHTS ROW THAT COULD NOT BE READ IS NOT A STATEMENT ABOUT
        // INSTRUMENTS. barredForms() returns the whole entitlement in that case,
        // and now that the entitlement is the read set, printing it would
        // enumerate every instrument the run touches for a failure that has
        // nothing to do with any of them.
        $noForms = mayStart(rights(['forms' => 'not-an-array']), $p);
        check('C5: an unreadable rights row refuses',
            $noForms['ok'] === false);
        check('C5: and says so, rather than enumerating every instrument',
            strpos($noForms['why'], 'could not be read') !== false
            && strpos($noForms['why'], 'fb') === false);
    }

    /* =====================================================================
     * A FIELD THAT CANNOT BE PLACED
     *
     * The fail-closed arm, and it is newly REACHABLE: hostFields only ever held
     * forms that were determined, so nothing could set this flag before.
     *
     * The counter-argument was that the module already refuses such a rule
     * (`n|unlocatable`), so its instrument is never read. That is false —
     * scanPlan() builds $readSet from every live rule's `fields`
     * unconditionally, the unlocatable note does not remove the rule from
     * $live, and durableScanContext hands array_keys($plan['readSet']) to
     * getData. The value IS read, so refusing is the only answer consistent
     * with the rest of the file.
     * ===================================================================== */
    {
        $p = planOf(['record_id' => fld('fa'),
                     'ghost' => fld('', '@UVREQUIRED')]);
        check('C5 unplaceable: the field is still read, which is why it must be gated',
            in_array('ghost', $p['asked'], true));
        check('C5 unplaceable: it is recorded as NULL, not dropped from the map',
            array_key_exists('ghost', $p['ctx']['ownership'])
            && $p['ctx']['ownership']['ghost'] === null);
        $u = mayStart(rights(), $p);
        check('C5 unplaceable: and the scan is refused even with every right granted',
            $u['ok'] === false);
        check('C5 unplaceable: as unknown ownership, not as an access problem',
            strpos($u['why'], 'could not be located') !== false
            && strpos($u['why'], 'do not have access') === false);
        // NAMED, for the same reason the barred arm names instruments. This is
        // a fixable dictionary problem — the field exists, its form_name does
        // not — and a refusal saying only "at least one field" sends an
        // administrator through the whole dictionary.
        check('C5 unplaceable: and the refusal names the field',
            strpos($u['why'], 'ghost') !== false);
    }

    /* =====================================================================
     * CANCEL IS NOT READ, AND THIS IS THE ONE THAT KEEPS THE SLOT FREE
     *
     * Widening the entitlement would otherwise make a run that was legitimately
     * started before the deploy simultaneously unworkable, unreadable AND
     * uncancellable — while it holds active_slot = 1, so the whole project
     * answers busy. The three paths that release a slot are finish() from a
     * worker pass (gated by mayWork), reapCancelled() (needs a successful
     * cancel), and ScanRetention::expireAbandoned(), which has no caller. The
     * exit would be a DBA.
     *
     * mayCancel's own docblock forbids this shape — "Ownership would only add a
     * way for a wedged run to become unstoppable" — and keying it on instrument
     * entitlement rather than creator identity does not make it a different
     * shape. So cancel keeps the narrower host-only set.
     * ===================================================================== */
    {
        $A = 'INSPIRE\\UniversalValidator\\Scan\\ScanAuthorization';
        $p = planOf(['record_id' => fld('fa'),
                     'a_val' => fld('fa', '@UVASSERT={"assert":"[b_secret] = \'x\'"}'),
                     'b_secret' => fld('fb')]);
        $bar = rights(['forms' => ['fa' => '1', 'fb' => '0']]);

        // The host-only set, built the way ScanService::entitlement() builds
        // cancelForms — from the plan's hostFields, which is still populated.
        $cancelForms = [];
        foreach ($p['ctx']['plan']['hostFields'] as $hosts) {
            foreach ($hosts as $form => $_) $cancelForms[$form] = true;
        }
        $cancelForms = array_keys($cancelForms);
        check('C5 cancel: the two sets are genuinely different, or this proves nothing',
            $p['forms'] !== $cancelForms && in_array('fb', $p['forms'], true)
            && !in_array('fb', $cancelForms, true));
        check('C5 cancel: the barred designer may NOT work that run',
            $A::mayWork($bar, $p['forms'], null, $p['unknown'])['ok'] === false);
        check('C5 cancel: but they may still STOP it, so it cannot wedge the project',
            $A::mayCancel($bar, $cancelForms, null)['ok'] === true);

        // AND THROUGH ScanService ITSELF, not through a second copy of its loop.
        // The two checks above compute cancelForms here; mutating the real
        // entitlement() to feed cancel the WIDENED set would leave them green,
        // which is the exact weakness this suite exists to close elsewhere.
        // cancel() is the verb to drive: status() gates on mayRead first, so a
        // barred designer never reaches its mayCancel at all.
        $cm = new \UvEntModule();
        $cm->systemSettings = [\INSPIRE\UniversalValidator\Scan\ScanService::SYS_FLAG => '1'];
        $cm->projectSettings = [\INSPIRE\UniversalValidator\Scan\ScanService::PROJ_FLAG => '1',
                                'log-values' => ''];
        $cm->userReturn = new \ExternalModules\RightsUser(
            ['design' => true, 'data_export_tool' => '1', 'group_id' => null,
             'forms' => ['fa' => '1', 'fb' => '0']]);
        // run_id, project_id, scope_dag, phase, terminal, coverage, detail,
        // values_state, policy_revision, fingerprint, manifest_total,
        // manifest_done, cursor_ordinal, lease_epoch, generation_id, run_seq,
        // created_by, detail_rows, detail_bytes, fence_open, fence_target,
        // cancel_requested_at
        $cm->runRow = ['77', '149', null, 'scanning', null, 'partial', 'complete', 'none',
                       '1', 'fp', '10', '3', '3', '1', '1', '1', 'tester', '2', '400',
                       '9', '9', null];
        \REDCap::$dictionary = ['record_id' => fld('fa'),
                                'a_val' => fld('fa', ASSERT_ANNOT),
                                'b_secret' => fld('fb')];
        $stopped = (new \INSPIRE\UniversalValidator\Scan\ScanService($cm))->cancel(149, 77);
        // The store cannot serve the UPDATE, so the cancel does not SUCCEED -
        // what is asserted is that it was not refused as an ENTITLEMENT
        // question, which is the arm that would wedge the project.
        check('C5 cancel: ScanService does not refuse the stop on instrument access',
            $stopped['why'] === null || strpos((string) $stopped['why'], 'do not have access') === false);
        check('C5 cancel: and the same actor IS refused a read of the same run',
            strpos((string) (new \INSPIRE\UniversalValidator\Scan\ScanService($cm))
                ->status(149, 77)['why'], 'do not have access') !== false);
        // AND CANCEL IS STILL A GATE. It is narrower, not absent.
        check('C5 cancel: a user without design rights still cannot stop it',
            $A::mayCancel(rights(['design' => false]), $cancelForms, null)['ok'] === false);
        check('C5 cancel: nor one without full export rights',
            $A::mayCancel(rights(['data_export_tool' => '2']), $cancelForms, null)['ok'] === false);
        check('C5 cancel: nor one barred from the instrument the RULE lives on',
            $A::mayCancel(rights(['forms' => ['fa' => '0', 'fb' => '1']]),
                          $cancelForms, null)['ok'] === false);
    }

    /* =====================================================================
     * THE NULL ENCODING, DRIVEN THROUGH ScanService ITSELF
     *
     * The whole fail-closed property rests on one loop in ScanService turning
     * null and '' into a denial rather than into an omission. A test that copies
     * that loop asserts the copy; this one asks the real class, so mutating the
     * real loop reddens it.
     * ===================================================================== */
    {
        require_once __DIR__ . '/../php/ScanCapabilities.php';
        require_once __DIR__ . '/../php/Scan/Schema.php';
        require_once __DIR__ . '/../php/Scan/ScanDb.php';
        require_once __DIR__ . '/../php/Scan/DbError.php';
        require_once __DIR__ . '/../php/Scan/ScanStore.php';
        require_once __DIR__ . '/../php/Scan/ScanOutcome.php';
        require_once __DIR__ . '/../php/Scan/ScanPhase.php';
        require_once __DIR__ . '/../php/Scan/ScanPolicy.php';
        require_once __DIR__ . '/../php/Scan/Hmac.php';
        require_once __DIR__ . '/../php/Scan/ReasonCode.php';
        require_once __DIR__ . '/../php/Scan/SqlScanStore.php';
        require_once __DIR__ . '/../php/Scan/WorkerSlots.php';
        require_once __DIR__ . '/../php/Scan/ScanRetention.php';
        require_once __DIR__ . '/../php/Scan/RecordManifestSource.php';
        require_once __DIR__ . '/../php/Scan/SourceFence.php';
        require_once __DIR__ . '/../php/Scan/ScanPlanner.php';
        require_once __DIR__ . '/../php/Scan/WorkBudget.php';
        require_once __DIR__ . '/../php/Scan/UniqueFinalizer.php';
        require_once __DIR__ . '/../php/Scan/CatchUp.php';
        require_once __DIR__ . '/../php/Scan/RollupBuilder.php';
        require_once __DIR__ . '/../php/Scan/ScanPromotion.php';
        require_once __DIR__ . '/../php/Scan/ScanWorker.php';
        require_once __DIR__ . '/../php/Scan/ScanStoreUnavailable.php';
        require_once __DIR__ . '/../php/Scan/ScanService.php';

        // A module whose user is barred from fb and whose db answers nothing,
        // so start() reaches the entitlement gate and refuses there. What is
        // asserted is WHICH sentence comes back, which is only reachable if the
        // real loop turned the ownership map into the real set.
        $mod = new \UvEntModule();
        $mod->systemSettings = [\INSPIRE\UniversalValidator\Scan\ScanService::SYS_FLAG => '1'];
        $mod->projectSettings = [\INSPIRE\UniversalValidator\Scan\ScanService::PROJ_FLAG => '1',
                                 'log-values' => ''];
        \REDCap::$dictionary = ['record_id' => fld('fa'),
                                'a_val' => fld('fa', '@UVASSERT={"assert":"[b_secret] = \'x\'"}'),
                                'b_secret' => fld('fb')];
        $mod->userReturn = new \ExternalModules\RightsUser(
            ['design' => true, 'data_export_tool' => '1', 'group_id' => null,
             'forms' => ['fa' => '1', 'fb' => '0']]);
        $svc = new \INSPIRE\UniversalValidator\Scan\ScanService($mod);
        $started = $svc->start(149);
        check('C5 service: start() refuses the barred designer through the real loop',
            $started['ok'] === false);
        check('C5 service: and it is the access refusal, naming the instrument',
            strpos($started['why'], 'do not have access') !== false
            && strpos($started['why'], 'fb') !== false);

        // THE SAME PATH, unplaceable instead of barred: this is the null arm.
        \REDCap::$dictionary = ['record_id' => fld('fa'), 'ghost' => fld('', '@UVREQUIRED')];
        $mod2 = new \UvEntModule();
        $mod2->systemSettings = $mod->systemSettings;
        $mod2->projectSettings = $mod->projectSettings;
        $mod2->userReturn = new \ExternalModules\RightsUser(
            ['design' => true, 'data_export_tool' => '1', 'group_id' => null,
             'forms' => ['fa' => '1', 'fb' => '1']]);
        $svc2 = new \INSPIRE\UniversalValidator\Scan\ScanService($mod2);
        $s2 = $svc2->start(149);
        check('C5 service: a field that cannot be placed refuses through the real loop too',
            $s2['ok'] === false && strpos($s2['why'], 'could not be located') !== false);
    }

    echo "scan_entitlement_php: $n checks, $fail failure(s)\n";
    exit($fail ? 1 : 0);
}
