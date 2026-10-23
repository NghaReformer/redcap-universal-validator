<?php
/**
 * Universal Field Validator — REDCap external module.
 *
 * Injects the verified check-character engine on data-entry forms and surveys,
 * configured entirely through the module's project settings (no code pasting,
 * no JavaScript Injector). The browser is the enforcement point; a
 * redcap_save_record hook additionally re-checks saved values on the server as
 * a best-effort, after-the-write AUDIT wherever REDCap invokes that hook.
 * Whether the hook fires for API and Data Import Tool writes depends on the
 * REDCap version and import path — see README "Server-side safety net" and
 * docs/TESTING.md before treating those paths as covered.
 *
 * The client engine (js/engine.js) and the server engine (php/CheckCharacter.php)
 * are both checked against the same Python-generated fixture (tests/), so the two
 * runtimes always agree with each other and with the ID generator.
 */

namespace INSPIRE\UniversalValidator;

use ExternalModules\AbstractExternalModule;

require_once __DIR__ . '/php/CheckCharacter.php';
require_once __DIR__ . '/php/AnnotationRules.php';
require_once __DIR__ . '/php/ModeRegistry.php';
require_once __DIR__ . '/php/Logic.php';
require_once __DIR__ . '/php/GrowthReference.php';
require_once __DIR__ . '/php/GrowthOutputs.php';
require_once __DIR__ . '/php/GrowthCurve.php';
require_once __DIR__ . '/php/TemporalIntegration.php';
require_once __DIR__ . '/php/ReturnedValues.php';
require_once __DIR__ . '/php/ScanRuns.php';
require_once __DIR__ . '/php/LiveLookups.php';
require_once __DIR__ . '/php/ValueStamps.php';
require_once __DIR__ . '/php/ValueHistory.php';
require_once __DIR__ . '/php/RoleBook.php';
require_once __DIR__ . '/php/Branching.php';
require_once __DIR__ . '/php/ScanPageView.php';
require_once __DIR__ . '/php/FindingSink.php';
require_once __DIR__ . '/php/ScanCapabilities.php';
require_once __DIR__ . '/php/ScanDimensions.php';
require_once __DIR__ . '/php/MessageCatalog.php';
require_once __DIR__ . '/php/ScanColumns.php';
// The durable scan. Loaded here rather than lazily because a partial load is
// how a class that decides an authorisation ends up absent at the moment it is
// asked - and the framework has no autoloader to fall back on.
require_once __DIR__ . '/php/Scan/Schema.php';
require_once __DIR__ . '/php/Scan/ScanDb.php';
require_once __DIR__ . '/php/Scan/DbError.php';
require_once __DIR__ . '/php/Scan/ScanStoreUnavailable.php';
require_once __DIR__ . '/php/Scan/ScanStore.php';
require_once __DIR__ . '/php/Scan/ScanOutcome.php';
require_once __DIR__ . '/php/Scan/ScanPhase.php';
require_once __DIR__ . '/php/Scan/ScanPolicy.php';
require_once __DIR__ . '/php/Scan/ScanAuthorization.php';
require_once __DIR__ . '/php/Scan/Hmac.php';
require_once __DIR__ . '/php/Scan/ReasonCode.php';
require_once __DIR__ . '/php/Scan/FindingRowMapper.php';
require_once __DIR__ . '/php/Scan/ScanReport.php';
require_once __DIR__ . '/php/Scan/SqlScanStore.php';
require_once __DIR__ . '/php/Scan/WorkerSlots.php';
require_once __DIR__ . '/php/Scan/ScanRetention.php';
require_once __DIR__ . '/php/Scan/RecordManifestSource.php';
require_once __DIR__ . '/php/Scan/FieldValues.php';
require_once __DIR__ . '/php/Scan/SourceFence.php';
require_once __DIR__ . '/php/Scan/ScanPlanner.php';
require_once __DIR__ . '/php/Scan/WorkBudget.php';
require_once __DIR__ . '/php/Scan/UniqueFinalizer.php';
require_once __DIR__ . '/php/Scan/CatchUp.php';
require_once __DIR__ . '/php/Scan/RollupBuilder.php';
require_once __DIR__ . '/php/Scan/ScanPromotion.php';
require_once __DIR__ . '/php/Scan/ScanWorker.php';
require_once __DIR__ . '/php/Scan/ScanService.php';

class UniversalValidator extends AbstractExternalModule
{
    use TemporalIntegration;
    use ReturnedValues;
    use ScanRuns;
    use LiveLookups;
    /**
     * Per-request data dictionary cache, keyed BY PROJECT ID.
     *
     * Keyed, because one process legitimately touches more than one project;
     * and holding successes only, because a failed read is not an answer to
     * cache. See dataDictionary().
     */
    private $ddCache = [];

    /** pid => conditionNumbers() map, built once per request from the dictionary. */
    private $conditionNumbersCache = [];

    /** pid => field => branchingOf() answer, from a dictionary that was read. */
    private $branchingCache = [];

    /** Per-request HMAC key cache: false = unresolved, null = unavailable. */
    private $hmacKey = false;

    /** How many findings of the running legacy scan a reason stands for (scanRecord, scanProject). */
    private $scanJustified = 0;

    /** The engine's default settings; each rule may override any of them. */
    private function defaults()
    {
        return [
            'algorithm'   => 'iso7064_mod37_36',
            'idPattern'   => null,
            'alternates'  => null,
            'source'      => 'normalized_id',
            'strip'       => "-/ _|\\",
            // OFF by default: a visible "should end in X" hint can entice
            // staff to force-fit a mistyped ID instead of re-scanning it.
            // Opt in per rule (dialog checkbox / "suggestFix" JSON key).
            'suggestFix'  => false,
            'keepChars'   => '',
            'idLengths'   => null,
            'idMinLen'    => 8,
            'idMaxLen'    => 14,
            'expectedIds' => null,
            'blockSave'   => 'off',
        ];
    }

    // -- hooks --------------------------------------------------------------

    public function redcap_data_entry_form_top($project_id, $record, $instrument, $event_id, $group_id, $repeat_instance = 1)
    {
        // Record context is threaded through so "when" conditions can snapshot
        // saved values of fields that are not on the rendered page.
        $this->injectClient($project_id, 'form', $record, $instrument, $event_id, $repeat_instance);
    }

    public function redcap_survey_page_top($project_id, $record, $instrument, $event_id, $group_id, $survey_hash, $response_id, $repeat_instance = 1)
    {
        // The context flag makes the client suppress technical configuration
        // detail in front of survey respondents (who cannot act on it); the
        // same problems stay fully visible on staff data-entry forms and in
        // the module log.
        $this->injectClient($project_id, 'survey', $record, $instrument, $event_id, $repeat_instance);
    }

    /**
     * Server-side safety net. redcap_save_record fires AFTER the write, so this
     * is a detection/audit hook, not a hard reject: the client "block save"
     * mode stops human form saves, and this hook logs invalid values for review
     * wherever REDCap invokes it. It mirrors the FULL client rule semantics —
     * single and pooled fields, check character, format pattern, and regex-only
     * (algorithm "none" + pattern) — so the audit has no rule-shape blind spots
     * (UV-003). Audit scope: fields on the SAVED instrument only, when the
     * instrument and data dictionary are known — an unrelated instrument's save
     * must not re-log an old invalid value (PER-001); when either is unknown
     * (some import/API contexts) every configured field is checked instead.
     */
    public function redcap_save_record($project_id, $record, $instrument, $event_id, $group_id, $survey_hash = null, $response_id = null, $repeat_instance = 1)
    {
        // Resolve the log-privacy mode FIRST, outside the try, so the error
        // path below can honor it too — an exception must never leak a record
        // ID that the project's mode says to hash or omit (SEC-003).
        $logMode = $this->logMode($project_id);
        // The module's own write of the values a rule returns is a save of this
        // record too (ReturnedValues::applyReturns). It changes nothing a person
        // entered, so it is not audited a second time.
        if (self::$returnWriting) return;
        $this->returnQueue = [];
        $this->returnBarred = [];
        // A survey respondent's save: a mode whose returned values come from
        // other records writes nothing then (returnsWrittenOnSave).
        $this->surveySave = $survey_hash !== null && $survey_hash !== '';
        // One import saves many records in one request: a lookup answered for
        // an earlier one may no longer be true, and a read kept for the lookups
        // of this request shows this record as it was before this save.
        $this->existsLookupMemo = [];
        $this->refreshExistsReads($project_id, $record);
        // One evaluation budget per record: findingsDelta starts one when the
        // event/instance audit did not, and the next record of an import must
        // not inherit what this one spent.
        $this->temporalBudget = null;
        try {
            // $project_id comes straight from the hook and is reliable in every
            // save context (form, survey, API, import, cron); $this->getProjectId()
            // is NOT (it can be null on import/API), so thread it explicitly into
            // EVERY settings/dictionary read (SEC-002).
            $rules = $this->getRules($project_id);
            if (!$rules) return;
            // Two phases when a rule returns values to fields (@UVRANGE
            // "return"): those rules are judged first, on both paths (the
            // event/instance audit too), and what they return is written before
            // the other rules read the record, so a rule that reads a returned
            // field judges the value this save wrote. Each phase reads the
            // record for itself and fails alone: an error in one is logged and
            // the other still runs.
            $returning = [];
            foreach ($rules as $i => $r) {
                if (empty($r['configError']) && ModeRegistry::returnFields($r)) $returning[$i] = true;
            }
            $phases = $returning ? [array_keys($returning), array_keys(array_diff_key($rules, $returning))] : [array_keys($rules)];
            foreach ($phases as $p => $only) {
                if ($p > 0) $this->applyReturns($logMode, $project_id, $record, $instrument, $event_id, $repeat_instance);
                if (!$only) continue;
                try {
                    $this->auditPhase($rules, $only, $project_id, $record, $instrument, $event_id, $group_id, $repeat_instance, $logMode);
                } catch (\Throwable $e) {
                    $this->logAuditError($logMode, $project_id, $record, $instrument, $e, 'audit');
                }
            }
        } catch (\Throwable $e) {
            // Never let an audit failure abort the save or vanish without a trace.
            $this->logAuditError($logMode, $project_id, $record, $instrument, $e, 'audit');
        } finally {
            // Whatever a phase returned and did not write yet (the last phase,
            // an early end): written here, once.
            $this->applyReturns($logMode, $project_id, $record, $instrument, $event_id, $repeat_instance);
        }
    }

    /**
     * One phase of the save audit: the rules whose indexes $only lists, out of
     * the project's $rules (all of them decide which fields are claimed
     * twice). The event/instance rules go first, then the others.
     */
    private function auditPhase(array $rules, array $only, $project_id, $record, $instrument, $event_id, $group_id, $repeat_instance, $logMode)
    {
        $only = array_fill_keys($only, true);
        try {
            $this->auditTemporalRules($rules, $project_id, $record, $instrument, $event_id, $repeat_instance, $logMode, array_keys($only));
        } catch (\Throwable $e) {
            foreach ($rules as $i => $rule) if (isset($only[$i]) && empty($rule['configError']) && TemporalRules::extended($rule)) {
                $this->logUnconfigurable($i, $rule['fields'], 'Extended audit incomplete: evaluation unavailable; run the validation scan.', $instrument, $event_id, $repeat_instance);
            }
        }
        $plain = array_filter($rules, function ($rule) { return !TemporalRules::extended($rule); });
        $rules = array_intersect_key($plain, $only);
        if (!$rules) return;

        // A field claimed by more than one live rule has no well-defined
        // verdict — the client refuses to attach a validator there, and the
        // server must not pick one arbitrarily. Mirror the client: skip.
        $dupes = [];
        foreach (self::duplicateFields($plain) as $f) $dupes[$f] = true;

        // Scope to the saved instrument when it is known (null = no filter).
        $onForm = $this->fieldsOnInstrument($project_id, $instrument);

        // A misconfigured rule checks nothing: the form shows its error, and a
        // survey tells the respondent "a configuration issue has been logged
        // for the study team". Log it, once per save, for each such rule on the
        // saved instrument (VAL-15); nothing wrote that entry before.
        foreach ($rules as $ruleIndex => $r) {
            if (empty($r['configError']) || $onForm === null) continue;
            $own = [];
            foreach ((isset($r['fields']) && is_array($r['fields'])) ? $r['fields'] : [] as $f) {
                if (is_string($f) && isset($onForm[$f])) $own[] = $f;
            }
            if ($own) {
                $this->logUnconfigurable($ruleIndex, $own, 'configuration error: ' . (string) $r['configError'],
                    $instrument, $event_id, $repeat_instance);
            }
        }

        $fields = [];
        foreach ($rules as $r) {
            if (!empty($r['configError'])) continue;
            foreach ($r['fields'] as $f) {
                if (isset($dupes[$f])) continue;
                if ($onForm !== null && !isset($onForm[$f])) continue;
                $fields[$f] = true;
            }
        }

        // REVERSE DEPENDENCIES (H-02). A cross-form constraint lives on the
        // instrument carrying the tag, so editing only the REFERENCED side
        // used to change the relationship with nothing checking it: the
        // referenced form installs no client validator, and the audit's
        // instrument scope excluded the dependent rule. Silent corruption of
        // a previously valid pair, findable only by re-saving the host form
        // or running the manual scan.
        //
        // A rule is a dependant of this save when its own field is NOT on the
        // saved instrument but its assert/when REFERENCES a field that is.
        // Those rules — and only those — are added back, so PER-001 still
        // holds: an unrelated instrument with no dependants reads no data and
        // audits nothing, exactly as before.
        $dependents = [];
        if ($onForm !== null) {
            foreach ($rules as $ruleIndex => $r) {
                if (!empty($r['configError'])) continue;
                $ownFieldOnForm = false;
                foreach ((isset($r['fields']) && is_array($r['fields'])) ? $r['fields'] : [] as $f) {
                    if (isset($onForm[$f])) { $ownFieldOnForm = true; break; }
                }
                if ($ownFieldOnForm) continue;      // already audited by the normal scope
                // Conditions, operands and field lists: saving the form that
                // holds a @UVWINDOW "from" date moves every window counted
                // from it, and saving a @UVEXISTS "match" field or a
                // @UVUNIQUE "with" field moves the lookup that reads it.
                $touches = false;
                // A @UVEXISTS "in" field is read in OTHER records, so saving
                // it here moves nothing in this record ('lookup' role).
                foreach (ModeRegistry::refFields($r, ['cond', 'operand', 'fieldList'], ['lookup']) as $rf) {
                    if (isset($onForm[$rf])) { $touches = true; break; }
                }
                if (!$touches) continue;
                $dependents[$ruleIndex] = true;
                foreach ((isset($r['fields']) && is_array($r['fields'])) ? $r['fields'] : [] as $f) {
                    if (isset($dupes[$f])) continue;
                    $fields[$f] = true;
                }
            }
        }
        if (!$fields) return;

        // Parse each live rule's "when" condition ONCE (false sentinel for a
        // string that does not parse — auditRule surfaces it) and widen the
        // read set with every referenced field. Refs are deliberately NOT
        // instrument-filtered: a condition may look at any field on the
        // saved event, wherever it lives.
        $whenAst = [];
        $readSet = $fields;
        foreach ($rules as $ruleIndex => $r) {
            if (!empty($r['configError'])) continue;
            // Branch rules: pre-parse EVERY branch's condition (null = the
            // else branch, false = does not parse) — auditRule picks the
            // active branch per save.
            if (isset($r['branches']) && is_array($r['branches'])) {
                $asts = [];
                foreach ($r['branches'] as $bi => $b) {
                    if (!isset($b['when']) || !is_string($b['when']) || $b['when'] === '') {
                        $asts[$bi] = null;
                        continue;
                    }
                    $p = Logic::parse($b['when']);
                    if (empty($p['ok'])) { $asts[$bi] = false; continue; }
                    $asts[$bi] = $p['ast'];
                    foreach (Logic::referencedFields($p['ast']) as $ref) $readSet[$ref[0]] = true;
                }
                $whenAst[$ruleIndex] = ['branches' => $asts];
                continue;
            }
            if (!isset($r['when']) || !is_string($r['when']) || $r['when'] === '') continue;
            $p = Logic::parse($r['when']);
            if (empty($p['ok'])) { $whenAst[$ruleIndex] = false; continue; }
            $whenAst[$ruleIndex] = $p['ast'];
            foreach (Logic::referencedFields($p['ast']) as $ref) $readSet[$ref[0]] = true;
        }

        // Every other field a rule's configuration reads — the operands of
        // an "assert", the composite "with" fields of a unique rule, and
        // whatever a mode declares in php/modes.json — widens the read set
        // the same way, so the audit can evaluate the rule (mirrors the
        // "when" widening above).
        foreach ($rules as $r) {
            if (!empty($r['configError'])) continue;
            foreach (ModeRegistry::refFields($r) as $f) $readSet[$f] = true;
            // and what a required field's branching logic reads (REQ-1)
            foreach ($this->requiredBranchingRefs($project_id, $r) as $f) $readSet[$f] = true;
        }

        // A survey response that is still open (REQ-5). REDCap saves each page
        // a respondent leaves (Next, Previous Page, Save & Return Later), so a
        // blank on a page not reached yet, or left for later, is no finding.
        // The rules of a mode with "surveySave": "complete" (@UVREQUIRED) are
        // judged once the instrument's status is Complete (2), which REDCap
        // sets when the respondent finishes. A status it could not read
        // leaves them judged, as before; the scan still covers open responses.
        $statusField = null;
        if ($this->surveySave && is_string($instrument) && $instrument !== '') {
            foreach ($rules as $i => $r) {
                if (empty($r['configError']) && !isset($dependents[$i]) && ModeRegistry::waitsForCompleteSurvey($r['type'] ?? '')) {
                    $statusField = $instrument . '_complete';
                    $readSet[$statusField] = true;
                    break;
                }
            }
        }

        // Read every audited + condition-referenced field for this exact
        // record/event/instance in ONE getData call instead of one call per
        // field (UV-007). keepArrays: checkbox refs arrive as code=>0/1 maps.
        $auditResolution = [];
        $values = $this->readValues($project_id, $record, array_keys($readSet), $event_id, $instrument, $repeat_instance, true, $auditResolution);

        // A FAILED read yields an empty value map, and an empty value map is
        // indistinguishable from "every field is blank" to every rule kind —
        // not just constraints. @UVREQUIRED would report a populated field as
        // blank, and a check rule would silently pass an invalid ID. There is
        // nothing to audit, so say so loudly and stop, rather than auditing
        // data we do not have (H-04).
        foreach ($auditResolution as $rstate) {
            if ($rstate === 'unreadable') {
                $this->logAuditError($logMode, $project_id, $record, $instrument,
                    new \RuntimeException('the saved values could not be read, so no rule was checked for this save'),
                    'audit');
                return;
            }
        }
        $surveyOpen = $statusField !== null && isset($values[$statusField]) && is_scalar($values[$statusField])
            && trim((string) $values[$statusField]) !== '' && trim((string) $values[$statusField]) !== '2';

        // HOST CONTEXTS for reverse dependencies (H-03). A dependant's field
        // lives on a DIFFERENT instrument, which may repeat while the form
        // being saved does not. Evaluating it in the trigger's single
        // context checked at most one instance — with a repeating host, the
        // real violations sat in instances nobody looked at, and the one
        // context that WAS examined reported the host field as belonging to
        // another repeating instrument.
        // The whole record is read ONCE, and only when a dependant exists, so
        // an unrelated instrument still reads nothing at all (PER-001).
        $hostContexts = null;
        $depResolution = [];    // host context key => resolution states, computed once
        if ($dependents) {
            $hostContexts = $this->saveHostContexts($project_id, $record, array_keys($readSet), $event_id, $instrument, $logMode);
        }

        // The unique rules of this save share their reads of the whole
        // project, one per event filter (PRF-1). Every rule below is caught on
        // its own, so nothing leaves the loop before endUniqueAudit().
        $this->beginUniqueAudit($rules, $project_id, $event_id);
        foreach ($rules as $ruleIndex => $rule) {
            if (!empty($rule['configError'])) continue; // logged above (VAL-15)
            if ($surveyOpen && !isset($dependents[$ruleIndex]) && ModeRegistry::waitsForCompleteSurvey($rule['type'] ?? '')) continue;
            // Each rule is isolated: one rule blowing up must not silently
            // abort the audit of every later rule (COR-002).
            try {
                if (isset($dependents[$ruleIndex])) {
                    if ($hostContexts === null) continue;   // could not enumerate; already logged
                    // The dependant is evaluated where it LIVES, once per host
                    // row. Running it over every same-event context instead
                    // turned one base-form violation into one log per unrelated
                    // repeat row of an unrelated instrument, each attributed to
                    // a form the rule has nothing to do with, and added a
                    // spurious "unconfigurable" for the trigger form's base row
                    // whenever the host repeated (H-03).
                    $h = $this->ruleHostForms($rule, $project_id);
                    if ($h['unknown']) {
                        $this->logUnconfigurable($ruleIndex, $h['unknown'],
                            'the instrument that owns this rule\'s field(s) could not be determined, so the '
                            . 'change on "' . (string) $instrument . '" could not be re-checked against it',
                            $instrument, $event_id, $repeat_instance);
                    }
                    foreach ($h['forms'] as $hostForm => $ownList) {
                        // Scope to the dependant's OWN fields, never the whole
                        // project, and report the HOST's instrument/instance so
                        // the log points at the field that is actually wrong
                        // rather than at whichever form happened to be saved
                        // (M-03).
                        $ownFields = array_fill_keys($ownList, true);
                        foreach ($this->hostContextsFor($hostContexts, $hostForm, $project_id) as $hk => $hctx) {
                            if (!isset($depResolution[$hk])) {
                                $depResolution[$hk] = $this->contextResolution($hctx, array_keys($readSet), $project_id);
                            }
                            $this->auditRule($rule, $ruleIndex, $hctx['values'], $dupes, $ownFields, $logMode,
                                $project_id, $record, $hostForm, $hctx['event_id'], $hctx['instance'],
                                isset($whenAst[$ruleIndex]) ? $whenAst[$ruleIndex] : null,
                                $depResolution[$hk], ['callerGroup' => $group_id]);
                        }
                    }
                    continue;
                }
                $this->auditRule($rule, $ruleIndex, $values, $dupes, $onForm, $logMode, $project_id, $record, $instrument, $event_id, $repeat_instance,
                    isset($whenAst[$ruleIndex]) ? $whenAst[$ruleIndex] : null, $auditResolution, ['callerGroup' => $group_id]);
            } catch (\Throwable $e) {
                $this->logAuditError($logMode, $project_id, $record, $instrument, $e, 'rule ' . ($ruleIndex + 1));
            }
        }
        $this->endUniqueAudit();
    }

    /**
     * The contexts of one record the reverse-dependency pass evaluates a rule
     * in: every row of the record's event (the plain row and each repeat
     * instance), read once. Null when they could not be read (logged).
     */
    private function saveHostContexts($project_id, $record, array $fields, $event_id, $instrument, $logMode)
    {
        try {
            $whole = \REDCap::getData([
                'project_id' => $project_id, 'return_format' => 'array',
                'records' => [$record], 'fields' => $fields,
            ]);
            if (is_array($whole) && isset($whole[$record]) && is_array($whole[$record])) {
                $out = [];
                foreach (self::recordContexts($whole[$record]) as $hctx) {
                    // Same event only: a save cannot speak for another event.
                    if ($event_id && (string) $hctx['event_id'] !== (string) $event_id) continue;
                    $out[] = $hctx;
                }
                return $out;
            }
        } catch (\Throwable $e) {
            $this->logAuditError($logMode, $project_id, $record, $instrument, $e, 'dependent contexts');
        }
        return null;
    }

    /**
     * Validate one rule's fields against the values read for this save, and
     * log the findings. Thin wrapper: the verdicts come from ruleFindings(),
     * the ONE dispatch shared with the project scan page — the hook and the
     * scan can never disagree about what a violation is.
     */
    private function auditRule(array $rule, $ruleIndex, array $values, array $dupes, $onForm, $logMode, $project_id, $record, $instrument, $event_id, $repeat_instance, $whenAst = null, array $resolution = [], array $meta = [])
    {
        $meta['instance'] = $repeat_instance;
        // The save audit also works out the values a rule returns to fields
        // (the scan, which calls ruleFindings itself, does not): they are
        // queued and written once the audit is done (applyReturns), unless
        // this save writes nothing for the rule's mode (returnsWrittenOnSave).
        // Then they are not worked out at all: no lookup asks for them.
        $meta['returns'] = $this->returnsWrittenOnSave($rule, $project_id);
        $f = $this->ruleFindings($rule, $ruleIndex, $values, $dupes, $onForm, $project_id, $record, $event_id, $whenAst, $resolution, $meta);
        if (isset($f['returns']) && is_array($f['returns'])) {
            // The write goes in under the name of the person who saved, so it
            // goes only into forms that person may change (EXS-3). A save of
            // another form (a "match" field's, an input's) judges the rule
            // too, and its saver may only read the form the rule fills.
            $barred = $this->returnsBarred($project_id, $f['returns']);
            if ($barred) {
                $this->returnBarred[] = ['rule' => $ruleIndex, 'fields' => (isset($rule['fields']) && is_array($rule['fields'])) ? $rule['fields'] : [],
                                         'values' => $barred, 'event' => $event_id, 'instance' => $repeat_instance, 'instrument' => $instrument];
                $f['returns'] = array_diff_key($f['returns'], $barred);
            }
            $this->queueReturns($f['returns'], (isset($rule['fields']) && is_array($rule['fields'])) ? $rule['fields'] : [], $event_id, $repeat_instance,
                self::looseReturns($rule, $f['returns']));
        }
        foreach ($f['unconfigurable'] as $u) {
            $this->logUnconfigurable($ruleIndex, $u['fields'], $u['why'], $instrument, $event_id, $repeat_instance);
        }
        foreach ($f['invalid'] as $v) {
            $extra = [];
            if (isset($v['asOf'])) $extra['as_of'] = (string) $v['asOf'];
            // A growth finding: its reference, and its z-score only where the
            // log keeps the value itself. The z-score, the reference, the sex
            // and the age give the measurement back to within a rounding, so a
            // log that keeps no value (none) or only its keyed hash (hashed)
            // must not keep the z-score either.
            if (isset($v['z']) && $logMode === 'raw') $extra['z'] = (string) $v['z'];
            if (isset($v['reference'])) $extra['reference'] = (string) $v['reference'];
            // A @UVDELTA finding: the reference it compared with (rule text, never a value).
            if (isset($v['vs'])) $extra['vs'] = (string) $v['vs'];
            // What a mode's findings carry for the log (php/modes.json findingKeys).
            foreach (ModeRegistry::findingKeys() as $fk => $to) {
                if (isset($to['log'], $v[$fk]) && is_scalar($v[$fk])) $extra[$to['log']] = (string) $v[$fk];
            }
            // A broken rule saved with a reason (@UVJUSTIFY): logged apart from the
            // open ones, naming the reason field. The reason itself is never logged.
            $justified = !empty($v['justified']) && isset($v['justifiedBy']);
            if ($justified) $extra['justified_by'] = (string) $v['justifiedBy'];
            $this->logInvalid($logMode, $project_id, $record, $v['field'], $v['value'], $v['algo'], $v['type'], $instrument, $event_id, $repeat_instance, $v['reason'],
                $extra, $justified ? 'uvalidate-justified' : 'invalid-id-saved');
        }
        foreach ((isset($f['unevaluated']) ? $f['unevaluated'] : []) as $u) {
            $this->logNotEvaluated($logMode, $project_id, $record, $ruleIndex, $u, $instrument, $event_id, $repeat_instance);
        }
    }

    /**
     * One rule's verdicts against ONE set of values (one record/event/instance
     * context). Pure evaluation — no logging, no instrument scoping beyond the
     * caller's $onForm filter — so the redcap_save_record audit (which logs)
     * and the project scan page (which collects) share this single dispatch.
     *
     * $whenAst: pre-parsed condition AST(s) from the hook, or null — null makes
     * this method parse the rule's own "when" (and each branch's) itself, the
     * path the scan takes. $meta carries what the caller knows about the
     * record beyond its values: 'dag' (the record's Data Access Group, null for
     * none; absent = not known, read when a rule needs it). Returns:
     *   ['invalid'         => [ ['field','value','algo','type','reason'], ... ],
     *    'unconfigurable'  => [ ['fields' => [...], 'why' => string], ... ],
     *    'unevaluated'     => [ ['field', 'why', ...], ... ] (optional: values a
     *                         growth rule could not work out a z-score for),
     *    'returns'         => [field => text, ...]]   (only when $meta['returns'] asks, and
     *                          only for a rule that returns values: "" empties the field, a
     *                          field not named keeps its value; see ReturnedValues)
     */
    private function ruleFindings(array $rule, $ruleIndex, array $values, array $dupes, $onForm, $project_id, $record, $event_id, $whenAst = null, array $resolution = [], array $meta = [])
    {
        $out = ['invalid' => [], 'unconfigurable' => []];

        // Branched rule (several conditional rules share this field): pick the
        // branch whose condition is true for THIS context and evaluate under
        // its configuration. Semantics mirror the client and are specified in
        // php/Branching.php: one active -> validate; none -> the else branch
        // if present, otherwise inert; more than one -> a branch conflict is
        // a reportable configuration problem, never a silent pass and never a
        // guessed algorithm. A mode with branch groups (@UVRANGE: one group
        // per reference) makes that choice once per group and evaluates every
        // group's pick (Branching).
        if (isset($rule['branches']) && is_array($rule['branches'])) {
            if ($whenAst === null) {
                // Scan path: parse each branch condition here (false = no parse).
                $asts = [];
                foreach ($rule['branches'] as $bi => $b) {
                    if (!isset($b['when']) || !is_string($b['when']) || $b['when'] === '') continue;
                    $p = Logic::parse($b['when']);
                    $asts[$bi] = empty($p['ok']) ? false : $p['ast'];
                }
            } else {
                $asts = (is_array($whenAst) && isset($whenAst['branches'])) ? $whenAst['branches'] : [];
            }
            $groupMode = ModeRegistry::modeOfType(isset($rule['type']) ? $rule['type'] : '');
            $groupKey = ModeRegistry::branchGroup($groupMode);
            $active = [];   // group => [branch index, ...]
            $else = [];     // group => branch index
            $groups = [];   // group => true, in branch order
            // A group whose branch cannot be chosen (an unparseable or
            // unresolvable "when") => why. It sets aside that group only: the
            // other groups of the field are still judged, as on the page
            // (QRID_activeVariants). With one group that is the whole field.
            $broken = [];
            foreach ($rule['branches'] as $bi => $b) {
                $g = Branching::groupOf($b, $groupKey);
                $groups[$g] = true;
                if (!isset($b['when']) || !is_string($b['when']) || $b['when'] === '') {
                    $else[$g] = $bi;
                    continue;
                }
                if (isset($broken[$g])) continue;
                $ast = isset($asts[$bi]) ? $asts[$bi] : false;
                if (!is_array($ast)) {
                    $broken[$g] = ['cannot be evaluated', null];
                    continue;
                }
                // A SELECTOR we could not resolve makes the whole branch decision
                // undecidable, and the failure is asymmetric: an unresolved
                // selector merely leaves a plain rule inert, but here it silently
                // hands control to the FALLBACK branch, which then enforces —
                // flagging the field, blocking the save and logging a violation
                // of a rule the designer never meant to apply to this context.
                // Refuse the whole decision rather than pick a branch from a
                // value we never read (H-01).
                foreach (Logic::referencedFields($ast) as $ref) {
                    $state = isset($resolution[$ref[0]]) ? $resolution[$ref[0]] : 'ok';
                    if ($state !== 'ok') {
                        $broken[$g] = [$state, $ref[0]];
                        continue 2;
                    }
                }
                if (Logic::evaluate($ast, $values, Logic::BLANK_INERT, !empty($b['caseSensitive']), $this->conditionNumbers($project_id))) $active[$g][] = $bi;
            }
            if ($broken && count($groups) === 1) {
                // One group: the field as a whole is not checked.
                list($state, $refField) = reset($broken);
                $out['unconfigurable'][] = ['fields' => $rule['fields'], 'why' => $refField === null
                    ? 'a branch "when" condition cannot be evaluated — field skipped'
                    : 'a branch "when" condition ' . self::resolutionProblem($state, $refField)
                      . ' No branch can be chosen, so the field is not checked here.'];
                return $out;
            }
            // A branch conflict leaves the whole field unchecked, so it is
            // reported alone, before any group set aside says the rest of
            // the field is checked.
            foreach ($active as $g => $list) {
                if (isset($broken[$g])) continue;
                if (count($list) > 1) {
                    $out['unconfigurable'][] = ['fields' => $rule['fields'],
                        'why' => 'more than one "when" condition is true for this field (branch conflict) — field skipped: "'
                        . $rule['branches'][$list[0]]['when'] . '" | "' . $rule['branches'][$list[1]]['when'] . '"'];
                    return $out;
                }
            }
            $groupName = ModeRegistry::branchGroupName($groupMode) ?: 'group';
            // Every group set aside: no part of the field is checked, and each
            // message says so. Otherwise each names the part it leaves out.
            $none = count($broken) === count($groups);
            foreach ($broken as $g => $bk) {
                $which = $g === '' ? 'the limits without a ' . $groupName : 'the ' . $groupName . ' "' . $g . '"';
                $part = $g === '' ? 'those limits are' : 'that ' . $groupName . ' is';
                $out['unconfigurable'][] = ['fields' => $rule['fields'], 'why' => $bk[1] === null
                    ? 'a branch "when" condition for ' . $which . ' cannot be evaluated — '
                      . ($none ? 'field skipped' : $part . ' skipped')
                    : 'a branch "when" condition for ' . $which . ' ' . self::resolutionProblem($bk[0], $bk[1])
                      . ($none ? ' No branch can be chosen, so the field is not checked here.'
                               : ' No branch of it can be chosen, so ' . $part . ' not checked here; the rest of the field is.')];
            }
            // A rule with several groups names the group on each finding (its
            // "locus"), so the findings of two references on one field keep
            // identities of their own (Scan\Hmac::findingIdentity).
            $several = (isset($rule['_groups']) ? (int) $rule['_groups'] : count($groups)) > 1;
            // What the rule returns to fields (only when the audit asks): each
            // group owns the fields of all its branches. The picked branch
            // fills its own and the group's other fields are emptied; so are
            // a group's fields when no branch of it applies. A group set
            // aside, or whose pick could not be judged (no 'returns': an input
            // that could not be resolved), keeps what its fields hold.
            $returns = [];
            $held = array_keys($broken);
            $sub = $meta;
            unset($sub['returnOwner'], $sub['returnKeep']);
            foreach (array_keys($groups) as $g) {
                if (isset($broken[$g])) continue;
                if (isset($active[$g])) $pick = $active[$g][0];
                elseif (isset($else[$g])) $pick = $else[$g];
                else continue;   // no branch of this group applies to this context
                $branch = $rule['branches'][$pick];
                unset($branch['when']);
                $flat = array_merge([
                    'type'   => isset($rule['type']) ? $rule['type'] : 'single',
                    'fields' => $rule['fields'],
                ], $branch);
                $r = $this->ruleFindings($flat, $ruleIndex, $values, $dupes, $onForm, $project_id, $record, $event_id, null, $resolution, $sub);
                // Each finding names the branch that judged it, so a report can show
                // that branch's message and limits (ScanDimensions keeps one
                // snapshot per branch), not the first branch's or none.
                foreach ($r['invalid'] as $v) {
                    $v['branch'] = $pick;
                    if ($several && !isset($v['locus'])) $v['locus'] = (string) $g;
                    $out['invalid'][] = $v;
                }
                foreach ($r['unconfigurable'] as $u) $out['unconfigurable'][] = $u;
                foreach ((isset($r['unevaluated']) ? $r['unevaluated'] : []) as $u) $out['unevaluated'][] = $u + ['branch' => $pick];
                if (isset($r['returns']) && is_array($r['returns'])) $returns = array_merge($returns, $r['returns']);
                else $held[] = $g;
            }
            if (!empty($meta['returns'])) {
                $returns = array_merge(self::ownedReturns($rule, $meta, $held), $returns);
                if ($returns) $out['returns'] = $returns;
            }
            // judged by the branch it picked, justified by the field's reason (justifyBy is the rule's, never a branch's)
            return $this->markJustified($out, $rule, $values, $project_id);
        }

        $type    = isset($rule['type']) && $rule['type'] !== '' ? $rule['type'] : 'single';
        $mode    = ModeRegistry::modeOfType($type);

        // An algorithm outside the whitelist would make CheckCharacter::compute
        // throw inside validateId, which reads as "invalid ID" — a config
        // problem must never be reported as a data problem. Only modes that
        // carry an algorithm (php/modes.json "hasAlgorithm") take this gate.
        if (ModeRegistry::hasAlgorithm($mode)) {
            $algo = isset($rule['algorithm']) && $rule['algorithm'] !== '' ? $rule['algorithm'] : 'iso7064_mod37_36';
            if (!in_array($algo, AnnotationRules::ALGORITHMS, true)) {
                $out['unconfigurable'][] = ['fields' => $rule['fields'], 'why' => 'unknown algorithm "' . $algo . '"'];
                return $out;
            }
        }

        // Conditional rule: evaluate the "when" against this context's values
        // (missing/empty ref => ''). False => the rule is inert here, mirroring
        // the client gate. The hook pre-parses conditions ($whenAst array, or
        // false when a stored condition no longer parses — surfaced, never a
        // silent pass); the scan passes null and the condition is parsed here.
        if (isset($rule['when']) && $rule['when'] !== '') {
            if ($whenAst === null) {
                $p = Logic::parse($rule['when']);
                $whenAst = empty($p['ok']) ? false : $p['ast'];
            }
            if (!is_array($whenAst)) {
                $out['unconfigurable'][] = ['fields' => $rule['fields'], 'why' => 'the "when" condition cannot be evaluated — rule skipped'];
                return $out;
            }
            // The gate gets the SAME resolution guard as the assert below. A
            // "when" over a reference this context could not resolve (off-event,
            // a different repeating instrument, a failed read) would otherwise be
            // evaluated against a '' that was never read, silently turning the
            // rule off — or on — for the wrong reason. Surface it instead.
            foreach (Logic::referencedFields($whenAst) as $ref) {
                $state = isset($resolution[$ref[0]]) ? $resolution[$ref[0]] : 'ok';
                if ($state !== 'ok') {
                    $out['unconfigurable'][] = ['fields' => $rule['fields'],
                        'why' => 'the "when" condition ' . self::resolutionProblem($state, $ref[0])];
                    return $out;
                }
            }
            if (!Logic::evaluate($whenAst, $values, Logic::BLANK_INERT, !empty($rule['caseSensitive']), $this->conditionNumbers($project_id))) {
                // inert here: nothing to report, and the fields it returns to are emptied
                if (!empty($meta['returns']) && ($blank = self::ownedReturns($rule, $meta))) $out['returns'] = $blank;
                return $out;
            }
        }

        // A field marked with a Missing Data Code holds the code, not an
        // answer, and REDCap does not validate it either: a mode that judges
        // the value (php/modes.json "missingCodes":"skip") leaves it alone.
        // @UVREQUIRED ("answer") counts the code as an answer.
        $codes = $this->missingDataCodes($project_id);
        $meta['missingCodes'] = $codes;

        // What the rule returns to fields (php/modes.json "returns"), worked out
        // before the skip below: a measurement marked with a Missing Data Code
        // has no z-score, but the bounds of its range do not need one.
        $ret = null;
        if (!empty($meta['returns'])) {
            $rt = ModeRegistry::returns($mode);
            // The record and event go last: a lookup ("returnsExists") needs
            // them, a growth rule ("returnsRange") does not take them.
            if ($rt !== null) $ret = $this->{$rt['method']}($rule, $values, $dupes, $onForm, $project_id, $resolution, $meta, $record, $event_id);
        }

        if ($codes && ModeRegistry::missingCodes($mode) === 'skip') {
            $kept = [];
            foreach ($rule['fields'] as $f) {
                if (!self::isMissingCode(isset($values[$f]) ? $values[$f] : null, $codes)) $kept[] = $f;
            }
            if (!$kept) return $this->withReturned($out, $ret, $rule, $meta);
            $rule['fields'] = $kept;
        }

        // The mode's own verdict (php/modes.json "evaluator").
        $evaluator = ModeRegistry::evaluator($mode);
        return $this->withReturned($this->markJustified(
            $this->$evaluator($rule, $type, $values, $dupes, $onForm, $project_id, $record, $event_id, $resolution, $meta),
            $rule, $values, $project_id), $ret, $rule, $meta);
    }

    /**
     * Marks each finding in $out justified, with justifiedBy, whose field
     * has its reason given in the same record, event and instance ($values):
     * the reason field named in the rule's justifyBy (applyJustify) holds a
     * reason (Logic::reasonGiven). A reason field that was not read leaves
     * the finding open, and so do Missing Data Codes that could not be read:
     * a code is no reason, and without the list no value can be told apart
     * from one.
     *
     * The reason field's resolution needs no test of its own. readValues
     * returns a value only for a field it resolved. A scan or host context
     * judges only the fields of its own form ($onForm), and the reason field
     * sits on that same form (annotateJustifyDictionary; @UVJUSTIFY is an
     * annotation tag only), so resolveOne finds it wherever it finds the
     * field. Drop the same-form refusal and this needs the test again.
     */
    private function markJustified(array $out, array $rule, array $values, $project_id)
    {
        if (empty($out['invalid']) || !isset($rule['justifyBy']) || !is_array($rule['justifyBy'])) return $out;
        $codes = null;
        foreach ($out['invalid'] as $k => $v) {
            $f = isset($v['field']) ? (string) $v['field'] : '';
            $reason = isset($rule['justifyBy'][$f]) && is_string($rule['justifyBy'][$f]) ? $rule['justifyBy'][$f] : '';
            if ($reason === '' || !array_key_exists($reason, $values)) continue;
            if ($codes === null) {
                $codes = $this->missingDataCodes($project_id);
                // the codes come from the project object: either read failing leaves them unknown
                $unread = isset($this->configUnread[(int) $project_id]) ? $this->configUnread[(int) $project_id] : [];
                if (isset($unread['the missing data codes']) || isset($unread['the project\'s design'])) return $out;
            }
            if (!Logic::reasonGiven($values[$reason], $codes)) continue;
            $out['invalid'][$k]['justified'] = true;
            $out['invalid'][$k]['justifiedBy'] = $reason;
        }
        return $out;
    }

    /**
     * Unique mode (@UVUNIQUE): the race backstop. The browser prevents the
     * common case live via the AJAX check; two near-simultaneous submits
     * can both pass it, so the audit re-checks the SAVED value against
     * every other record. (The scan page does NOT take this path — it
     * detects duplicates in one aggregate pass over the scanned data
     * instead of one whole-project read per record.)
     */
    private function findingsUnique(array $rule, $type, array $values, array $dupes, $onForm, $project_id, $record, $event_id, array $resolution)
    {
        $out = ['invalid' => [], 'unconfigurable' => []];
        $with  = (isset($rule['uniqueWith']) && is_array($rule['uniqueWith'])) ? $rule['uniqueWith'] : [];
        $also  = (isset($rule['uniqueAlso']) && is_array($rule['uniqueAlso'])) ? $rule['uniqueAlso'] : [];
        $scope = isset($rule['uniqueScope']) ? $rule['uniqueScope'] : 'project';
        foreach ($rule['fields'] as $field) {
            if (isset($dupes[$field])) continue;
            if ($onForm !== null && !isset($onForm[$field])) continue;
            $value = isset($values[$field]) ? $values[$field] : null;
            if ($value === null || is_array($value) || Logic::lookupTrim($value) === '') continue;
            if (isset($rule['uniqueRecordResults'])) {
                if (!array_key_exists($field,$rule['uniqueRecordResults']) || $rule['uniqueRecordResults'][$field] === null) {
                    $out['unconfigurable'][]=['fields'=>[$field],'why'=>'Record uniqueness could not be resolved.'];
                } elseif ($rule['uniqueRecordResults'][$field] === false) {
                    $out['invalid'][]=['field'=>$field,'value'=>$value,'algo'=>'unique','type'=>'unique','reason'=>'duplicate-value'];
                }
                continue;
            }
            // A composite part this context could not read is not a blank
            // (UNQ-2): compared as one, every reuse at another site was a
            // duplicate. The scan refuses it in the same words.
            foreach ($with as $w) {
                $state = isset($resolution[$w]) ? $resolution[$w] : 'ok';
                if ($state !== 'ok') {
                    $out['unconfigurable'][] = ['fields' => [$field],
                        'why' => 'the unique rule\'s composite key ' . self::resolutionProblem($state, $w)];
                    continue 2;
                }
            }
            $cand = [$field => (string) $value];
            foreach ($with as $w) {
                $cand[$w] = (isset($values[$w]) && !is_array($values[$w])) ? (string) $values[$w] : '';
            }
            $col = $this->findCollision($project_id, $field, $with, $scope, $cand, $record, $event_id, null, false,
                                        empty($rule['caseSensitive']), null, $also);
            if ($col === false) {
                $out['unconfigurable'][] = ['fields' => [$field], 'why' => 'the saved values could not be read to compare with'];
            } elseif ($col !== null) {
                $out['invalid'][] = ['field' => $field, 'value' => $value, 'algo' => 'unique', 'type' => 'unique', 'reason' => 'duplicate-value'];
            }
        }
        return $out;
    }

    /**
     * Exists mode (@UVEXISTS): the saved value must already be saved somewhere
     * else — a record ID of this project, or a value of the "in" field in some
     * entry, narrowed by "event", "scope" and "match". A blank value checks
     * nothing, and so does a blank "match" field of this record (the entry it
     * would narrow to is not known yet). A lookup that cannot be completed is a
     * rule problem ("lookup-unavailable"), never a pass and never a finding.
     *
     * The audit asks findExisting() per value; a scan request builds one index
     * of the searched field per rule and answers every record from it
     * ($meta['existsIndex'], set by scanRecord).
     */
    private function findingsExists(array $rule, $type, array $values, array $dupes, $onForm, $project_id, $record, $event_id, array $resolution, array $meta = [])
    {
        $out = ['invalid' => [], 'unconfigurable' => []];
        // A branch the scan's plan found another project will not answer for
        // this person (scanPlan): reported for every value it would have checked.
        $refused = (isset($rule['existsScanRefused']) && is_string($rule['existsScanRefused'])) ? $rule['existsScanRefused'] : null;
        $locals = (isset($rule['existsLocal']) && is_array($rule['existsLocal'])) ? $rule['existsLocal'] : [];
        foreach ($rule['fields'] as $field) {
            if (isset($dupes[$field])) continue;
            if ($onForm !== null && !isset($onForm[$field])) continue;
            $value = isset($values[$field]) ? $values[$field] : null;
            if ($value === null || is_array($value) || Logic::lookupTrim($value) === '') continue;
            if ($refused !== null) {
                $out['unconfigurable'][] = ['fields' => [$field], 'why' => $refused];
                continue;
            }
            $lv = [];
            foreach ($locals as $lf) {
                $state = isset($resolution[$lf]) ? $resolution[$lf] : 'ok';
                if ($state !== 'ok') {
                    $out['unconfigurable'][] = ['fields' => [$field],
                        'why' => 'the "match" field ' . self::resolutionProblem($state, $lf) . ' — field not checked'];
                    continue 2;
                }
                $v = isset($values[$lf]) ? $values[$lf] : '';
                // nothing to narrow by yet (blank, or a Missing Data Code)
                if (is_array($v) || Logic::lookupTrim($v) === '' || self::isMissingCode($v, isset($meta['missingCodes']) ? $meta['missingCodes'] : [])) continue 2;
                $lv[$lf] = Logic::lookupTrim($v);
            }
            $r = $this->existsLookup($project_id, $this->existsAskedOnSave($project_id, $rule, $field, $meta),
                Logic::lookupTrim($value), $lv, $event_id, $record, $meta);
            if ($r['state'] === 'not-found') {
                // "filtered": the value is saved, but no entry holding it meets "where".
                $out['invalid'][] = ['field' => $field, 'value' => $value, 'algo' => 'exists', 'type' => 'exists',
                                     'reason' => !empty($r['filtered']) ? 'filtered' : 'not-found'];
            } elseif ($r['state'] !== 'found') {
                $out['unconfigurable'][] = ['fields' => [$field],
                    'why' => 'the lookup in ' . self::existsSourceName($rule) . ' could not be completed (lookup-unavailable'
                           . (isset($r['why']) && $r['why'] !== null ? ': ' . $r['why'] : '') . ')'
                           . ' — field not checked; check it again later'];
            }
        }
        return $out;
    }

    /**
     * What an exists rule with "return" copies into this record's fields in
     * one context, for the save (ReturnedValues): 'values' is field here =>
     * the value REDCap stores, "" emptying the field; null keeps what every
     * field holds, with the reason in 'problem' when it is one to log. Null
     * when the tagged field is not on the form audited. The browser twin is
     * QRIDExistsInit, fed by existsCheck.
     *
     * The rule owns its fields: a blank value (or a Missing Data Code) and a
     * value that is not found empty them. Values of another record go only to
     * a person who may read them, so nothing is copied when the save has no
     * signed-in user (a job, an API call REDCap runs without one; a survey
     * save never gets here, returnsWrittenOnSave), nor when the person who
     * saved may not open the form of every field the lookup reads
     * (existsReadFields; in another project, crossLookup asks the same). Nothing is
     * copied when the entries found disagree on the values, or are all in
     * other Data Access Groups than the saver's, or when the lookup could not
     * be made (findingsExists logs that one).
     */
    private function returnsExists(array $rule, array $values, array $dupes, $onForm, $project_id, array $resolution, array $meta, $record = null, $event_id = null)
    {
        $ret = (isset($rule['existsReturn']) && is_array($rule['existsReturn'])) ? $rule['existsReturn'] : [];
        if (!$ret) return ['values' => [], 'problem' => null, 'field' => null];
        $field = null;
        foreach ((isset($rule['fields']) && is_array($rule['fields'])) ? $rule['fields'] : [] as $f) {
            if (isset($dupes[$f])) continue;
            if ($onForm !== null && !isset($onForm[$f])) continue;
            $field = $f;
            break;
        }
        if ($field === null) return null;
        $keep = function ($why = null) use ($field) { return ['values' => null, 'problem' => $why, 'field' => $field]; };
        $targets = '"[' . implode(']", "[', array_values($ret)) . ']"';
        $codes = (isset($meta['missingCodes']) && is_array($meta['missingCodes'])) ? $meta['missingCodes'] : [];
        $raw = (isset($values[$field]) && !is_array($values[$field])) ? (string) $values[$field] : '';
        if (Logic::lookupTrim($raw) === '' || ($codes && self::isMissingCode($raw, $codes))) {
            return ['values' => array_fill_keys(array_values($ret), ''), 'problem' => null, 'field' => $field];
        }
        $user = $this->currentUsername();
        if ($user === null) return $keep();
        // The same fields the page asks rights for (existsCheck): the field
        // searched and the "match" fields tell which entry holds the value as
        // much as the fields copied do. For a rule that searches another
        // project these are the fields here it reads; crossLookup checks
        // the rest there.
        if ($this->firstUnreadableField($project_id, $user, self::existsReadFields($rule, $field)) !== null) {
            return $keep('the person who saved may not open every form this lookup reads, so ' . $targets
                . ' keep their values — the next save by someone who may fills them');
        }
        $lv = [];
        foreach ((isset($rule['existsLocal']) && is_array($rule['existsLocal'])) ? $rule['existsLocal'] : [] as $lf) {
            $state = isset($resolution[$lf]) ? $resolution[$lf] : 'ok';
            $v = isset($values[$lf]) ? $values[$lf] : '';
            // unresolved: findingsExists reports it; blank: nothing to narrow by yet
            if ($state !== 'ok' || is_array($v) || Logic::lookupTrim($v) === '' || ($codes && self::isMissingCode($v, $codes))) return $keep();
            $lv[$lf] = Logic::lookupTrim($v);
        }
        $r = $this->existsLookup($project_id, $this->existsAskedOnSave($project_id, $rule, $field, $meta),
            Logic::lookupTrim($raw), $lv, $event_id, $record, $meta);
        if ($r['state'] === 'not-found') return ['values' => array_fill_keys(array_values($ret), ''), 'problem' => null, 'field' => $field];
        if ($r['state'] !== 'found') return $keep();
        if (isset($r['returned']) && is_array($r['returned'])) {
            $out = [];
            foreach ($ret as $s => $t) $out[$t] = isset($r['returned'][$s]) ? (string) $r['returned'][$s] : '';
            return ['values' => $out, 'problem' => null, 'field' => $field];
        }
        if (isset($r['returnConflict'])) {
            return $keep((int) $r['returnConflict'] . ' saved entries hold this value with different values to copy, so ' . $targets
                . ' keep their values — narrow the lookup with "match"');
        }
        if (!empty($r['returnWithheld'])) {
            return $keep('the value is saved only outside the Data Access Group of the person who saved, so ' . $targets
                . ' keep their values');
        }
        return $keep();
    }

    /**
     * Whether an exists rule looks only inside the record's own DAG, or in
     * another project, where this project's groups mean nothing: either way a
     * scan confined to one group of this project can check it. A branched rule
     * qualifies only when every branch does (each branch carries its own keys).
     */
    private static function existsConfinedToDag(array $rule)
    {
        foreach (self::existsParts($rule) as $p) {
            if (!empty($p['existsPid'])) continue;
            if ((isset($p['existsScope']) ? $p['existsScope'] : 'project') !== 'dag') return false;
        }
        return true;
    }

    /** The rule itself, or each of its branches: the parts that carry lookup keys. */
    private static function existsParts(array $rule)
    {
        $parts = (isset($rule['branches']) && is_array($rule['branches']) && $rule['branches'])
            ? $rule['branches'] : [$rule];
        return array_values(array_filter($parts, 'is_array'));
    }

    /**
     * Why the person running a scan cannot have a rule's lookups in another
     * project answered, or null when they can (or the rule searches no other
     * project). The scan answers every record from one read of that project,
     * so a read confined to a group there is refused too: it would make values
     * of the other groups read as not found.
     *
     * $unread is set true when the refusal rests on a read that FAILED rather
     * than on what was read (review R3U-1): refused all the same - failing
     * closed - but said as what it is, and marked so that a stored run's
     * status tells the reader to wait rather than that the answer is final.
     */
    private function crossScanProblem($pid, array $rule, &$unread = null)
    {
        $unread = false;
        foreach (self::existsParts($rule) as $p) {
            if (empty($p['existsPid'])) continue;
            $b = (int) $p['existsPid'];
            $c = $this->crossGate($pid, $p);
            $why = null;
            if ($c === null) {
                $why = 'project ' . $b . ' does not answer this lookup from this project';
            } else {
                $why = $this->crossCaller($b, $p, $c, $this->currentUsername() === null, $confine, $callerDag);
                if ($why !== null) {
                    $why .= ' (project ' . $b . ')';
                } elseif ($confine !== null || $callerDag !== null) {
                    $why = 'your account is in a Data Access Group of project ' . $b . ', so the one read the scan '
                        . 'makes there could miss values saved in the other groups';
                }
            }
            if ($why === null) continue;
            if ($this->crossUnread($b)) {
                $unread = true;
                return 'whether project ' . $b . ' answers your lookups could not be read just now';
            }
            return $why;
        }
        return null;
    }

    /**
     * A lookup into another project as a stored run keeps it: the project and
     * the fields searched there ('record' for its record IDs), sorted. These
     * are all crossGate() and crossCaller() read of a rule part, so the
     * refusal can be asked again later without the rule (crossLookupRefusals()).
     */
    private static function crossLookupOf(array $part)
    {
        $fields = self::crossFields($part);
        sort($fields, SORT_STRING);
        return ['pid' => (int) $part['existsPid'], 'fields' => $fields];
    }

    /**
     * For a stored run: which of the lookups it made (crossLookupOf(), kept
     * from the plan that started it) would the other project refuse the
     * signed-in reader, asked as the scan asks (crossScanProblem()). One entry
     * per refused lookup: ['pid', 'why', 'unread'], `unread` when the refusal
     * rests on a read that failed (review R3U-1). [] when every lookup would
     * be answered. null when a lookup is not in the stored shape: a list that
     * cannot be read is not a list that names nothing.
     *
     * Asked of the lookups the run made, not of the rules as they stand
     * (review R4-2): a rule edited to search a form the reader may open does
     * not open the answers the run took from one they may not.
     */
    public function crossLookupRefusals($pid, array $lookups)
    {
        $out = [];
        foreach ($lookups as $l) {
            if (!is_array($l) || !isset($l['pid'], $l['fields']) || !is_int($l['pid']) || $l['pid'] <= 0
                    || !is_array($l['fields']) || !$l['fields']) {
                return null;
            }
            $remote = [];
            $record = false;
            foreach ($l['fields'] as $f) {
                if (!is_string($f) || $f === '') return null;
                if ($f === 'record') { $record = true; continue; }
                $remote[] = $f;
            }
            $part = ['existsPid' => $l['pid'], 'existsIn' => $record ? 'record' : '', 'existsRemoteTargets' => $remote];
            $why = $this->crossScanProblem((int) $pid, $part, $unread);
            if ($why !== null) $out[] = ['pid' => $l['pid'], 'why' => $why, 'unread' => (bool) $unread];
        }
        return $out;
    }

    /** How REDCap stores a field's value, in words, from its validation. */
    private static function storedKindOf($validation)
    {
        $v = strtolower(trim((string) $validation));
        if (strpos($v, 'datetime_seconds_') === 0) return 'dates with a time to the second';
        if (strpos($v, 'datetime_') === 0) return 'dates with a time to the minute';
        if (strpos($v, 'date_') === 0) return 'dates';
        if ($v === 'time_hh_mm_ss') return 'times to the second';
        if ($v === 'time_mm_ss') return 'minutes and seconds';
        if ($v === 'time') return 'times to the minute';
        return 'no date or time';
    }

    /**
     * How @UVEXISTS and @UVUNIQUE compare the values of one field
     * (Logic::lookupKey): 'point' or 'comma' (the decimal mark) when the field
     * holds numbers - a calc, a slider, or a Text field validated as an integer
     * or a number - so "007" equals "7"; null when it holds text.
     */
    private static function numberMarkOf($meta)
    {
        if (!is_array($meta)) return null;
        return Logic::numberMark(isset($meta['field_type']) ? $meta['field_type'] : '', self::validationOf($meta));
    }

    /**
     * Every field of project $pid that holds numbers, with its decimal mark:
     * the $numbers map a rule condition is evaluated with (Logic::evaluate),
     * so [hb]>=5 reads a saved 12,5 as 12.5 (AST-1). Cached per project for
     * the request; an unreadable dictionary gives an empty map, which reads
     * every value as typed, as before.
     */
    private function conditionNumbers($pid)
    {
        $key = (string) $pid;
        if (isset($this->conditionNumbersCache[$key])) return $this->conditionNumbersCache[$key];
        $dd = $this->dataDictionary($pid);
        if (!is_array($dd) || !$dd) return [];
        $out = [];
        foreach ($dd as $f => $meta) {
            $mark = self::numberMarkOf($meta);
            if ($mark !== null) $out[(string) $f] = $mark;
        }
        return $this->conditionNumbersCache[$key] = $out;
    }

    /**
     * The text mode a rule's TEST condition ("assert") is evaluated with:
     * Logic::EXACT for "exact": true, else its "caseSensitive" flag. A "when"
     * gate always uses the flag alone.
     */
    private static function testTextMode(array $rule)
    {
        if (!empty($rule['exact'])) return Logic::EXACT;
        return !empty($rule['caseSensitive']);
    }

    /** testTextMode as the index of foldRuleConditions' folded tests: 0, 1 or 2 (exact). */
    private static function testModeIndex(array $rule)
    {
        $mode = self::testTextMode($rule);
        return $mode === Logic::EXACT ? 2 : ($mode ? 1 : 0);
    }

    /** numberMarkOf for each of $fields of project $pid; null for a field the dictionary does not hold. */
    private function lookupMarks($pid, array $fields)
    {
        $dd = $this->dataDictionary($pid);
        $out = [];
        foreach ($fields as $f) $out[$f] = (is_array($dd) && isset($dd[$f])) ? self::numberMarkOf($dd[$f]) : null;
        return $out;
    }

    /** "holds numbers with a decimal point" and so on, for a refusal; null for text. */
    private static function numberMarkWords($mark)
    {
        if ($mark === 'point') return 'numbers with a decimal point';
        if ($mark === 'comma') return 'numbers with a decimal comma';
        return null;
    }

    /** "the record IDs" or "[field]", for messages. */
    private static function existsSourceName(array $rule)
    {
        $in = isset($rule['existsIn']) ? (string) $rule['existsIn'] : '';
        $name = $in === 'record' ? 'the record IDs' : '[' . $in . ']';
        return !empty($rule['existsPid']) ? $name . ' of project ' . (int) $rule['existsPid'] : $name;
    }

    /**
     * Scan requests answer @UVEXISTS from one index per rule (see findingsExists),
     * asked for through $meta['existsIndex'] so no other caller in the same
     * request ever reads an index built before its own save.
     * @var array lookup key (project included) => index, or false for one that could not be built
     */
    private $existsIndexes = [];
    /** @var array pid => the Data Access Groups this request's reads of the project showed (noteGroupsSeen) */
    private $groupVisibility = [];
    /** @var array pid => true once this request has read (or tried to read) every record ID of the project */
    private $groupVisibilityRead = [];

    /**
     * One @UVEXISTS lookup: ['state' => found|not-found|unknown, 'record' => ?, 'dag' => ?, 'why' => ?].
     * A rule that searches another project goes through crossLookup().
     * $meta['dag'] is the DAG of the record being checked when the caller knows
     * it (a scan does); otherwise it is read here, and only for "scope":"dag".
     * $meta['callerGroup'] is the group id of the user whose request this is
     * (the saving user, for the audit); see findExisting for why it matters.
     * $meta['existsIndex'] asks for the scan's one-read index.
     */
    private function existsLookup($pid, array $rule, $value, array $locals, $eventId, $record, array $meta = [])
    {
        // A save asks once per rule and value: the verdict (findingsExists) and
        // the values copied (returnsExists) share the answer, so another
        // project is read, counted and logged once. The scan answers from its
        // own index and would only fill the memo record by record. Both
        // callers pass the rule existsAskedOnSave gives, without "return"
        // when the save copies nothing.
        if (!empty($meta['existsIndex'])) return $this->existsLookupOnce($pid, $rule, $value, $locals, $eventId, $record, $meta);
        // serialize, not json_encode: a value that is not valid UTF-8 would
        // make json_encode fail, and every such lookup share one answer.
        $mk = serialize([(int) $pid, self::existsRuleKey($rule), (string) $value, $locals, (string) $eventId, (string) $record,
            array_key_exists('dag', $meta) ? $meta['dag'] : '~', isset($meta['callerGroup']) ? $meta['callerGroup'] : null]);
        if (!isset($this->existsLookupMemo[$mk])) {
            $this->existsLookupMemo[$mk] = $this->existsLookupOnce($pid, $rule, $value, $locals, $eventId, $record, $meta);
        }
        return $this->existsLookupMemo[$mk];
    }

    /** @var array existsLookup answers, per save (redcap_save_record clears it) */
    private $existsLookupMemo = [];

    /**
     * @var array serialize([getData parameters, user]) => ['params' => them, 'data' => the read]:
     * the full reads of rules that copy values ("return"), kept for the request
     * (existsFullRead)
     */
    private $existsReads = [];

    /**
     * The full read findExisting makes for a rule that copies values: every
     * entry, never narrowed, so one import of many records would read the
     * whole project once per record and rule. It is made once per request,
     * parameters and signed-in user (REDCap may confine a user's reads to
     * their group) instead, and each save of a record of that project
     * re-reads that record into it (refreshExistsReads), so a later lookup
     * sees what was saved since. A read that fails is not kept.
     */
    private function existsFullRead(array $params)
    {
        $k = serialize([$params, $this->currentUsername()]);
        if (!isset($this->existsReads[$k])) {
            $data = \REDCap::getData($params);
            if (!is_array($data)) return $data;
            $this->existsReads[$k] = ['params' => $params, 'data' => $data];
        }
        return $this->existsReads[$k]['data'];
    }

    /**
     * Record $record of project $pid was just saved: every read existsFullRead
     * keeps of that project takes the record's rows as they are now, in its
     * place (gone when it no longer has any). A read that cannot be refreshed
     * is dropped, so the next lookup reads afresh. The read is changed where
     * it is kept: a copy of it per save would cost a pass over the project.
     */
    private function refreshExistsReads($pid, $record)
    {
        if ($record === null || $record === '') return;
        // A record-ID lookup's read of every record's "where" values
        // (recordRowsOf) is read afresh: it is small beside a full read.
        foreach (array_keys($this->recordRows) as $k) {
            if (strpos($k, (int) $pid . "\0") === 0) unset($this->recordRows[$k]);
        }
        foreach (array_keys($this->existsReads) as $k) {
            $params = $this->existsReads[$k]['params'];
            if ((int) $params['project_id'] !== (int) $pid) continue;
            try {
                $fresh = \REDCap::getData(['records' => [(string) $record]] + $params);
            } catch (\Throwable $e) {
                $fresh = null;
            }
            if (!is_array($fresh)) {
                unset($this->existsReads[$k]);
                continue;
            }
            if (isset($fresh[$record])) $this->existsReads[$k]['data'][$record] = $fresh[$record];
            else unset($this->existsReads[$k]['data'][$record]);
        }
    }

    /** What makes two exists rules ask the same question. */
    private static function existsRuleKey(array $rule)
    {
        $k = [];
        foreach (['existsIn', 'existsPid', 'existsEvent', 'existsScope', 'existsMatch', 'existsReturn', 'caseSensitive'] as $f) {
            $k[$f] = isset($rule[$f]) ? $rule[$f] : null;
        }
        // Two branches whose "where" differ ask different questions; two that
        // write one condition two ways ask the same one.
        $k['existsWhere'] = isset($rule['existsWhere'])
            ? Logic::canonicalKey((string) $rule['existsWhere'], empty($rule['caseSensitive'])) : null;
        return $k;
    }

    private function existsLookupOnce($pid, array $rule, $value, array $locals, $eventId, $record, array $meta)
    {
        if (!empty($rule['existsPid'])) {
            return $this->crossLookup($pid, $rule, $value, $locals, !empty($meta['existsIndex']) ? 'scan' : 'audit');
        }
        $scope = isset($rule['existsScope']) ? $rule['existsScope'] : 'project';
        $dag = false;
        if ($scope === 'dag') {
            if (array_key_exists('dag', $meta) && $meta['dag'] !== false) {
                $dag = $meta['dag'];
            } else {
                $rd = $this->recordDagOf($pid, $record);
                if ($rd === false || !$rd['found']) return ['state' => 'unknown', 'record' => null, 'dag' => null];
                $dag = $rd['dag'];
            }
        }
        if (!empty($meta['existsIndex'])) return $this->existsIndexLookup($pid, $rule, $value, $locals, $eventId, $dag, $record);
        $opts = ['self' => $record];
        if (isset($meta['callerGroup']) && $meta['callerGroup'] !== null && $meta['callerGroup'] !== '') {
            $opts['callerDag'] = ScanPageView::dagNameOf($meta['callerGroup']);
            if ($opts['callerDag'] === null) {
                return ['state' => 'unknown', 'record' => null, 'dag' => null,
                        'why' => 'the Data Access Group of the user could not be read'];
            }
        }
        return $this->findExisting($pid, $rule, $value, $locals, $eventId, $dag, true, $opts);
    }

    /**
     * Whether $value is saved where the rule looks. $locals holds the values of
     * the "match" fields of the record being checked; $dag the DAG to stay in
     * for "scope":"dag" (null = no DAG, false = not known, which answers
     * unknown). Values compare by Logic::lookupKey (existsSpec): trimmed, letter
     * case ignored unless the rule is caseSensitive, numbers by value when the
     * searched field holds numbers; a date is compared in the Y-M-D form REDCap
     * stores. Of several matches the one returned is in the caller's Data
     * Access Group first, then spelled exactly as the value.
     *
     * A record ID is looked up by its exact name first. When that misses, a
     * value with letters in a rule that ignores case is compared with every
     * record ID of the project (one read per request, under the same budget
     * as the confirming full read). The record being edited ($opts['self'])
     * never finds itself.
     *
     * $narrow (the live endpoint and the audit): first ask REDCap for candidate
     * entries only (filterLogic). A hit there is final. A miss is not trusted:
     * the read is repeated without the filter, over the searched fields only,
     * and a failure of that read answers unknown. The narrowing can therefore
     * save work but never turn a saved value into "not found".
     *
     * $opts:
     *   callerDag    the Data Access Group of the user this lookup runs for, when
     *                they are in one. REDCap may confine that user's reads to
     *                their own group, and then a value saved only in another
     *                group reads as missing. A "not found" from a rule that looks
     *                across groups is therefore kept only when this request is
     *                shown to see records outside the caller's group; otherwise
     *                it is unknown (existsMiss, readShows).
     *   mayFullRead  a callable asked before the confirming full read, and
     *                before any read of another project's record IDs that
     *                existsMiss needs; false answers unknown (the
     *                unauthenticated read budget).
     *   foreign      the lookup searches another project (crossLookup): an
     *                empty read proves nothing there, so a "not found" needs
     *                a read that showed records (existsMiss).
     *   self         the record being checked, left out of a record-ID lookup.
     */
    private function findExisting($pid, array $rule, $value, array $locals, $eventId, $dag, $narrow = false, array $opts = [])
    {
        $unknown = ['state' => 'unknown', 'record' => null, 'dag' => null];
        $value = Logic::lookupTrim($value);
        $spec = $this->existsSpec($pid, $rule, $value, $locals, $eventId);
        if ($spec === null || $value === '') return $unknown;
        $scope = isset($rule['existsScope']) ? $rule['existsScope'] : 'project';
        if ($scope === 'dag' && $dag === false) return $unknown;
        $prefer = isset($opts['callerDag']) ? $opts['callerDag'] : null;
        $budgeted = isset($opts['mayFullRead']) && is_callable($opts['mayFullRead']);
        // The record being edited, when its own value of the searched field
        // came with the request (EXS-6): the saved one is not what it holds.
        if (isset($opts['skip']) && $opts['skip'] !== null && $opts['skip'] !== '' && $spec['in'] !== 'record') {
            $spec['skip'] = (string) $opts['skip'];
        }
        try {
            if ($spec['in'] === 'record') {
                $pk = $this->recordIdFieldOf($pid);
                if ($pk === '') return $unknown;
                $self = (isset($opts['self']) && $opts['self'] !== null && $opts['self'] !== '') ? (string) $opts['self'] : null;
                // With "where" the same read brings the values it tests, so a
                // record that exists costs no read more than one that does not:
                // a survey cannot tell "saved, but filtered" from "absent" by
                // how many reads its answer took.
                $read = $spec['where'] === null ? [$pk] : array_values(array_unique(array_merge([$pk], $spec['where']['fields'])));
                $data = \REDCap::getData(['project_id' => $pid, 'return_format' => 'array', 'records' => [$value],
                                          'fields' => $read, 'exportDataAccessGroups' => true]);
                if (!is_array($data)) return $unknown;
                $ids = [];
                foreach ($data as $rec => $node) {
                    if (is_array($node)) $ids[(string) $rec] = self::dagOfRecordNode($node);
                }
                $mark = $spec['recordMark'];
                $filtered = false;
                if ($spec['where'] === null) {
                    $hit = self::recordIdMatch($ids, $value, $spec['fold'], $scope, $dag, $self, $mark);
                } else {
                    $hit = self::recordWhereHit($spec, self::recordIdMatches($ids, $value, $spec['fold'], $scope, $dag, $self, $mark),
                                                $data, $filtered);
                }
                if ($hit !== null) return $this->recordFoundWithReturns($pid, $spec, $hit, $prefer);
                // The data table names every record whose ID matches, in any
                // spelling the rule accepts, and is not confined to the
                // caller's Data Access Group (EXS-1): only those records are
                // read, and a miss there is a miss. A survey (a read budget)
                // keeps the reads below.
                if (empty($opts['foreign']) && !$budgeted) {
                    $t = $this->existsRecordFromTable($pid, $spec, $value, $read, $ids, $scope, $dag, $self, $prefer, $opts, $filtered);
                    if ($t !== null) return $t;
                }
                // REDCap finds a record by its exact name only, so a value that
                // differs from a record ID in letter case, or a number written
                // another way ("007" for record 7), needs every record ID.
                if (($spec['fold'] && preg_match('/[A-Za-z]/', $value))
                        || ($mark !== null && Logic::numberKey($value, $mark) !== null)) {
                    // With "where", one read of every record's ID and "where"
                    // values, whatever it finds (recordRowsOf).
                    $rows = $spec['where'] === null ? null
                        : $this->recordRowsOf($pid, $spec['where']['fields'], $this->fullReadGate($pid, $opts));
                    $all = $spec['where'] === null ? $this->recordIdsOf($pid, $this->fullReadGate($pid, $opts))
                        : ($rows === null ? null : self::recordIdsIn($rows));
                    if ($all === null) return $unknown + ['why' => 'the record IDs could not be read just now'];
                    if ($spec['where'] === null) {
                        $hit = self::recordIdMatch($all, $value, $spec['fold'], $scope, $dag, $self, $mark);
                    } else {
                        // The records the exact read already judged are not judged again.
                        $more = array_diff_key(self::recordIdMatches($all, $value, $spec['fold'], $scope, $dag, $self, $mark), $ids);
                        $hit = self::recordWhereHit($spec, $more, $rows, $filtered);
                    }
                    if ($hit !== null) return $this->recordFoundWithReturns($pid, $spec, $hit, $prefer);
                }
                return self::existsFilteredMiss($this->existsMiss($pid, $scope, $opts, $dag), $filtered);
            }
            $fields = array_keys($spec['target']);
            foreach ($spec['where'] !== null ? $spec['where']['fields'] : [] as $f) $fields[] = $f;
            foreach ($spec['returns'] as $f) if ($f !== 'record') $fields[] = $f;
            $params = ['project_id' => $pid, 'return_format' => 'array', 'fields' => array_values(array_unique($fields)),
                       'exportDataAccessGroups' => true];
            if ($spec['event'] !== null) $params['events'] = [$spec['event']];
            // A rule that copies values ("return") must see every entry that
            // holds the value, to tell one answer from several: the narrowed
            // read can miss one (collisionFilterLogic), so it reads in full.
            // A caller under a read budget (a survey) with "where" reads in
            // full too, once, under that budget: the read confirming a
            // narrowed hit costs about as much and would escape the budget,
            // and how many reads an answer took would tell a value saved but
            // filtered from an absent one.
            //
            // A caller without a budget (staff, the save audit) reads the data
            // table first (existsFromTable): the records that hold the value,
            // then those records only, in place of a read of every record
            // (EXS-4). A survey keeps its narrowed read, whose hit costs no
            // budget, and reads the table in place of the full read below.
            $table = empty($opts['foreign']) && !($budgeted && $spec['where'] !== null);
            if ($table && !$budgeted) {
                $t = $this->existsFromTable($pid, $spec, $params, $scope, $dag, $prefer, $value, $opts);
                if ($t !== null) return $t;
            }
            if ($narrow && !$spec['returns'] && !($budgeted && $spec['where'] !== null)) {
                $fl = self::collisionFilterLogic(array_keys($spec['target']), $spec['target'], $spec['fold'], $spec['marks']);
                if ($fl !== null) {
                    try {
                        $n = \REDCap::getData($params + ['filterLogic' => $fl]);
                        if (is_array($n) && $spec['where'] !== null && $n) {
                            // A filtered read may leave out the rows "where" needs
                            // (the event row beside a matching repeat instance), so
                            // it only names the candidate records: they are read
                            // again whole, and that read decides.
                            $n = \REDCap::getData($params + ['records' => array_map('strval', array_keys($n))]);
                        }
                        $hit = is_array($n) ? self::existsMatchIn($n, $spec, $scope, $dag, $prefer) : null;
                        if ($hit !== null) return ['state' => 'found'] + $hit;
                    } catch (\Throwable $e) {
                        // a filter this build cannot run: the full read below decides
                    }
                }
            }
            if ($narrow && isset($opts['mayFullRead']) && is_callable($opts['mayFullRead']) && !call_user_func($opts['mayFullRead'])) {
                return $unknown + ['why' => 'too many lookups in the last minute'];
            }
            if ($table && $budgeted) {
                $t = $this->existsFromTable($pid, $spec, $params, $scope, $dag, $prefer, $value, $opts);
                if ($t !== null) return $t;
            }
            $data = $spec['returns'] ? $this->existsFullRead($params) : \REDCap::getData($params);
            if (!is_array($data)) return $unknown;
            $filtered = false;
            if ($spec['returns']) {
                $hits = self::existsHitsIn($data, $spec, $scope, $dag, $filtered);
                if ($hits) {
                    $best = self::bestExistsHit($hits, $prefer);
                    return ['state' => 'found', 'record' => $best['record'], 'dag' => $best['dag']]
                        + self::existsReturnOutcome($hits, $spec['returns'], $prefer);
                }
            } else {
                $hit = self::existsMatchIn($data, $spec, $scope, $dag, $prefer, $filtered);
                if ($hit !== null) return ['state' => 'found'] + $hit;
            }
            // The read just made is the evidence of what this request can see
            // there; it spares existsMiss a second read of that project.
            if (!empty($opts['foreign'])) $this->noteGroupsSeen($pid, $data, false);
            return self::existsFilteredMiss($this->existsMiss($pid, $scope, $opts, $dag), $filtered);
        } catch (\Throwable $e) {
            return $unknown;
        }
    }

    /**
     * A record-ID lookup that the exact read missed, answered from the data
     * table, or null when the table cannot be read (EXS-1, EXS-4). The
     * table's record-ID rows name every record whose ID the rule accepts
     * (existsCandidates; $self is left out); the ones the exact read did not
     * already judge ($ids) are read with $read and judged as recordIdsOf's
     * read would have judged them. A record the table names but getData does
     * not hand back is in another Data Access Group (or went in between): an
     * ID is all a rule without "where" or "return" tests, so it is found
     * there, without its name; with either, the answer is unknown. Under
     * "scope":"dag" such a record is outside the group the rule looks in.
     */
    private function existsRecordFromTable($pid, array $spec, $value, array $read, array $ids, $scope, $dag, $self, $prefer, array $opts, &$filtered)
    {
        $pk = $this->recordIdFieldOf($pid);
        if ($pk === '') return null;
        $cands = $this->existsCandidates($pid, $pk, $value, $spec['fold'], $spec['recordMark'], [], null, $self);
        if ($cands === null) return null;
        $notFound = ['state' => 'not-found', 'record' => null, 'dag' => null];
        $more = array_values(array_filter($cands, function ($r) use ($ids) { return !array_key_exists($r, $ids); }));
        if (!$more) return self::existsFilteredMiss($notFound, $filtered);
        $data = \REDCap::getData(['project_id' => $pid, 'return_format' => 'array', 'records' => $more,
                                  'fields' => $read, 'exportDataAccessGroups' => true]);
        if (!is_array($data)) return ['state' => 'unknown', 'record' => null, 'dag' => null];
        $got = self::recordIdsIn($data);
        $mark = $spec['recordMark'];
        $hit = $spec['where'] === null
            ? self::recordIdMatch($got, $value, $spec['fold'], $scope, $dag, $self, $mark)
            : self::recordWhereHit($spec, self::recordIdMatches($got, $value, $spec['fold'], $scope, $dag, $self, $mark), $data, $filtered);
        if ($hit !== null) return $this->recordFoundWithReturns($pid, $spec, $hit, $prefer);
        $unseen = array_values(array_filter($more, function ($r) use ($got) { return !array_key_exists($r, $got); }));
        if ($unseen && $scope !== 'dag') {
            if ($spec['where'] === null && !$spec['returns']) return ['state' => 'found', 'record' => $unseen[0], 'dag' => false];
            return ['state' => 'unknown', 'record' => null, 'dag' => null,
                    'why' => isset($opts['callerDag']) && $opts['callerDag'] !== null
                        ? 'a value saved in another Data Access Group may not be visible from yours'
                        : 'the saved values could not be read just now'];
        }
        return self::existsFilteredMiss($notFound, $filtered);
    }

    /**
     * A miss ($miss, from existsMiss) where the value IS saved, in entries
     * that all fail the rule's "where" ($filtered): "not found", marked
     * 'filtered' so staff can be told the value is saved but not where the
     * conditions hold. It enforces as "not found" does. A miss that could not
     * be trusted stays unknown: an entry that passes may be one this request
     * cannot see.
     */
    private static function existsFilteredMiss(array $miss, $filtered)
    {
        if ($filtered && $miss['state'] === 'not-found') $miss['filtered'] = true;
        return $miss;
    }

    /**
     * For a record-ID lookup with "where": the first of $cands (record ID =>
     * DAG, in the order to prefer) whose entry in $d (a getData read that
     * holds their "where" fields) meets the condition, as ['record','dag'];
     * null when none does (with $filtered set when any was judged). A
     * record's entries are its event rows ("event" alone when the rule names
     * one, which a longitudinal project needs: existsEntryShape), never a
     * repeat instance, since "where" tests forms that do not repeat; a record
     * with no row there has no entry, so it fails. The values come from the
     * read that found the IDs, so judging costs no read of its own.
     */
    private static function recordWhereHit(array $spec, array $cands, array $d, &$filtered)
    {
        if (!$cands) return null;
        foreach ($cands as $rec => $rdag) {
            $rec = (string) $rec;
            $filtered = true;
            $node = (isset($d[$rec]) && is_array($d[$rec])) ? $d[$rec] : [];
            foreach (self::recordContexts($node) as $ctx) {
                if ($ctx['repeatKey'] !== null) continue;
                if ($spec['event'] !== null && (string) $ctx['event_id'] !== (string) $spec['event']) continue;
                if (self::existsWherePasses($ctx, $spec)) return ['record' => $rec, 'dag' => $rdag];
            }
        }
        return null;
    }

    /**
     * "Not found", or unknown when the read may not have seen everything the
     * rule searches:
     *   - the caller is in a Data Access Group, the rule looks across groups,
     *     and no record outside the caller's group was visible to this request;
     *   - a lookup in another project ($opts['foreign']) whose reads showed no
     *     record at all (or, confined to the caller's group there, none of that
     *     group): REDCap may have applied this project's restrictions to the
     *     other project's read, and an empty read proves nothing.
     */
    private function existsMiss($pid, $scope, array $opts, $dag = null)
    {
        $notFound = ['state' => 'not-found', 'record' => null, 'dag' => null];
        $callerDag = isset($opts['callerDag']) ? $opts['callerDag'] : null;
        $foreign = !empty($opts['foreign']);
        // Another project's record IDs are read only within the caller's read
        // budget, and the read is named in that project's probe log line.
        $gate = $foreign ? $this->fullReadGate($pid, $opts) : null;
        if ($scope === 'dag') {
            if (!$foreign || $this->readShows($pid, function ($g) use ($dag) { return $g === $dag; }, $gate)) return $notFound;
            return ['state' => 'unknown', 'record' => null, 'dag' => null,
                    'why' => 'the other project showed no record of your Data Access Group there to this lookup'];
        }
        if ($callerDag === null && !$foreign) return $notFound;
        $seen = $this->readShows($pid, function ($g) use ($callerDag, $foreign) {
            return ($foreign && $callerDag === null) || $g !== $callerDag;
        }, $gate);
        if ($seen) return $notFound;
        return ['state' => 'unknown', 'record' => null, 'dag' => null,
                'why' => $callerDag === null ? 'the other project showed no records to this lookup'
                       : 'a value saved in another Data Access Group may not be visible from yours'];
    }

    /**
     * Whether a read made by this request shows a record whose Data Access
     * Group (null = none) passes $want. Reads already made count
     * (noteGroupsSeen); when none of them answers, the record-ID field of the
     * project is read once per request, but only when $gate (if given)
     * allows it. A failed or refused read answers false: there is then no
     * evidence of what the lookup saw.
     */
    private function readShows($pid, callable $want, ?callable $gate = null)
    {
        foreach (isset($this->groupVisibility[$pid]) ? $this->groupVisibility[$pid] : [] as $g) {
            if ($want($g)) return true;
        }
        if (!empty($this->groupVisibilityRead[$pid])) return false;   // every record ID is already in hand
        if ($this->recordIdsOf($pid, $gate) === null) return false;
        foreach ($this->groupVisibility[$pid] as $g) {
            if ($want($g)) return true;
        }
        return false;
    }

    /**
     * Every record ID of project $pid => its Data Access Group (null = none),
     * read once per request (a failed read is not repeated); null when $gate
     * refuses the read or it fails. The read also counts as evidence for
     * readShows.
     */
    private function recordIdsOf($pid, ?callable $gate = null)
    {
        if (array_key_exists((int) $pid, $this->recordIds)) return $this->recordIds[(int) $pid];
        if ($gate !== null && !call_user_func($gate)) return null;
        $this->recordIds[(int) $pid] = null;
        $this->groupVisibilityRead[$pid] = true;
        try {
            $pk = $this->recordIdFieldOf($pid);
            if ($pk === '') return null;
            $data = \REDCap::getData(['project_id' => $pid, 'return_format' => 'array', 'fields' => [$pk],
                                      'exportDataAccessGroups' => true]);
            if (!is_array($data)) return null;
            $this->noteGroupsSeen($pid, $data, true);
            $ids = [];
            foreach ($data as $rec => $node) {
                if (is_array($node)) $ids[(string) $rec] = self::dagOfRecordNode($node);
            }
            return $this->recordIds[(int) $pid] = $ids;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /** @var array pid => record ID => DAG, or null for a read that failed (recordIdsOf) */
    private $recordIds = [];

    /**
     * Every record of project $pid with its ID and the fields $fields (a
     * record-ID lookup's "where" fields), in one read under $gate, as
     * recordIdsOf reads the IDs alone: it notes the groups the read shows,
     * and is kept for the request per field list. Null when the gate refuses
     * or the read fails.
     */
    private function recordRowsOf($pid, array $fields, ?callable $gate = null)
    {
        $k = (int) $pid . "\0" . implode(',', $fields);
        if (array_key_exists($k, $this->recordRows)) return $this->recordRows[$k];
        if ($gate !== null && !call_user_func($gate)) return null;
        $this->recordRows[$k] = null;
        $this->groupVisibilityRead[$pid] = true;
        try {
            $pk = $this->recordIdFieldOf($pid);
            if ($pk === '') return null;
            $data = \REDCap::getData(['project_id' => $pid, 'return_format' => 'array',
                                      'fields' => array_values(array_unique(array_merge([$pk], $fields))),
                                      'exportDataAccessGroups' => true]);
            if (!is_array($data)) return null;
            $this->noteGroupsSeen($pid, $data, true);
            return $this->recordRows[$k] = $data;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /** @var array "pid\0fields" => a getData read, or null for one that failed (recordRowsOf) */
    private $recordRows = [];

    /** Record ID => DAG of the records in a getData read. */
    private static function recordIdsIn(array $data)
    {
        $ids = [];
        foreach ($data as $rec => $node) {
            if (is_array($node)) $ids[(string) $rec] = self::dagOfRecordNode($node);
        }
        return $ids;
    }

    /**
     * The gate for a read of a whole searched field or of every record ID
     * that a lookup makes beyond its first read: the caller's budget
     * ($opts['mayFullRead']), and for another project a note that the probe
     * log line names the extra read.
     */
    private function fullReadGate($pid, array $opts)
    {
        return function () use ($opts, $pid) {
            if (isset($opts['mayFullRead']) && is_callable($opts['mayFullRead']) && !call_user_func($opts['mayFullRead'])) return false;
            if (!empty($opts['foreign'])) $this->crossExtraRead[(int) $pid] = true;
            return true;
        };
    }

    /**
     * The entry of $ids (record ID => DAG) whose ID matches $value, as
     * ['record','dag'], or null. The exact spelling wins over a match that
     * differs in letter case ($fold), $self (the record being edited) never
     * matches, and under "scope":"dag" only records of $dag count. $mark is
     * the record-ID field's numberMarkOf: IDs that hold numbers compare by value.
     */
    private static function recordIdMatch(array $ids, $value, $fold, $scope, $dag, $self, $mark = null)
    {
        $want = Logic::lookupKey($value, $fold, $mark);
        $best = null;
        foreach ($ids as $rec => $rdag) {
            $rec = (string) $rec;
            if ($self !== null && $rec === $self) continue;
            if ($scope === 'dag' && $rdag !== $dag) continue;
            if ($rec === $value) return ['record' => $rec, 'dag' => $rdag];
            if ($best === null && ($fold || $mark !== null) && Logic::lookupKey($rec, $fold, $mark) === $want) {
                $best = ['record' => $rec, 'dag' => $rdag];
            }
        }
        return $best;
    }

    /**
     * Every entry of $ids (record ID => DAG) whose ID matches $value, as
     * record ID => DAG, the exact spelling first: what recordIdMatch chooses
     * from, for a lookup that must judge each match ("where").
     */
    private static function recordIdMatches(array $ids, $value, $fold, $scope, $dag, $self, $mark = null)
    {
        $want = Logic::lookupKey($value, $fold, $mark);
        $exact = [];
        $other = [];
        foreach ($ids as $rec => $rdag) {
            $rec = (string) $rec;
            if ($self !== null && $rec === $self) continue;
            if ($scope === 'dag' && $rdag !== $dag) continue;
            if ($rec === $value) $exact[$rec] = $rdag;
            elseif (($fold || $mark !== null) && Logic::lookupKey($rec, $fold, $mark) === $want) $other[$rec] = $rdag;
        }
        return $exact + $other;
    }

    /**
     * Remember the Data Access Groups (null = none) of the records one read of
     * project $pid returned. $all: the read was every record ID, so readShows
     * has nothing more to read.
     */
    private function noteGroupsSeen($pid, array $data, $all)
    {
        if (!isset($this->groupVisibility[$pid])) $this->groupVisibility[$pid] = [];
        foreach ($data as $node) {
            if (!is_array($node)) continue;
            $g = self::dagOfRecordNode($node);
            $this->groupVisibility[$pid][$g === null ? '' : 'g' . $g] = $g;
        }
        if ($all) $this->groupVisibilityRead[$pid] = true;
    }

    /**
     * What one lookup compares: 'in', the 'target' field => value map (the
     * searched field plus the "match" targets, trimmed), each target's
     * Logic::lookupKey ('keys'), how they were made ('fold': letter case is
     * ignored unless the rule is caseSensitive; 'marks': numberMarkOf of each
     * searched field of project $pid), the one event id to search ('event',
     * null = every event), and the fields whose values the rule copies
     * ('returns', "return": fields of project $pid, "record" for the record ID
     * found; [] when it copies nothing). Null when the event cannot be
     * resolved or a "match" value is blank.
     */
    private function existsSpec($pid, array $rule, $value, array $locals, $eventId)
    {
        $in = isset($rule['existsIn']) ? (string) $rule['existsIn'] : '';
        if ($in === '') return null;
        $target = [];
        if ($in !== 'record') $target[$in] = Logic::lookupTrim($value);
        foreach ((isset($rule['existsMatch']) && is_array($rule['existsMatch'])) ? $rule['existsMatch'] : [] as $t => $l) {
            $lv = isset($locals[$l]) ? Logic::lookupTrim($locals[$l]) : '';
            if ($lv === '') return null;
            $target[(string) $t] = $lv;
        }
        $fold = empty($rule['caseSensitive']);
        $marks = $this->lookupMarks($pid, array_keys($target));
        $keys = [];
        foreach ($target as $f => $tv) $keys[$f] = Logic::lookupKey($tv, $fold, $marks[$f]);
        $recordMark = null;
        if ($in === 'record') {
            $pk = $this->recordIdFieldOf($pid);
            if ($pk !== '') $recordMark = $this->lookupMarks($pid, [$pk])[$pk];
        }
        $event = null;
        if (!empty($rule['existsEvent'])) {
            $event = !empty($rule['existsPid']) ? $this->eventIdIn($pid, $rule['existsEvent'])
                                                : $this->eventIdOf($pid, $rule['existsEvent']);
            if ($event === null) return null;
        } elseif ((isset($rule['existsScope']) ? $rule['existsScope'] : 'project') === 'event') {
            if ($eventId === null || $eventId === '') return null;
            $event = $eventId;
        }
        // The fields "return" copies from the entry found ("record": its ID).
        $returns = [];
        foreach ((isset($rule['existsReturn']) && is_array($rule['existsReturn'])) ? $rule['existsReturn'] : [] as $s => $_) {
            $returns[] = (string) $s;
        }
        $where = null;
        if (isset($rule['existsWhere'])) {
            // Checked when the rule was saved; a stored rule that no longer
            // parses answers unknown, never "found".
            $p = Logic::parse((string) $rule['existsWhere']);
            if (empty($p['ok'])) return null;
            $wf = AnnotationRules::existsWhereFieldsOf((string) $rule['existsWhere']);
            $where = ['ast' => $p['ast'], 'fields' => $wf, 'marks' => $this->lookupMarks($pid, $wf)];
        }
        // A saved Missing Data Code is no value: "NA" saved there is not found
        // by "na" typed here, letter case ignored or not.
        return ['in' => $in, 'target' => $target, 'keys' => $keys, 'fold' => $fold, 'marks' => $marks,
                'recordMark' => $recordMark, 'codes' => $this->missingDataCodes($pid), 'event' => $event,
                'returns' => $returns, 'where' => $where];
    }

    /**
     * Whether one entry of an exported data set (a recordContexts context)
     * holds every target value (by existsSpec's keys): null when it does not,
     * else whether it holds each spelled exactly as looked up. A saved
     * Missing Data Code matches nothing. An entry that holds the values but
     * fails the rule's "where" condition is no match either, and sets
     * $filtered, so the caller can tell "saved, but not where the conditions
     * hold" from "not saved".
     */
    private static function existsRowMatch(array $ctx, array $spec, &$filtered = null)
    {
        $row = $ctx['values'];
        $exact = true;
        foreach ($spec['target'] as $f => $tv) {
            $rv = (isset($row[$f]) && !is_array($row[$f])) ? Logic::lookupTrim($row[$f]) : '';
            if ($spec['codes'] && self::isMissingCode($rv, $spec['codes'])) return null;
            if ($rv === $tv) continue;
            $exact = false;
            if (Logic::lookupKey($rv, $spec['fold'], $spec['marks'][$f]) !== $spec['keys'][$f]) return null;
        }
        if (!self::existsWherePasses($ctx, $spec)) {
            $filtered = true;
            return null;
        }
        return $exact;
    }

    /**
     * Whether one entry (a recordContexts context) meets the rule's "where"
     * condition; true for a rule without one. The condition reads the entry
     * as REDCap stores it, with the rule's letter case (existsSpec 'fold'),
     * and a blank side of < > <= >= gives no answer, so the comparison fails
     * (Logic::BLANK_INERT). Missing Data Codes are plain values, as in
     * "when". A field that holds numbers with a decimal comma is read by
     * value ("3,5" is 3.5). A repeat instance carries its event row's values
     * (recordContexts), except a checkbox: REDCap gives every row of an
     * export the checkbox's codes, all "0" where the form is not that row's,
     * which would hide the event row's ticks. A checkbox with no box ticked
     * in the instance therefore reads the event row's boxes.
     */
    private static function existsWherePasses(array $ctx, array $spec)
    {
        if (empty($spec['where'])) return true;
        $vals = $ctx['values'];
        $base = null;
        if (isset($ctx['repeatKey']) && $ctx['repeatKey'] !== null && isset($ctx['node'][$ctx['event_id']])
                && is_array($ctx['node'][$ctx['event_id']])) {
            $base = $ctx['node'][$ctx['event_id']];
        }
        $comma = [];
        foreach ($spec['where']['fields'] as $f) {
            if (isset($spec['where']['marks'][$f]) && $spec['where']['marks'][$f] === 'comma') $comma[] = $f;
            if ($base !== null && isset($vals[$f]) && is_array($vals[$f]) && isset($base[$f]) && is_array($base[$f])
                    && !in_array('1', array_map('strval', $vals[$f]), true)) {
                $vals[$f] = $base[$f];
            }
        }
        return AnnotationRules::existsWhereHolds($spec['where']['ast'], $vals, !$spec['fold'], $comma);
    }

    /**
     * An entry of an exported data set that holds every target value (by
     * existsSpec's keys), as ['record','dag'], or null. Of several, the first
     * in Data Access Group $prefer (the caller's, so staff can be shown it),
     * then the first spelled exactly as looked up. A saved Missing Data Code
     * matches nothing. An entry that fails "where" is skipped and sets
     * $filtered (existsRowMatch).
     */
    private static function existsMatchIn(array $data, array $spec, $scope, $dag, $prefer = null, &$filtered = null)
    {
        $best = null;
        $bestScore = -1;
        $top = ($prefer !== null ? 2 : 0) + 1;
        $skip = isset($spec['skip']) ? (string) $spec['skip'] : null;
        foreach ($data as $rec => $node) {
            if (!is_array($node) || ($skip !== null && (string) $rec === $skip)) continue;
            $rdag = self::dagOfRecordNode($node);
            if ($scope === 'dag' && $rdag !== $dag) continue;
            foreach (self::recordContexts($node) as $ctx) {
                if ($spec['event'] !== null && (string) $ctx['event_id'] !== (string) $spec['event']) continue;
                $exact = self::existsRowMatch($ctx, $spec, $filtered);
                if ($exact === null) continue;
                $score = ($prefer !== null && $rdag === $prefer ? 2 : 0) + ($exact ? 1 : 0);
                if ($score > $bestScore) {
                    $best = ['record' => (string) $rec, 'dag' => $rdag];
                    $bestScore = $score;
                    if ($score === $top) return $best;
                }
            }
        }
        return $best;
    }

    /**
     * Every entry of an exported data set that holds every target value, for a
     * rule that copies values ("return"): ['record','dag','exact','values'],
     * 'values' being field => the value saved in that entry's row ("record":
     * the record ID). A row of a repeating instance carries the values of its
     * event's row too (recordContexts), so a value copied from a form that
     * does not repeat is the same in each. A saved Missing Data Code is no
     * value to copy: it is copied as "". Only entries that meet "where" are
     * hits, so values are copied only from them; one that fails it sets
     * $filtered.
     */
    private static function existsHitsIn(array $data, array $spec, $scope, $dag, &$filtered = null)
    {
        $hits = [];
        $skip = isset($spec['skip']) ? (string) $spec['skip'] : null;
        foreach ($data as $rec => $node) {
            if (!is_array($node) || ($skip !== null && (string) $rec === $skip)) continue;
            $rdag = self::dagOfRecordNode($node);
            if ($scope === 'dag' && $rdag !== $dag) continue;
            foreach (self::recordContexts($node) as $ctx) {
                if ($spec['event'] !== null && (string) $ctx['event_id'] !== (string) $spec['event']) continue;
                $exact = self::existsRowMatch($ctx, $spec, $filtered);
                if ($exact === null) continue;
                $hits[] = ['record' => (string) $rec, 'dag' => $rdag, 'exact' => $exact,
                           'values' => self::copiedValues($ctx['values'], (string) $rec, $spec)];
            }
        }
        return $hits;
    }

    /** The values a rule copies out of one row of record $rec (existsHitsIn). */
    private static function copiedValues(array $row, $rec, array $spec)
    {
        $out = [];
        foreach ($spec['returns'] as $f) {
            if ($f === 'record') {
                $out[$f] = (string) $rec;
                continue;
            }
            $v = (isset($row[$f]) && !is_array($row[$f])) ? (string) $row[$f] : '';
            if ($spec['codes'] && self::isMissingCode($v, $spec['codes'])) $v = '';
            $out[$f] = $v;
        }
        return $out;
    }

    /**
     * The hit staff are shown, as existsMatchIn picks it: in Data Access Group
     * $prefer first, then spelled exactly as looked up, then the first.
     */
    private static function bestExistsHit(array $hits, $prefer)
    {
        $best = null;
        $bestScore = -1;
        foreach ($hits as $h) {
            $score = ($prefer !== null && $h['dag'] === $prefer ? 2 : 0) + ($h['exact'] ? 1 : 0);
            if ($score > $bestScore) {
                $best = $h;
                $bestScore = $score;
            }
        }
        return $best;
    }

    /**
     * What a rule that copies values ("return") copies, from the entries the
     * lookup found:
     *   ['returned' => field => value]  every visible entry holds the same values;
     *   ['returnConflict' => n]          the n visible entries do not all hold
     *                                    the same ones: nothing is copied;
     *   ['returnWithheld' => true]       the value is saved only outside group
     *                                    $visible: nothing is copied.
     * $visible is the Data Access Group of the person the lookup runs for
     * (null: no group, every entry is visible). A person in a group sees the
     * values of their own group's entries only, as REDCap shows them records.
     */
    private static function existsReturnOutcome(array $hits, array $returns, $visible)
    {
        $tuples = [];
        $seen = 0;
        foreach ($hits as $h) {
            if ($visible !== null && $h['dag'] !== $visible) continue;
            $seen++;
            $vals = [];
            foreach ($returns as $f) $vals[$f] = isset($h['values'][$f]) ? (string) $h['values'][$f] : '';
            // serialize, not json_encode: a value that is not valid UTF-8 (a
            // legacy import) makes json_encode fail, and every such tuple
            // would then share one key and read as agreeing.
            $tuples[serialize($vals)] = $vals;
        }
        if (!$tuples) return ['returnWithheld' => true];
        if (count($tuples) > 1) return ['returnConflict' => $seen];
        return ['returned' => reset($tuples)];
    }

    /**
     * A record-ID lookup that found $hit, with what the rule copies from that
     * record: the row of "event" in a longitudinal project, else its one row
     * (existsReturnProblem keeps the copied fields off repeating forms and
     * asks for "event" in a longitudinal project). A record without a row
     * there gives "" for each field. A read that fails answers unknown.
     */
    private function recordFoundWithReturns($pid, array $spec, array $hit, $prefer)
    {
        $out = ['state' => 'found'] + $hit;
        if (!$spec['returns']) return $out;
        $row = [];
        $fields = array_values(array_diff($spec['returns'], ['record']));
        if ($fields) {
            $p = ['project_id' => $pid, 'return_format' => 'array', 'records' => [$hit['record']], 'fields' => $fields];
            if ($spec['event'] !== null) $p['events'] = [$spec['event']];
            $d = \REDCap::getData($p);
            if (!is_array($d)) return ['state' => 'unknown', 'record' => null, 'dag' => null, 'why' => 'the values to copy could not be read just now'];
            $node = isset($d[$hit['record']]) && is_array($d[$hit['record']]) ? $d[$hit['record']] : [];
            foreach (self::recordContexts($node) as $ctx) {
                if ($ctx['repeatKey'] !== null) continue;
                if ($spec['event'] !== null && (string) $ctx['event_id'] !== (string) $spec['event']) continue;
                $row = $ctx['values'];
                break;
            }
        }
        $h = $hit + ['exact' => true, 'values' => self::copiedValues($row, $hit['record'], $spec)];
        return $out + self::existsReturnOutcome([$h], $spec['returns'], $prefer);
    }

    /**
     * The scan's answer: the searched fields of the whole project are read
     * ONCE per rule and request into a hash set, and each record is answered
     * from it. An index that cannot be read, or that would not fit in memory,
     * answers unknown for every record of the request.
     */
    private function existsIndexLookup($pid, array $rule, $value, array $locals, $eventId, $dag, $self = null)
    {
        $unknown = ['state' => 'unknown', 'record' => null, 'dag' => null];
        $spec = $this->existsSpec($pid, $rule, $value, $locals, $eventId);
        if ($spec === null) return $unknown;
        $scope = isset($rule['existsScope']) ? $rule['existsScope'] : 'project';
        if ($scope === 'dag' && $dag === false) return $unknown;
        $fields = array_keys($spec['target']);
        $where = $spec['where'];
        // Rules that search the same fields but test a different "where"
        // need their own index: whether an entry passes is kept in it.
        $key = json_encode([(int) $pid, $spec['in'], $fields, isset($rule['existsEvent']) ? $rule['existsEvent'] : null,
                            $spec['fold'], $spec['marks'], $spec['recordMark'],
                            $where !== null ? [Logic::canonicalKey((string) $rule['existsWhere'], $spec['fold']), $where['marks']] : null]);
        $read = array_values(array_unique(array_merge($fields, $where !== null ? $where['fields'] : [])));
        $foreign = !empty($rule['existsPid']);
        if (!array_key_exists($key, $this->existsIndexes)) {
            // Another project's index costs one read there per request: it is
            // counted against that project's budget and leaves one line in its
            // module log.
            $over = $foreign ? $this->crossRateLimited($pid, 'scan') : null;
            if ($over !== null) {
                $this->existsIndexes[$key] = false;
                if ($over === 'log') $this->logCrossIndexRead($pid, $read, 'throttled');
            } else {
                $this->existsIndexes[$key] = $this->buildExistsIndex($pid, $spec['in'], $fields,
                    !empty($rule['existsEvent']) ? $spec['event'] : null, $foreign, $spec['fold'], $spec['marks'],
                    $spec['recordMark'], $spec['codes'], $where);
                if ($foreign) $this->logCrossIndexRead($pid, $read, $this->existsIndexes[$key] === false ? 'failed' : 'read');
            }
        }
        $idx = $this->existsIndexes[$key];
        if ($idx === false) return $unknown;
        $k = self::existsIndexKey(array_values($spec['keys']));
        if ($spec['in'] === 'record') $k = Logic::lookupKey($value, $spec['fold'], $spec['recordMark']);
        $self = ($self !== null && $self !== '') ? (string) $self : null;
        $filtered = false;
        foreach (isset($idx[$k]) ? $idx[$k] : [] as $hit) {
            if ($spec['in'] === 'record' && $hit[0] === $self) continue;
            if ($scope === 'dag' && $hit[1] !== $dag) continue;
            if ($scope === 'event' && (string) $hit[2] !== (string) $spec['event']) continue;
            if (empty($hit[3])) {
                $filtered = true;
                continue;
            }
            return ['state' => 'found', 'record' => $hit[0], 'dag' => $hit[1]];
        }
        // The index read is the evidence (buildExistsIndex noted what it
        // showed); the scan never makes a second, uncounted read there.
        if ($foreign && !$this->readShows($pid, function ($g) { return true; }, function () { return false; })) {
            return $unknown + ['why' => 'the other project showed no records to this scan'];
        }
        return self::existsFilteredMiss(['state' => 'not-found', 'record' => null, 'dag' => null], $filtered);
    }

    /**
     * key => [[record, dag, event_id, passes], ...] for one searched field set,
     * or false. The key is existsIndexKey of the values' Logic::lookupKey
     * ($fold, $marks) in rule order; a record-ID index is keyed by the record
     * ID's lookupKey ($fold, $recordMark), so IDs that differ only in letter
     * case share a key. An entry holding a Missing Data Code ($codes) holds
     * nothing to find. passes: whether the entry meets the rule's "where"
     * ($where, existsSpec's; always true without one), so a lookup can tell
     * "saved, but not where the conditions hold" from "not saved" from this
     * one index.
     * One hit is kept per (DAG, event) under a key - all a lookup ever asks -
     * so a value repeated in many records costs one entry, not a list that
     * every later record has to be compared against. An entry that passes
     * takes the place of one that does not.
     * $foreign: the project is another one; what the read showed is noted for
     * readShows, the evidence that a "not found" there is real.
     *
     * The record IDs are read first (recordIdsOf), then the searched fields in
     * chunks of records. After each chunk the memory the index has grown by is
     * projected over the records still to read, and the build stops (false)
     * as soon as that projection passes the cap: well before the read that
     * would have run the request out of memory, not after it. A record-ID
     * index with "where" reads the "where" fields the same way, and judges
     * each record's event rows as recordWhereHit does. Each field "where"
     * reads makes the chunks smaller, so a chunk's read stays about the size
     * it is without one.
     */
    private function buildExistsIndex($pid, $in, array $fields, $event, $foreign = false, $fold = true, array $marks = [],
                                      $recordMark = null, array $codes = [], ?array $where = null)
    {
        // A rule that tests only the value searched (one field, or a record
        // ID, and no "where") is indexed from the data table: one statement
        // per field, not an export of every record in chunks, which each work
        // request of a durable scan paid again (EXS-5, PRF-5).
        if (!$foreign && $where === null && ($in === 'record' || count($fields) === 1)) {
            $t = $this->existsIndexFromTable($pid, $in, $in === 'record' ? null : (string) $fields[0], $event, $fold, $marks,
                $recordMark, $codes);
            if ($t !== null) return $t;
        }
        try {
            $ids = $this->recordIdsOf($pid);
            if ($ids === null) return false;
            $idx = [];
            if ($in === 'record' && $where === null) {
                foreach ($ids as $rec => $rdag) $idx[Logic::lookupKey((string) $rec, $fold, $recordMark)][] = [(string) $rec, $rdag, null, true];
                return $idx;
            }
            $judge = ['where' => $where, 'fold' => $fold];
            if ($in === 'record' && $this->recordIdFieldOf($pid) === '') return false;
            $read = $in === 'record' ? array_merge([$this->recordIdFieldOf($pid)], $where['fields'])
                                     : array_merge($fields, $where !== null ? $where['fields'] : []);
            $read = array_values(array_unique($read));
            $base = $in === 'record' ? 1 : max(1, count($fields));
            $chunkSize = max(1, (int) floor(self::$existsIndexChunk * $base / max($base, count($read))));
            $cap = $this->existsIndexCap !== null ? (int) $this->existsIndexCap : (int) (self::memoryLimitBytes() * 0.6);
            $params = ['project_id' => $pid, 'return_format' => 'array', 'fields' => $read, 'exportDataAccessGroups' => true];
            if ($event !== null) $params['events'] = [$event];
            $total = count($ids);
            $done = 0;
            $start = memory_get_usage();
            foreach (array_chunk(array_map('strval', array_keys($ids)), $chunkSize) as $chunk) {
                $data = \REDCap::getData($params + ['records' => $chunk]);
                if (!is_array($data)) return false;
                if ($foreign) $this->noteGroupsSeen($pid, $data, false);
                if ($in === 'record') {
                    // Every record ID is in the index; a record whose rows all
                    // fail (or that has no row in "event") is kept as failing.
                    foreach ($chunk as $rec) {
                        $node = (isset($data[$rec]) && is_array($data[$rec])) ? $data[$rec] : [];
                        $pass = false;
                        foreach (self::recordContexts($node) as $ctx) {
                            if ($ctx['repeatKey'] !== null) continue;
                            if ($event !== null && (string) $ctx['event_id'] !== (string) $event) continue;
                            if (self::existsWherePasses($ctx, $judge)) { $pass = true; break; }
                        }
                        $idx[Logic::lookupKey($rec, $fold, $recordMark)][] = [$rec, $ids[$rec], null, $pass];
                    }
                } else {
                    foreach ($data as $rec => $node) {
                        if (!is_array($node)) continue;
                        $rdag = self::dagOfRecordNode($node);
                        foreach (self::recordContexts($node) as $ctx) {
                            if ($event !== null && (string) $ctx['event_id'] !== (string) $event) continue;
                            $parts = [];
                            foreach ($fields as $f) {
                                $v = (isset($ctx['values'][$f]) && !is_array($ctx['values'][$f])) ? Logic::lookupTrim($ctx['values'][$f]) : '';
                                if ($v === '') continue 2;   // an entry with a blank searched field holds nothing to find
                                if ($codes && self::isMissingCode($v, $codes)) continue 2;
                                $parts[] = Logic::lookupKey($v, $fold, isset($marks[$f]) ? $marks[$f] : null);
                            }
                            $k = self::existsIndexKey($parts);
                            $slot = ($rdag === null ? '' : 'g' . $rdag) . "\x1e" . (string) $ctx['event_id'];
                            if (isset($idx[$k][$slot]) && $idx[$k][$slot][3]) continue;
                            $pass = self::existsWherePasses($ctx, $judge);
                            if (!isset($idx[$k][$slot]) || $pass) $idx[$k][$slot] = [(string) $rec, $rdag, (string) $ctx['event_id'], $pass];
                        }
                    }
                }
                unset($data);
                $done += count($chunk);
                if (self::indexOverrun(memory_get_usage(), $start, $done, $total, $cap)) return false;
            }
            return $idx;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * buildExistsIndex's index for a rule that tests only the value searched,
     * from the data table (Scan\FieldValues::eachRow): the rows of $field (of
     * $event, or every event), or the record-ID rows for a record-ID lookup
     * ($in "record"). Each record's Data Access Group comes from the table's
     * group rows (tableGroupsOf). The same entries the export gives: a blank
     * or a Missing Data Code holds nothing to find, and one hit is kept per
     * (group, event) under a key. Null when the table or a group name cannot
     * be read (the caller exports instead); false when the index would pass
     * the memory cap.
     */
    private function existsIndexFromTable($pid, $in, $field, $event, $fold, array $marks, $recordMark, array $codes)
    {
        $groups = $this->tableGroupsOf($pid);
        if ($groups === null) return null;
        $cap = $this->existsIndexCap !== null ? (int) $this->existsIndexCap : (int) (self::memoryLimitBytes() * 0.6);
        $idx = [];
        $n = 0;
        $over = false;
        $full = function () use (&$n, &$over, $cap) {
            if (!$over && $cap > 0 && (++$n & 2047) === 0 && memory_get_usage() >= $cap) $over = true;
            return $over;
        };
        if ($in === 'record') {
            $pk = $this->recordIdFieldOf($pid);
            if ($pk === '') return null;
            $seen = [];
            $ok = $this->fieldValues()->eachRow($pid, $pk, null,
                function ($rec) use (&$idx, &$seen, $groups, $fold, $recordMark, $full) {
                    if (isset($seen[$rec]) || $full()) return;
                    $seen[$rec] = true;
                    $idx[Logic::lookupKey($rec, $fold, $recordMark)][] = [$rec, isset($groups[$rec]) ? $groups[$rec] : null, null, true];
                });
        } else {
            $mark = isset($marks[$field]) ? $marks[$field] : null;
            $ok = $this->fieldValues()->eachRow($pid, $field, $event,
                function ($rec, $evt, $v) use (&$idx, $groups, $fold, $mark, $codes, $full) {
                    if ($full()) return;
                    $v = Logic::lookupTrim($v);
                    if ($v === '' || ($codes && self::isMissingCode($v, $codes))) return;
                    $k = self::existsIndexKey([Logic::lookupKey($v, $fold, $mark)]);
                    $rdag = isset($groups[$rec]) ? $groups[$rec] : null;
                    $slot = ($rdag === null ? '' : 'g' . $rdag) . "\x1e" . $evt;
                    if (!isset($idx[$k][$slot])) $idx[$k][$slot] = [$rec, $rdag, $evt, true];
                });
        }
        if (!$ok) return null;
        return $over ? false : $idx;
    }

    /**
     * Record => unique name of its Data Access Group, from the data table's
     * group rows (REDCap keeps a record's group as field "__GROUPID__"); a
     * record with no such row is in no group and is not listed. Null when the
     * table cannot be read or a group's name cannot.
     */
    private function tableGroupsOf($pid)
    {
        $ids = [];
        $ok = $this->fieldValues()->eachRow($pid, '__GROUPID__', null, function ($rec, $evt, $v) use (&$ids) {
            if ($v !== '') $ids[$rec] = $v;
        });
        if (!$ok) return null;
        $names = [];
        foreach (array_unique(array_values($ids)) as $gid) {
            $nm = ScanPageView::dagNameOf($gid);
            if ($nm === null) return null;
            $names[$gid] = $nm;
        }
        $out = [];
        foreach ($ids as $rec => $gid) $out[$rec] = $names[$gid];
        return $out;
    }

    /**
     * Whether an index that used $start bytes before its first chunk and $now
     * after $done of $total records will pass $cap (0 = no cap) by the time
     * every record is read, growing as it has so far.
     */
    private static function indexOverrun($now, $start, $done, $total, $cap)
    {
        if ($cap <= 0) return false;
        return $now + max(0, $now - $start) / max(1, $done) * max(0, $total - $done) >= $cap;
    }

    /** Records per read when the scan index reads a searched field (buildExistsIndex). */
    private static $existsIndexChunk = 2000;

    /** Bytes the scan index may bring the request to; null = 60% of memory_limit. Tests set it. */
    private $existsIndexCap = null;

    /**
     * One key for a list of lookup keys: each prefixed with its length in
     * bytes, so no value can run into the next, and a key costs its values'
     * bytes plus a few (hex doubled them, and the index is capped by memory).
     */
    private static function existsIndexKey(array $parts)
    {
        $k = '';
        foreach ($parts as $p) $k .= strlen((string) $p) . ':' . $p;
        return $k;
    }

    /** The event id of a unique event name in this project, or null. */
    private function eventIdOf($pid, $name)
    {
        try {
            if (!is_callable(['\REDCap', 'getEventNames'])) return null;
            $names = \REDCap::getEventNames(true);
            if (!is_array($names)) return null;
            foreach ($names as $id => $unique) {
                if ((string) $unique === (string) $name) return $id;
            }
        } catch (\Throwable $e) {
        }
        return null;
    }

    // -- @UVEXISTS in another project ----------------------------------------

    /** Default budgets of the cross-project lookups (system settings override them). */
    const CROSS_USER_PER_MINUTE = 30;
    const CROSS_PROJECT_PER_MINUTE = 1200;

    /**
     * The one message for every reason another project cannot be searched:
     * not a project, the module not enabled there, no consent for this
     * project, a field it did not list. One text for all of them, so a
     * designer here cannot use the error to learn anything about that project.
     */
    const CROSS_UNAVAILABLE = 'project %d cannot be searched from this project. It must have this module enabled and '
        . 'list this project, with every field this lookup searches or filters on, under "Projects that may look up '
        . 'values here" in its module settings, and an "event" must be one of its unique event names.';

    /**
     * The one refusal of a "where" whose reason would describe another
     * project's fields: their types, choice codes, forms or events
     * (existsWhereProblem). That project's team can say which applies.
     */
    const CROSS_FILTER_UNAVAILABLE = 'project %d cannot apply this "where" condition to the entry the lookup finds. '
        . 'Each field it tests must be a field there with one value (a checkbox is tested as [field(code)] with one '
        . 'of its codes), compared with a value of its kind (a date written as Y-M-D, a number with a decimal point), '
        . 'and be on the form searched or on a form that does not repeat, in every event the value can be found in; '
        . 'with "in":"record" every field must be on a form that does not repeat and, in a longitudinal project, '
        . '"event" must name an event that holds those forms and does not repeat.';

    /**
     * The one refusal of a "return" whose reason would describe how another
     * project is built (existsReturnProblem). That project's team can say which
     * of these applies.
     */
    const CROSS_RETURN_UNAVAILABLE = 'project %d cannot give the values "return" asks for in the entry the lookup finds. '
        . 'Each field copied must be on the form searched or on a form that does not repeat, and on a form of every event '
        . 'the value can be found in; with "in":"record" in a longitudinal project, "event" must name an event that does '
        . 'not repeat.';

    /** @var array "a|b" => consent row or null, per request */
    private $crossConsents = [];
    /** @var array pid => \Project or null, per request */
    private $projectObjects = [];
    /** @var array pid => userRightsIn() answer, per request */
    private $rightsIn = [];

    /**
     * @var array other project id => true for every read about that project
     * that FAILED this request, and 0 => true when the server switch could not
     * be read (review R3U-1). See crossUnread().
     */
    private $crossReadFailed = [];

    /**
     * Whether a refusal of project $b may rest on a read that failed rather
     * than on what was read (review R3U-1).
     *
     * Every read on the cross-project path fails closed - rights that cannot
     * be read are no rights, a module state that cannot be read is "not
     * enabled", an agreement that cannot be read is no agreement - and that
     * stays: the lookup is refused either way. What changes is that a refusal
     * can now say it was a moment and not a decision. ScanService marked every
     * refusal of a stored run final, so one database blip in the rights read
     * of the other project ended a watching tab's watch for good, while the
     * next ask would have shown the run.
     *
     * Any failed read about $b this request counts, read or memoized, so a
     * refusal is called a decision only when nothing about $b failed; a
     * decision taken beside an unrelated failure is merely asked again.
     */
    private function crossUnread($b)
    {
        return isset($this->crossReadFailed[0]) || isset($this->crossReadFailed[(int) $b]);
    }

    /** Whether an administrator allowed lookups in other projects on this server. */
    private function crossProjectOn()
    {
        try {
            return in_array($this->getSystemSetting('exists-system-cross-project'), [true, 1, '1', 'true'], true);
        } catch (\Throwable $e) {
            $this->crossReadFailed[0] = true;
            return false;
        }
    }

    /** A whole-number system setting above zero, or $default when blank or not one. */
    private function systemCount($key, $default)
    {
        try {
            $v = $this->getSystemSetting($key);
        } catch (\Throwable $e) {
            return $default;
        }
        $v = is_int($v) ? (string) $v : trim((string) $v);
        return preg_match('/^[1-9][0-9]{0,6}$/', $v) ? (int) $v : $default;
    }

    /**
     * The project id an @UVEXISTS "project" names: the id itself, or the
     * project this project's "exists-project-aliases" maps the alias to. Null
     * for an alias that is not set up here.
     */
    private function existsProjectPid($pid, $ref)
    {
        $ref = strtolower(trim((string) $ref));
        if (preg_match('/^[1-9][0-9]{0,9}$/', $ref)) return (int) $ref;
        try {
            $rows = $this->getSubSettings('exists-project-aliases', $pid);
        } catch (\Throwable $e) {
            $this->noteConfigUnread($pid, 'this project\'s lookup aliases');
            return null;
        }
        foreach (is_array($rows) ? $rows : [] as $row) {
            if (!is_array($row)) continue;
            $alias = isset($row['exists-alias']) ? strtolower(trim((string) $row['exists-alias'])) : '';
            $target = isset($row['exists-alias-project']) ? trim((string) $row['exists-alias-project']) : '';
            if ($alias === $ref && preg_match('/^[1-9][0-9]{0,9}$/', $target)) return (int) $target;
        }
        return null;
    }

    /** Whether this module is enabled in project $pid. Fails closed. */
    private function moduleEnabledIn($pid)
    {
        try {
            if (!is_callable([$this, 'isModuleEnabled'])) return false;
            $prefix = isset($this->PREFIX) ? $this->PREFIX : null;
            if (!is_string($prefix) || $prefix === '') return false;
            if (!$this->isModuleEnabled($prefix, (int) $pid)) return false;
            if (is_callable([$this, 'getProjectStatus']) && $this->getProjectStatus((int) $pid) === null) return false;
            return $this->projectNotDeleted($pid);
        } catch (\Throwable $e) {
            $this->crossReadFailed[(int) $pid] = true;
            return false;
        }
    }

    /**
     * Whether project $pid exists and is not deleted. A deleted project keeps
     * its row, its settings and its data until REDCap purges it, and the
     * framework still reports a status for it, so only date_deleted tells.
     * Fails closed: no row, or a read that fails, is a deleted project.
     */
    private function projectNotDeleted($pid)
    {
        try {
            $rows = (new Scan\ModuleDb($this))->select(
                'SELECT date_deleted FROM redcap_projects WHERE project_id = ?', [(int) $pid]);
            return is_array($rows) && count($rows) === 1 && is_array($rows[0])
                && array_key_exists(0, $rows[0]) && $rows[0][0] === null;
        } catch (\Throwable $e) {
            $this->crossReadFailed[(int) $pid] = true;
            return false;
        }
    }

    /**
     * What project $b agreed to answer for project $a: ['mode' => rights|answer,
     * 'targets' => field => true ("record" = the record ID), 'surveys' => bool],
     * or null. Null covers every reason alike - the server switch off, $b the
     * same project or not one, the module not enabled there, no row naming $a,
     * two rows naming it - and callers answer them all the same way. Read once
     * per request.
     */
    private function crossConsent($a, $b)
    {
        $a = (int) $a;
        $b = (int) $b;
        $k = $a . '|' . $b;
        if (array_key_exists($k, $this->crossConsents)) return $this->crossConsents[$k];
        $this->crossConsents[$k] = null;
        if ($a <= 0 || $b <= 0 || $a === $b || !$this->crossProjectOn() || !$this->moduleEnabledIn($b)) return null;
        try {
            $rows = $this->getSubSettings('exists-consumers', $b);
        } catch (\Throwable $e) {
            $this->crossReadFailed[$b] = true;
            return null;
        }
        $found = null;
        foreach (is_array($rows) ? $rows : [] as $row) {
            $c = self::consumerRow($row);
            if ($c === null || $c['project'] !== $a) continue;
            if ($found !== null) return null;   // two rows for one project: neither is the agreement
            $found = $c;
        }
        if ($found === null) return null;
        unset($found['project']);
        return $this->crossConsents[$k] = $found;
    }

    /**
     * One "exists-consumers" row read strictly, or null when it is incomplete:
     * a project id, at least one field, a known mode (blank = rights). Survey
     * answers count only with the "answer" mode. 'returns' are the fields
     * whose values the asking project may copy (@UVEXISTS "return"): none
     * under the "answer" mode, and none when the list holds a name that is not
     * a field name (read strictly, as the searched fields are).
     */
    private static function consumerRow($row)
    {
        if (!is_array($row)) return null;
        $p = isset($row['exists-consumer-project']) ? trim((string) $row['exists-consumer-project']) : '';
        if (!preg_match('/^[1-9][0-9]{0,9}$/', $p)) return null;
        $targets = self::consumerTargets(isset($row['exists-consumer-targets']) ? $row['exists-consumer-targets'] : '');
        if (!$targets) return null;
        $mode = isset($row['exists-consumer-mode']) ? trim((string) $row['exists-consumer-mode']) : '';
        if ($mode === '') $mode = 'rights';
        if ($mode !== 'rights' && $mode !== 'answer') return null;
        $surveys = in_array(isset($row['exists-consumer-surveys']) ? $row['exists-consumer-surveys'] : false, [true, 1, '1', 'true'], true);
        $returns = $mode === 'rights'
            ? self::consumerTargets(isset($row['exists-consumer-returns']) ? $row['exists-consumer-returns'] : '') : [];
        return ['project' => (int) $p, 'mode' => $mode, 'targets' => $targets, 'surveys' => $surveys && $mode === 'answer',
                'returns' => $returns];
    }

    /** "a, b record" as [a => true, b => true, record => true]; [] when any name is not a field name. */
    private static function consumerTargets($text)
    {
        $out = [];
        foreach (preg_split('/[\s,]+/', strtolower(trim((string) $text)), -1, PREG_SPLIT_NO_EMPTY) as $t) {
            if (!preg_match('/^[a-z][a-z0-9_]*$/', $t)) return [];
            $out[$t] = true;
        }
        return $out;
    }

    /** The fields of the other project a rule searches; "record" for a record-ID lookup. */
    private static function crossFields(array $rule)
    {
        $out = (isset($rule['existsRemoteTargets']) && is_array($rule['existsRemoteTargets'])) ? $rule['existsRemoteTargets'] : [];
        if ((isset($rule['existsIn']) ? $rule['existsIn'] : null) === 'record') $out[] = 'record';
        return array_values(array_unique(array_map('strval', $out)));
    }

    /**
     * The other project's agreement when it covers every field the rule
     * searches and every field it copies ("return", listed apart: a project
     * may let others search a field it does not let them copy), or null (one
     * answer for every reason).
     */
    private function crossGate($pid, array $rule)
    {
        $c = $this->crossConsent($pid, isset($rule['existsPid']) ? (int) $rule['existsPid'] : 0);
        if ($c === null) return null;
        foreach (self::crossFields($rule) as $f) {
            if (!isset($c['targets'][$f])) return null;
        }
        foreach (self::returnSources($rule) as $f) {
            if (!isset($c['returns'][$f])) return null;
        }
        return $c;
    }

    /**
     * The fields of the project searched whose values an exists rule copies
     * ("return"; "record" for the record ID found), on the rule and on each
     * branch. [] for a rule without "return".
     */
    private static function returnSources(array $rule)
    {
        $out = [];
        $nodes = [$rule];
        if (isset($rule['branches']) && is_array($rule['branches'])) {
            foreach ($rule['branches'] as $b) if (is_array($b)) $nodes[] = $b;
        }
        foreach ($nodes as $n) {
            if (!isset($n['existsReturn']) || !is_array($n['existsReturn'])) continue;
            foreach (array_keys($n['existsReturn']) as $s) $out[(string) $s] = true;
        }
        return array_keys($out);
    }

    /** An exists rule (or branch) as one that copies nothing: the same lookup, without "return". */
    private static function withoutReturn(array $rule)
    {
        unset($rule['existsReturn'], $rule['existsReturnFields'], $rule['existsReturnNumbers']);
        return $rule;
    }

    /**
     * The fields of this project an exists rule on $field reads to answer one
     * lookup here: the field itself, its "match" fields, the fields it
     * searches and the fields it copies (not the record ID). The person a
     * lookup runs for must be able to open the form of each, on the page
     * (existsCheck) and when a save copies values (returnsExists). A rule that
     * searches another project reads only $field and its "match" fields here;
     * the rest is checked there (crossCaller).
     */
    private static function existsReadFields(array $rule, $field)
    {
        $locals = (isset($rule['existsLocal']) && is_array($rule['existsLocal'])) ? $rule['existsLocal'] : [];
        if (!empty($rule['existsPid'])) return array_values(array_unique(array_merge([(string) $field], $locals)));
        $targets = (isset($rule['existsTargets']) && is_array($rule['existsTargets'])) ? $rule['existsTargets'] : [];
        $copied = array_values(array_diff(self::returnSources($rule), ['record']));
        return array_values(array_unique(array_merge([(string) $field], $locals, $targets, $copied)));
    }

    /**
     * The rule a save's lookup for $field asks with: the rule itself when the
     * save copies its values, else the rule without "return" (withoutReturn),
     * so no copied field is read and none is logged as handed over in another
     * project (logCrossProbe). A save copies nothing the rule copies when it
     * does not write the mode's values (returnsWrittenOnSave: $meta['returns']),
     * when nobody is signed in, or when the person who saved may not open
     * every form the lookup reads here (existsReadFields). findingsExists and
     * returnsExists both ask through it, so they share one answer (existsLookup).
     */
    private function existsAskedOnSave($pid, array $rule, $field, array $meta)
    {
        if (empty($meta['returns'])) return self::withoutReturn($rule);
        $user = $this->currentUsername();
        if ($user === null || $this->firstUnreadableField($pid, $user, self::existsReadFields($rule, $field)) !== null) {
            return self::withoutReturn($rule);
        }
        return $rule;
    }

    /**
     * The signed-in user's rights in project $pid from the framework's
     * project-scoped read ONLY: ['forms' => form => level, or true for every
     * form (an administrator), 'group' => group id or null], or null when
     * there is no user, no rights row, or the row has expired.
     *
     * Never \REDCap::getUserRights(): it answers for the project of the
     * request whatever project is meant, so a fallback to it would grant this
     * project's rights in the other one.
     */
    private function userRightsIn($pid)
    {
        $pid = (int) $pid;
        if (array_key_exists($pid, $this->rightsIn)) return $this->rightsIn[$pid];
        $out = null;
        try {
            $u = is_callable([$this, 'getUser']) ? $this->getUser() : null;
            if ($u && ScanPageView::isAdministrator($u)) {
                $out = ['forms' => true, 'group' => null];
            } elseif ($u && is_callable([$u, 'getRights'])) {
                $r = $u->getRights($pid);
                if (is_array($r) && isset($r[$pid]) && is_array($r[$pid])) $r = $r[$pid];
                if (is_array($r) && isset($r['forms']) && is_array($r['forms'])) {
                    $exp = isset($r['expiration']) ? trim((string) $r['expiration']) : '';
                    // REDCap ends access ON the expiration date.
                    if ($exp === '' || $exp > date('Y-m-d')) {
                        $g = (isset($r['group_id']) && $r['group_id'] !== null && $r['group_id'] !== '') ? (int) $r['group_id'] : null;
                        $out = ['forms' => $r['forms'], 'group' => $g];
                    }
                }
            }
        } catch (\Throwable $e) {
            // Still no rights - fail closed - but known to be a read that
            // failed, not a row that says so (crossUnread()).
            $out = null;
            $this->crossReadFailed[$pid] = true;
        }
        return $this->rightsIn[$pid] = $out;
    }

    /** REDCap's \Project for $pid, once per request, or null. */
    private function projectObject($pid)
    {
        $pid = (int) $pid;
        if (!array_key_exists($pid, $this->projectObjects)) {
            $this->projectObjects[$pid] = null;
            try {
                if ($pid > 0 && class_exists('\Project')) $this->projectObjects[$pid] = new \Project($pid);
            } catch (\Throwable $e) {
                $this->noteConfigUnread($pid, 'the project\'s design');
            }
        }
        return $this->projectObjects[$pid];
    }

    /**
     * @var array pid => [what => true]: reads this request that a fact of the
     * project's scan configuration rests on, and that FAILED (review R5-4).
     */
    private $configUnread = [];

    /**
     * Record that a fact of $pid's configuration was built from a read that
     * failed.
     *
     * Every one of these reads degrades instead of throwing: a design that
     * could not be read is "unreadable", Missing Data Codes that could not be
     * read are none, another project's settings that could not be read refuse
     * the lookup as a configuration error. That is right for a form save. For
     * a scan it is not, because those facts are in the fingerprint a work
     * request compares, and one blip on an unchanged project read as a change
     * and ended an hours-long run failed. durableScanContext() hands the list
     * back as `unread`, and ScanService refuses the request as one to retry.
     */
    private function noteConfigUnread($pid, $what)
    {
        $this->configUnread[(int) $pid][(string) $what] = true;
    }

    /** @var array pid => that project's Missing Data Codes, code => true */
    private $missingCodesMemo = [];

    /**
     * The project's Missing Data Codes (Project Setup > Additional
     * customizations) as code => true, read once per request; [] when the
     * project has none or they cannot be read. A field marked with REDCap's
     * "M" button holds the code itself as its value, and REDCap skips its own
     * validation for it, so the checks of php/modes.json "missingCodes":"skip"
     * modes treat such a value as not entered.
     */
    private function missingDataCodes($pid)
    {
        $pid = (int) $pid;
        if (isset($this->missingCodesMemo[$pid])) return $this->missingCodesMemo[$pid];
        $codes = [];
        try {
            $p = $this->projectObject($pid);
            if ($p && isset($p->project) && is_array($p->project) && isset($p->project['missing_data_codes'])) {
                $codes = self::parseMissingDataCodes($p->project['missing_data_codes']);
            }
        } catch (\Throwable $e) {
            $codes = [];
            $this->noteConfigUnread($pid, 'the missing data codes');
        }
        return $this->missingCodesMemo[$pid] = $codes;
    }

    /**
     * REDCap's "CODE, Label" lines as ['CODE' => true, ...]. Lines are split
     * on a newline or the two characters backslash-n, the way REDCap writes
     * choice lists.
     */
    public static function parseMissingDataCodes($raw)
    {
        $out = [];
        if (!is_string($raw) || $raw === '') return $out;
        foreach (preg_split('/\r\n|\r|\n|\\\\n/', $raw) as $line) {
            $parts = explode(',', $line, 2);
            $code = trim($parts[0]);
            if ($code !== '') $out[$code] = true;
        }
        return $out;
    }

    /** Whether $value, trimmed, is one of $codes (from missingDataCodes). */
    private static function isMissingCode($value, array $codes)
    {
        return $codes && is_scalar($value) && isset($codes[trim((string) $value)]);
    }

    /** The event id of a unique event name in ANOTHER project, or null. */
    private function eventIdIn($pid, $name)
    {
        try {
            $p = $this->projectObject($pid);
            if ($p && is_callable([$p, 'getUniqueEventNames'])) {
                $names = $p->getUniqueEventNames();
                foreach (is_array($names) ? $names : [] as $id => $unique) {
                    if ((string) $unique === (string) $name) return $id;
                }
            }
        } catch (\Throwable $e) {
        }
        return null;
    }

    /** The unique name of a Data Access Group of ANOTHER project, or null. */
    private function groupNameIn($pid, $groupId)
    {
        try {
            $p = $this->projectObject($pid);
            if ($p && is_callable([$p, 'getUniqueGroupNames'])) {
                $names = $p->getUniqueGroupNames();
                if (is_array($names) && isset($names[$groupId]) && (string) $names[$groupId] !== '') return (string) $names[$groupId];
                // A list that was read and lacks the group is what was read.
                if (is_array($names)) return null;
            }
        } catch (\Throwable $e) {
        }
        // No list at all: a read that failed (crossUnread()).
        $this->crossReadFailed[(int) $pid] = true;
        return null;
    }

    /**
     * The first field of the other project, among those the rule searches,
     * that is an Identifier there (the record-ID field standing for "record"),
     * or null. Flags that cannot be read count as one (fail closed).
     */
    private function crossIdentifier($b, array $rule)
    {
        $k = (int) $b . '|' . implode(',', self::crossFields($rule));
        if (!array_key_exists($k, $this->crossIds)) $this->crossIds[$k] = $this->crossIdentifierRead($b, $rule);
        return $this->crossIds[$k];
    }

    /** @var array crossIdentifier() answers, per request */
    private $crossIds = [];

    private function crossIdentifierRead($b, array $rule)
    {
        $ids = $this->projectIdentifierFields($b);
        if ($ids === null) $this->crossReadFailed[(int) $b] = true;
        $touch = [];
        foreach (self::crossFields($rule) as $f) {
            if ($f === 'record') {
                $pk = $this->recordIdFieldOf($b);
                if ($pk === '') return 'record';
                $f = $pk;
            }
            $touch[] = $f;
        }
        if ($ids === null) return $touch ? $touch[0] : 'record';
        return self::firstIdentifier($ids, $touch);
    }

    /**
     * The first field the rule searches or copies ("return") whose form, in
     * the other project, the user may not open; null when they may open them
     * all. A record ID needs no form. Fields the dictionary cannot place count
     * as closed.
     */
    private function crossUnreadable($b, array $rule, $forms)
    {
        $dd = $this->dataDictionary($b);
        if (!is_array($dd) || !$dd) $this->crossReadFailed[(int) $b] = true;
        foreach (array_unique(array_merge(self::crossFields($rule), self::returnSources($rule))) as $f) {
            if ($f === 'record') continue;
            $form = (is_array($dd) && isset($dd[$f]['form_name'])) ? (string) $dd[$f]['form_name'] : '';
            if ($form === '' || !self::mayReadForm($forms, $form)) return $f;
        }
        return null;
    }

    /**
     * Whether a lookup into project $b is over its budget: null when it may go
     * ahead, else 'log' (refused; the first refusal of its window, worth one
     * "throttled" line in that project's log) or 'quiet' (refused; already
     * said). Without the quiet answer a flood of refused lookups would become
     * a flood of log lines in the other project.
     *
     * Three counters per searched project, one per kind of caller, so no kind
     * can spend another's budget (tiers of the rate buckets, RATE_TIERS):
     *   staff   tier 3, exists-system-cross-project-per-minute, plus a window
     *           per signed-in session (exists-system-cross-user-per-minute);
     *   survey  tier 4, THROTTLE_CROSS_SURVEY. Anyone can be a survey caller
     *           and a session is shed by dropping a cookie, so the count that
     *           matters is the shared one, and it is smaller;
     *   audit and scan  tier 5, the same setting as staff. A save or a scan
     *           never spends what live data entry needs, and anonymous traffic
     *           cannot switch the post-save check off.
     * FAILS CLOSED, unlike the survey throttle: a read of another project that
     * cannot be counted is not made, and "could not check" never blocks a save.
     */
    private function crossRateLimited($b, $channel)
    {
        $b = (int) $b;
        $tier = $channel === 'survey' ? 4 : ($channel === 'staff' ? 3 : 5);
        try {
            $now = time();
            if (($channel === 'staff' || $channel === 'survey')
                    && function_exists('session_status') && session_status() === PHP_SESSION_ACTIVE) {
                $key = 'uvalidate_cross_hits_' . $b;
                $hits = (isset($_SESSION[$key]) && is_array($_SESSION[$key])) ? $_SESSION[$key] : [];
                $hits = array_values(array_filter($hits, function ($t) use ($now) {
                    return is_int($t) && ($now - $t) < 60;
                }));
                if (count($hits) >= $this->systemCount('exists-system-cross-user-per-minute', self::CROSS_USER_PER_MINUTE)) {
                    $_SESSION[$key] = $hits;
                    // One line per session and minute: the refusal that opens the run.
                    $said = isset($_SESSION[$key . '_said']) && is_int($_SESSION[$key . '_said']) && ($now - $_SESSION[$key . '_said']) < 60;
                    if (!$said) $_SESSION[$key . '_said'] = $now;
                    return $said ? 'quiet' : 'log';
                }
                $hits[] = $now;
                $_SESSION[$key] = $hits;
            }
            $db = new Scan\ModuleDb($this);
            $bucket = ((int) floor($now / 60)) * self::RATE_TIERS + $tier;
            $db->exec('INSERT INTO ' . Scan\Schema::table('rate_bucket') . '
                (project_id, bucket, hits) VALUES (?, ?, LAST_INSERT_ID(1))
                ON DUPLICATE KEY UPDATE hits = LAST_INSERT_ID(hits + 1)',
                [$b, $bucket]);
            $r = $db->select('SELECT LAST_INSERT_ID()', []);
            if (!isset($r[0][0]) || $r[0][0] === null) throw new \RuntimeException('the counter did not report a value');
            $hits = (int) $r[0][0];
            if ($hits === 1) {
                $db->exec('DELETE FROM ' . Scan\Schema::table('rate_bucket')
                    . ' WHERE project_id = ? AND bucket < ?', [$b, $bucket - 2 * self::RATE_TIERS]);
            }
            $limit = $channel === 'survey' ? self::THROTTLE_CROSS_SURVEY
                   : $this->systemCount('exists-system-cross-project-per-minute', self::CROSS_PROJECT_PER_MINUTE);
            if ($hits <= $limit) return null;
            return $hits === $limit + 1 ? 'log' : 'quiet';
        } catch (\Throwable $e) {
            // A counter that cannot be kept: refused, and said once per request.
            $k = $b . '|' . $tier;
            if (isset($this->crossBudgetFailSaid[$k])) return 'quiet';
            $this->crossBudgetFailSaid[$k] = true;
            return 'log';
        }
    }

    /** @var array "pid|tier" => true once a failed counter has been logged in this request */
    private $crossBudgetFailSaid = [];

    /**
     * One line in the searched project's module log for a lookup into it from
     * another project, answered or refused once that project's agreement
     * could be read (with the server switch off, or the module not enabled
     * there, nothing is written there): the asking project, the channel
     * (staff, survey, audit), the user ("survey" for a respondent, "(no user)"
     * for a save nobody was signed in for, such as an import or a scheduled
     * job), the field searched, the value as a keyed hash under the searched
     * project's key (left out when that project logs no values: "none" or
     * "off"), and the result. When the lookup also read that project's record
     * IDs to make sure a "not found" was real, "extra_read" says so. A
     * cross-project value is never logged raw.
     *
     * A rule that copies values ("return") adds the fields it copies
     * ("returned"), and, when values were handed over, the record of that
     * project they came from ($copiedFrom): as a keyed hash when that project
     * logs no values ("none"), not at all when its log is "off". The values
     * themselves are never logged.
     *
     * "field" lists every field the lookup reads there, the "where" fields
     * included; a "not-found" whose value is saved in entries that all fail
     * "where" ($filtered) adds "filtered".
     */
    private function logCrossProbe($b, $a, $channel, array $rule, $value, $result, $copiedFrom = null, $filtered = false)
    {
        $extra = !empty($this->crossExtraRead[(int) $b]);
        unset($this->crossExtraRead[(int) $b]);
        try {
            $mode = $this->logMode($b);
            $user = $this->currentUsername();
            $entry = [
                'project_id'     => (int) $b,
                'source_project' => (string) (int) $a,
                'channel'        => (string) $channel,
                'user'           => $user !== null ? $user : ($channel === 'survey' ? 'survey' : '(no user)'),
                'field'          => implode(',', self::crossFields($rule)),
                'result'         => (string) $result,
            ];
            if ($extra) $entry['extra_read'] = 'record ids';
            if ($filtered) $entry['filtered'] = 'true';
            $copies = self::returnSources($rule);
            if ($copies) $entry['returned'] = implode(',', $copies);
            if ($copiedFrom !== null && $copiedFrom !== '' && $mode !== 'off') {
                if ($mode === 'none') {
                    $h = $this->hashedIdentifier($b, (string) $copiedFrom);
                    if ($h !== null) $entry['copied_from_hmac'] = $h;
                } else {
                    $entry['copied_from'] = (string) $copiedFrom;
                }
            }
            // A lookup that ignores letter case logs the hash of the value in
            // lower case, so "SP-1" and "sp-1" leave the same hash there.
            $fold = empty($rule['caseSensitive']);
            if ($fold) $entry['case'] = 'ignored';
            $probed = $value !== null ? Logic::lookupTrim($value) : '';
            if ($mode !== 'none' && $mode !== 'off' && $probed !== '') {
                $h = $this->hashedIdentifier($b, $fold ? Logic::foldAscii($probed) : $probed);
                if ($h !== null) $entry['value_hash'] = $h;
            }
            $this->log('uv-exists-probe', $entry);
        } catch (\Throwable $e) {
        }
    }

    /** @var array pid => true while a lookup there has made a read of its record IDs not yet logged */
    private $crossExtraRead = [];

    /** One line in the searched project's module log for a scan's one read of it. */
    private function logCrossIndexRead($b, array $fields, $result)
    {
        try {
            $user = $this->currentUsername();
            $this->log('uv-exists-index-read', [
                'project_id'     => (int) $b,
                'source_project' => (string) (int) $this->crossSource,
                'user'           => $user !== null ? $user : '',
                'field'          => implode(',', $fields),
                'result'         => (string) $result,
            ]);
        } catch (\Throwable $e) {
        }
    }

    /** @var int the project whose scan is reading another one (logCrossIndexRead) */
    private $crossSource = 0;

    /**
     * Who may ask the other project, under its agreement $c, for this caller:
     * null when they may, else the reason. $asSurvey: a survey respondent, or
     * a save with no signed-in user.
     *   rights  the user has an unexpired rights row there (or is an
     *           administrator) and may open the form of every field searched.
     *   answer  any signed-in user of this project; the searched fields must not
     *           be Identifiers there.
     *   surveys only under "answer", when the other project also allows survey
     *           answers.
     * On success $confine holds the user's Data Access Group there under
     * "rights" (the lookup stays inside it), and $callerDag their group under
     * "answer" (a "not found" is then kept only when the read shows records of
     * other groups).
     */
    private function crossCaller($b, array $rule, array $c, $asSurvey, &$confine, &$callerDag)
    {
        $confine = null;
        $callerDag = null;
        if ($asSurvey) {
            // consumerRow() grants survey answers only with the answer mode.
            if (empty($c['surveys'])) return 'the other project does not answer survey respondents of this project';
            return $this->crossIdentifier($b, $rule) !== null ? 'a field searched is an Identifier in the other project' : null;
        }
        if ($this->currentUsername() === null) return 'no signed-in user';
        $rights = $this->userRightsIn($b);
        if ($c['mode'] === 'rights') {
            if ($rights === null) return 'you do not have rights in the project this lookup searches';
            if ($this->crossUnreadable($b, $rule, $rights['forms']) !== null) {
                return 'you do not have access to every form this lookup reads in the other project';
            }
            if ($rights['group'] !== null) {
                $confine = $this->groupNameIn($b, $rights['group']);
                if ($confine === null) return 'your Data Access Group in the other project could not be read';
            }
            return null;
        }
        if ($this->crossIdentifier($b, $rule) !== null) return 'a field searched is an Identifier in the other project';
        if ($rights !== null && $rights['group'] !== null) {
            $callerDag = $this->groupNameIn($b, $rights['group']);
            if ($callerDag === null) return 'your Data Access Group in the other project could not be read';
        }
        return null;
    }

    /**
     * One @UVEXISTS lookup in another project, for the live endpoint ($channel
     * staff or survey), the post-save audit (audit) and the scan (scan, from
     * its one read per request). In order: the other project's agreement (one
     * refusal for every reason), who may ask under it, the budget, then the
     * read. Every refusal or failure answers unknown; the record found there
     * is never returned. Each lookup, refused or answered, leaves one line in
     * the other project's module log; the scan logs its one read instead.
     * $opts: findExisting options from the caller (mayFullRead for surveys).
     */
    private function crossLookup($pid, array $rule, $value, array $locals, $channel, array $opts = [])
    {
        $b = isset($rule['existsPid']) ? (int) $rule['existsPid'] : 0;
        $unknown = function ($why) { return ['state' => 'unknown', 'record' => null, 'dag' => null, 'why' => $why]; };
        $scan = $channel === 'scan';
        $c = $this->crossGate($pid, $rule);
        if ($c === null) {
            if (!$scan && $b > 0 && $this->crossProjectOn() && $this->moduleEnabledIn($b)) {
                $this->logCrossProbe($b, $pid, $channel, $rule, $value, 'refused');
            }
            return $unknown('the other project does not answer this lookup');
        }
        $asSurvey = $channel === 'survey' || ($channel === 'audit' && $this->currentUsername() === null);
        $why = $this->crossCaller($b, $rule, $c, $asSurvey, $confine, $callerDag);
        if ($why !== null) {
            if (!$scan) $this->logCrossProbe($b, $pid, $channel, $rule, $value, 'refused');
            return $unknown($why);
        }
        $ruleB = $rule;
        $dag = null;
        if ($confine !== null) {
            // The post-save audit judges the record, not the person who saved
            // it: a lookup confined to the saver's group there would log a
            // value saved in another group as missing, and the same save by
            // someone else as clean. Live, the confinement is the point.
            if ($channel === 'audit') {
                $this->logCrossProbe($b, $pid, $channel, $rule, $value, 'refused');
                return $unknown('your account is in a Data Access Group of the other project, so the check after saving '
                    . 'cannot see values saved in its other groups');
            }
            $ruleB['existsScope'] = 'dag';
            $dag = $confine;
        }
        if ($scan) {
            // The scan's plan already refused a user confined there (scanPlan).
            if ($confine !== null || $callerDag !== null) return $unknown('your account is in a Data Access Group of the other project');
            $this->crossSource = (int) $pid;
            $r = $this->existsIndexLookup($b, $ruleB, $value, $locals, null, null);
        } else {
            $over = $this->crossRateLimited($b, $channel);
            if ($over !== null) {
                if ($over === 'log') $this->logCrossProbe($b, $pid, $channel, $rule, $value, 'throttled');
                return $unknown('too many lookups in the other project in the last minute');
            }
            // The record being edited is a record of this project, not of that one.
            unset($opts['self']);
            $r = $this->findExisting($b, $ruleB, $value, $locals, null, $dag, true,
                ['foreign' => true, 'callerDag' => $callerDag] + $opts);
            $this->logCrossProbe($b, $pid, $channel, $rule, $value, $r['state'],
                isset($r['returned']) ? $r['record'] : null, !empty($r['filtered']));
        }
        $r['record'] = null;
        $r['dag'] = null;
        return $r;
    }

    /**
     * The saved record's DAG: ['found' => bool, 'dag' => ?string], or false
     * when it could not be read. 'found' false is a record not saved yet.
     */
    private function recordDagOf($pid, $record)
    {
        if ($record === null || $record === '') return ['found' => false, 'dag' => null];
        $pk = $this->recordIdFieldOf($pid);
        if ($pk === '') return false;
        try {
            $data = \REDCap::getData(['project_id' => $pid, 'return_format' => 'array', 'records' => [(string) $record],
                                      'fields' => [$pk], 'exportDataAccessGroups' => true]);
            if (!is_array($data)) return false;
            foreach ($data as $rec => $node) {
                if ((string) $rec === (string) $record && is_array($node)) {
                    return ['found' => true, 'dag' => self::dagOfRecordNode($node)];
                }
            }
            return ['found' => false, 'dag' => null];
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Required mode (@UVREQUIRED): the INVERSE emptiness rule — a BLANK
     * field is the violation (every other mode is inert on blank). The
     * "when" gate above already skipped the rule when the condition is
     * false, so reaching here means the requirement is in force. Nothing
     * identifying is in a blank, so the finding carries an empty value.
     *
     * A field REDCap hides is not required (REQ-1). REDCap hides a field
     * whose branching logic is false, leaves it out of its own required
     * check and erases its value on save, so a blank there is the expected
     * answer. The logic is read in the condition dialect (php/Logic.php),
     * whose references the audit and the scan read with the rule's own
     * (requiredBranchingRefs). Logic the dialect cannot read, or that
     * references a value this context could not resolve, leaves the field
     * unchecked and says so as a rule problem, never as a finding.
     */
    private function findingsRequired(array $rule, $type, array $values, array $dupes, $onForm, $project_id, $record, $event_id, array $resolution)
    {
        $out = ['invalid' => [], 'unconfigurable' => []];
        foreach ($rule['fields'] as $field) {
            if (isset($dupes[$field])) continue;
            if ($onForm !== null && !isset($onForm[$field])) continue;
            $value = isset($values[$field]) ? $values[$field] : null;
            if (is_array($value)) continue; // non-scalar (checkbox map) — not a required target
            if ($value !== null && !Logic::isBlank((string) $value)) continue;
            $shown = $this->branchingShows($project_id, $field, $values, $resolution);
            if ($shown === false) continue;
            if (is_string($shown)) {
                $out['unconfigurable'][] = ['fields' => [$field], 'why' => $shown];
                continue;
            }
            $out['invalid'][] = ['field' => $field, 'value' => '', 'algo' => 'required', 'type' => 'required', 'reason' => 'required-blank'];
        }
        return $out;
    }

    /**
     * Whether REDCap's branching logic shows $field in this context: true
     * (no logic, or the logic holds), false (the logic is false), or a
     * sentence saying why it cannot be told. See findingsRequired().
     */
    private function branchingShows($project_id, $field, array $values, array $resolution)
    {
        $b = $this->branchingOf($project_id, $field);
        if ($b === null) return true;
        $name = '[' . $field . ']';
        if (!is_array($b['ast'])) {
            return 'the branching logic of ' . $name . ' ' . $b['error']
                 . ' The module cannot tell whether REDCap shows the field, so the requirement is not checked here.';
        }
        foreach (Logic::referencedFields($b['ast']) as $ref) {
            $state = isset($resolution[$ref[0]]) ? $resolution[$ref[0]] : 'ok';
            if ($state !== 'ok') return 'the branching logic of ' . $name . ' ' . self::resolutionProblem($state, $ref[0]);
        }
        return Logic::evaluate($b['ast'], $values, Logic::BLANK_INERT, false, $this->conditionNumbers($project_id));
    }

    /**
     * The branching logic of $field parsed in the condition dialect, or null
     * when the field has none: ['text' => the logic, 'ast' => the parsed
     * condition], or 'ast' false and 'error' when the dialect cannot read it.
     * Read once per project and field.
     */
    private function branchingOf($pid, $field)
    {
        $pid = (int) $pid;
        $field = (string) $field;
        if (isset($this->branchingCache[$pid]) && array_key_exists($field, $this->branchingCache[$pid])) {
            return $this->branchingCache[$pid][$field];
        }
        $dd = $this->dataDictionary($pid);
        $text = ($dd && isset($dd[$field]['branching_logic'])) ? trim((string) $dd[$field]['branching_logic']) : '';
        $out = null;
        if ($text !== '') {
            $p = Logic::parse($text);
            $out = empty($p['ok'])
                ? ['text' => $text, 'ast' => false, 'error' => isset($p['error']) ? (string) $p['error'] : 'cannot be read.']
                : ['text' => $text, 'ast' => $p['ast']];
        }
        // Only a dictionary that was read is an answer worth keeping.
        if ($dd) $this->branchingCache[$pid][$field] = $out;
        return $out;
    }

    /**
     * The fields the branching logic of a @UVREQUIRED rule's fields reads,
     * for the audit's and the scan's read sets. Other modes judge a value,
     * and a hidden field has none once REDCap erased it.
     *
     * @return string[]
     */
    private function requiredBranchingRefs($pid, array $r)
    {
        if (!empty($r['configError']) || ModeRegistry::modeOfType(isset($r['type']) ? $r['type'] : '') !== 'required') return [];
        $out = [];
        foreach ((isset($r['fields']) && is_array($r['fields'])) ? $r['fields'] : [] as $f) {
            $b = $this->branchingOf($pid, $f);
            if ($b === null || !is_array($b['ast'])) continue;
            foreach (Logic::referencedFields($b['ast']) as $ref) $out[(string) $ref[0]] = true;
        }
        return array_keys($out);
    }

    /**
     * Choices mode (@UVCHOICES): a saved value that is a currently-hidden
     * choice is the violation. The "when" gate above already skipped the
     * rule while its condition is false, so reaching here means the filter
     * is in force. A value outside the field's own choice list (e.g. a
     * missing-data code like -99) is out of the filter's scope — never
     * flagged. Checkbox values arrive as code=>0/1 maps (keepArrays); this
     * is the one mode that must judge them.
     */
    private function findingsChoices(array $rule, $type, array $values, array $dupes, $onForm, $project_id, $record, $event_id, array $resolution)
    {
        $out = ['invalid' => [], 'unconfigurable' => []];
        $all = (isset($rule['choicesAll']) && is_array($rule['choicesAll']))
            ? array_map('strval', $rule['choicesAll']) : [];
        if (isset($rule['choicesShow']) && is_array($rule['choicesShow'])) {
            if (!$all) {
                // A "show" whitelist is only meaningful against the full
                // list — without it the complement cannot be computed.
                $out['unconfigurable'][] = ['fields' => $rule['fields'],
                    'why' => 'a "show" list needs the field\'s full choice list — rule skipped'];
                return $out;
            }
            $hidden = array_diff($all, array_map('strval', $rule['choicesShow']));
        } elseif (isset($rule['choicesHide']) && is_array($rule['choicesHide'])) {
            $hidden = array_map('strval', $rule['choicesHide']);
        } else {
            $out['unconfigurable'][] = ['fields' => $rule['fields'],
                'why' => 'the choices rule carries neither a "show" nor a "hide" list — rule skipped'];
            return $out;
        }
        $hiddenSet = array_fill_keys(array_values($hidden), true);
        foreach ($rule['fields'] as $field) {
            if (isset($dupes[$field])) continue;
            if ($onForm !== null && !isset($onForm[$field])) continue;
            $value = isset($values[$field]) ? $values[$field] : null;
            if (is_array($value)) {
                foreach ($value as $code => $checked) {
                    if ((string) $checked !== '1') continue;
                    $c = (string) $code;
                    if ($all && !in_array($c, $all, true)) continue; // outside the choice list — out of scope
                    if (isset($hiddenSet[$c])) {
                        // locus: WHICH hidden code. A checkbox can have
                        // several ticked at once, and every one of them is
                        // a separate problem at the same field - so without
                        // this they were the same finding twice, the unique
                        // key refused the second, and the batch that
                        // carried them both was rolled back entire.
                        $out['invalid'][] = ['field' => $field, 'value' => $c, 'algo' => 'choices',
                                             'type' => 'choices', 'reason' => 'hidden-choice',
                                             'locus' => $c];
                    }
                }
                continue;
            }
            if ($value === null || trim((string) $value) === '') continue;
            $v = trim((string) $value);
            if ($all && !in_array($v, $all, true)) continue; // outside the choice list — out of scope
            if (isset($hiddenSet[$v])) {
                $out['invalid'][] = ['field' => $field, 'value' => $value, 'algo' => 'choices',
                                     'type' => 'choices', 'reason' => 'hidden-choice'];
            }
        }
        return $out;
    }

    /**
     * Constraint mode (@UVASSERT): the field is invalid whenever its
     * "assert" condition is false against this context's values. An empty
     * field is inert (emptiness is @UVREQUIRED's concern, not a
     * constraint's). No check character / pattern — just the test. The
     * condition is re-parsed here (config-validated, cheap) and evaluated
     * against the full value map, so no fold is needed server-side.
     */
    private function findingsConstraint(array $rule, $type, array $values, array $dupes, $onForm, $project_id, $record, $event_id, array $resolution)
    {
        $out = ['invalid' => [], 'unconfigurable' => []];
        $a = Logic::parse(isset($rule['assert']) ? (string) $rule['assert'] : '');
        if (empty($a['ok'])) {
            $out['unconfigurable'][] = ['fields' => $rule['fields'], 'why' => 'the "assert" condition cannot be evaluated — field skipped'];
            return $out;
        }
        // A reference the context could not actually RESOLVE (off-event, on
        // a different repeating instrument, or a failed read) must not be
        // evaluated: Logic::operandValue would render it '' and the assert
        // would "fail" against a value we never read, logging a violation
        // for correct data on every save and every scan (H-01/H-04/M-01).
        // Surface it instead — the module's rule is that nothing fails
        // silently (M-05).
        foreach (Logic::referencedFields($a['ast']) as $ref) {
            $state = isset($resolution[$ref[0]]) ? $resolution[$ref[0]] : 'ok';
            if ($state !== 'ok') {
                $out['unconfigurable'][] = ['fields' => $rule['fields'],
                    'why' => 'the "assert" condition ' . self::resolutionProblem($state, $ref[0])];
                return $out;
            }
        }
        $numbers = $this->conditionNumbers($project_id);
        foreach ($rule['fields'] as $field) {
            if (isset($dupes[$field])) continue;
            if ($onForm !== null && !isset($onForm[$field])) continue;
            $value = isset($values[$field]) ? $values[$field] : null;
            // Inert when blank. Whitespace-only counts as blank on BOTH
            // sides now: the client already trims with this charlist before
            // deciding inertness, and the two evaluators trim with it before
            // comparing, so anything else made the browser silent while the
            // server logged a violation (M-04).
            if ($value === null || is_array($value)) continue;
            if (trim((string) $value, " \t\r\n") === '') continue;
            if (!Logic::evaluate($a['ast'], $values, Logic::BLANK_PASSES, self::testTextMode($rule), $numbers)) {
                $out['invalid'][] = ['field' => $field, 'value' => $value, 'algo' => 'constraint', 'type' => 'constraint', 'reason' => 'assert:' . ($rule['_temporalAssertLabel'] ?? $rule['assert'])];
            }
        }
        return $out;
    }

    /**
     * Window mode (@UVWINDOW): each saved date must fall inside its window
     * around the "from" date, and with "notFuture" not after today. Saved
     * dates are always Y-M-D, whatever the field displays. The verdict is
     * TemporalLogic::windowVerdict, the twin of the browser's.
     *
     * A blank value, and a blank "from" date, check nothing: the visit that
     * anchors the window has not happened yet. A "from" date this context
     * could not resolve is reported, never read as blank, for the same reason
     * the assert above is (M-01). A saved value that does not read as a date
     * at all is reported too: REDCap validates dates on entry, so one that
     * fails here came in through a path that skipped that check.
     */
    private function findingsWindow(array $rule, $type, array $values, array $dupes, $onForm, $project_id, $record, $event_id, array $resolution, array $meta = [])
    {
        $out = ['invalid' => [], 'unconfigurable' => []];
        $anchor = null;
        // "today", "now" or a written date (windowAnchor) instead of a field.
        $kw = isset($rule['windowAnchor']) && is_string($rule['windowAnchor']) ? $rule['windowAnchor'] : null;
        $clockAnchor = $kw === 'today' || $kw === 'now';
        if ($kw !== null && !$clockAnchor) $anchor = $kw;
        // A "from" date that cannot be read: the window part is not checked
        // ($fromWhy says why), and notFuture and notPast, which do not need it,
        // still are.
        $fromWhy = null;
        if (isset($rule['windowFrom']) && is_string($rule['windowFrom']) && $rule['windowFrom'] !== '') {
            if (array_key_exists('windowFromValue', $rule)) {
                // Compiled on the extended (event/instance) path, which resolved it.
                $anchor = $rule['windowFromValue'];
                if (!is_string($anchor)) {
                    $fromWhy = 'the "from" date ' . $rule['windowFrom'] . ' could not be resolved';
                    if (isset($rule['windowFromValueWhy']) && is_string($rule['windowFromValueWhy'])) {
                        $fromWhy .= ' (' . rtrim($rule['windowFromValueWhy'], '.') . ')';
                    }
                }
            } else {
                $op = ModeRegistry::operandRef($rule['windowFrom']);
                $state = $op === null ? null : (isset($resolution[$op[1]]) ? $resolution[$op[1]] : 'ok');
                if ($op === null) {
                    $fromWhy = 'the "from" date cannot be evaluated';
                } elseif ($state !== 'ok') {
                    $fromWhy = 'the "from" date ' . self::resolutionProblem($state, $op[1]);
                } else {
                    $v = isset($values[$op[1]]) ? $values[$op[1]] : '';
                    $anchor = is_array($v) ? '' : (string) $v;
                }
            }
            if ($fromWhy !== null) {
                if (empty($rule['windowNotFuture']) && empty($rule['windowNotPast'])) {
                    $out['unconfigurable'][] = ['fields' => $rule['fields'], 'why' => $fromWhy . ' — field skipped'];
                    return $out;
                }
                $anchor = false;
            }
            // A "from" date marked with a Missing Data Code was not entered:
            // there is nothing to count from, as with a blank one.
            if (is_string($anchor) && self::isMissingCode($anchor, $this->missingDataCodes($project_id))) $anchor = '';
        }
        $spec = [
            'lo' => isset($rule['windowLo']) ? $rule['windowLo'] : null,
            'hi' => isset($rule['windowHi']) ? $rule['windowHi'] : null,
            'unit' => isset($rule['windowUnit']) ? $rule['windowUnit'] : 'days',
            'period' => isset($rule['windowPeriod']) ? $rule['windowPeriod'] : null,
            'offLo' => isset($rule['windowOffLo']) ? $rule['windowOffLo'] : 0,
            'offHi' => isset($rule['windowOffHi']) ? $rule['windowOffHi'] : 0,
            'weekStart' => isset($rule['windowWeekStart']) ? $rule['windowWeekStart'] : 'monday',
            'notFuture' => !empty($rule['windowNotFuture']),
            'notPast' => !empty($rule['windowNotPast']),
            'type' => isset($rule['dateType']) ? $rule['dateType'] : null,
            'fromType' => $kw !== null ? TemporalLogic::keywordAnchorType($kw) : (isset($rule['fromType']) ? $rule['fromType'] : null),
        ];
        // The parts judged against "today": a window or period counted from
        // today or now, and notPast. Each saved value is judged against the day
        // it was saved (valueSavedAt), so re-saving another field never turns an
        // old date into a finding. notFuture moves to that day too; where the
        // day is not known it is judged against today, which can only miss a
        // date that was in the future then, never invent one.
        $todayRelative = $clockAnchor || $spec['notPast'];
        $asked = $todayRelative || $spec['notFuture'];
        // The extended path marks a "from" that resolved to the field itself, in
        // this same entry ([baseline_arm_1][visit_date] saved on the baseline
        // visit): no window applies there.
        $self = isset($rule['windowFromValueSelf']) ? $rule['windowFromValueSelf'] : null;
        foreach ($rule['fields'] as $field) {
            if (isset($dupes[$field])) continue;
            if ($onForm !== null && !isset($onForm[$field])) continue;
            $value = isset($values[$field]) ? $values[$field] : null;
            if ($value === null || is_array($value)) continue;
            $vspec = $spec;
            $clock = null;
            $asOf = null;
            if ($asked && trim((string) $value, " \t\r\n") !== '') {
                $server = $this->serverClock($project_id);
                if (self::windowDayFree($spec, $clockAnchor, (string) $value, $server)) {
                    // No day it could have been saved on changes the verdict: no log read.
                    $st = ['at' => null, 'why' => null];
                    $clock = $server;
                } else {
                    $st = $this->valueSavedAt($project_id, $record, $event_id, isset($meta['instance']) ? $meta['instance'] : 1,
                        $field, (string) $value);
                }
                if ($st['at'] !== null) {
                    $asOf = $st['at'];
                    $clock = ['today' => substr($asOf, 0, 10), 'now' => $asOf];
                } elseif ($st['why'] !== null) {
                    if ($spec['notFuture']) $clock = $server;
                    if ($todayRelative) {
                        // Not checked: the parts that need the day it was saved.
                        $vspec['notPast'] = false;
                        if ($clockAnchor) { $vspec['lo'] = null; $vspec['hi'] = null; $vspec['period'] = null; }
                        $out['unconfigurable'][] = ['fields' => [$field],
                            'why' => 'the day this value was saved is not known (' . $st['why'] . ') — it was not checked against that day'];
                    }
                }
            }
            // A date and time gets the most margin the page could have given
            // (AFTER_SAVE_SLACK_S): its 120 seconds plus up to 600 for a computer
            // clock that is off, which only the page can measure. A time the page
            // accepted is then never flagged after saving.
            if ($clock !== null && $spec['type'] !== 'date') $clock = TemporalLogic::clockSlack($clock, TemporalLogic::AFTER_SAVE_SLACK_S);
            $vanchor = $self === $field ? '' : $anchor;
            $vanchorHi = $vanchor;
            if ($clockAnchor) {
                // "now" is a span, pastNow to futureNow, except for a period: whole
                // calendar days, so it takes the plain now, as the page does.
                $spread = $kw === 'now' && $vspec['period'] === null;
                $vanchor = $clock === null ? false : ($kw === 'today' ? $clock['today']
                    : ($spread && isset($clock['pastNow']) ? $clock['pastNow'] : $clock['now']));
                $vanchorHi = $clock === null ? false : ($kw === 'today' ? $clock['today']
                    : ($spread && isset($clock['futureNow']) ? $clock['futureNow'] : $clock['now']));
                if ($vspec['lo'] === null && $vspec['hi'] === null && $vspec['period'] === null) { $vanchor = null; $vanchorHi = null; }
            }
            $r = TemporalLogic::windowVerdictSpread($vspec, (string) $value, 'ymd', $vanchor, $vanchorHi, 'ymd', $clock);
            if ($r['verdict'] === 'unknown') {
                $readable = TemporalValue::parse(trim((string) $value, " \t\r\n"), $spec['type'], 'ymd')['state'] === 'ok';
                // The parts judged against the clock alone, which passed.
                $held = $clock === null ? '' : ($spec['notFuture'] && $vspec['notPast'] ? ' (the date is neither in the future nor in the past)'
                    : ($spec['notFuture'] ? ' (the date is not in the future)' : ($vspec['notPast'] ? ' (the date is not in the past)' : '')));
                if ($fromWhy !== null && $readable) {
                    $why = $fromWhy . ' — the window was not checked' . $held;
                } elseif ($fromWhy !== null) {
                    $why = $fromWhy . ', and the saved date is not a date this rule can read — field skipped';
                } elseif ($held !== '' && $readable) {
                    // The value was read and passed the clock parts: only the window part is unknown.
                    $why = 'the "from" date is not a date this rule can read — the window was not checked' . $held;
                } else {
                    $why = 'the saved date or its "from" date is not a date this rule can read — field skipped';
                }
                $out['unconfigurable'][] = ['fields' => [$field], 'why' => $why];
                continue;
            }
            if ($r['verdict'] === 'ok' || $r['verdict'] === 'inert') continue;
            $finding = ['field' => $field, 'value' => $value, 'algo' => 'window', 'type' => 'window', 'reason' => $r['verdict']];
            // The day the verdict was judged against, for the scan's detail line,
            // on a verdict that rests on it: not a window counted from a field.
            $dayBound = $r['verdict'] === 'future' || $r['verdict'] === 'past' || $clockAnchor;
            if ($asOf !== null && $dayBound) $finding['asOf'] = substr($asOf, 0, $spec['type'] === 'date' ? 10 : 16);
            $out['invalid'][] = $finding;
        }
        return $out;
    }

    /**
     * When $value was saved, for a @UVWINDOW part judged against that day:
     * ['at' => 'Y-m-d H:i:s' in the rule's time zone, or null, 'why' => why not].
     * REDCap's log is the only source, for the post-save audit too: REDCap
     * writes a save's log row before it calls redcap_save_record (checked live
     * by WINDOW_LIVE_ACCEPTANCE I3), so a value the save wrote is already
     * there. A value whose newest row shows another value, a field the log
     * never shows, a record with no rows, or a log read only in part, is a gap
     * in the history: the day is not known, and the value is not judged
     * against any day rather than against today. Nothing the browser sends
     * decides it.
     */
    private function valueSavedAt($pid, $record, $eventId, $instance, $field, $value)
    {
        $stamps = $this->valueStamps($pid);
        if ($stamps === null) return ['at' => null, 'why' => 'the project log could not be read'];
        $r = $stamps->savedAt($record, $eventId, $instance, $field, $value);
        if ($r['state'] === 'logged') return ['at' => $r['at'], 'why' => null];
        $why = ['changed' => 'the project log shows another value for it',
                'unlogged' => 'the project log does not show when it was saved',
                'capped' => 'the project log holds more saves of this record than are read',
                'none' => 'the project log holds nothing for this record',
                'unknown' => 'the project log could not be read'];
        return ['at' => null, 'why' => isset($why[$r['state']]) ? $why[$r['state']] : $why['unknown']];
    }

    /**
     * Whether judging $value against the server's clock gives the verdict it
     * gets against any earlier day it could have been saved on, so the log
     * need not be read: with notFuture, a value after now was in the future
     * then too; with notPast as the only part judged against a day, a value
     * from now on was never in the past. A window counted from today or now
     * always needs the day.
     */
    private static function windowDayFree(array $spec, $clockAnchor, $value, array $clock)
    {
        if (empty($spec['type']) || !isset($clock['today'], $clock['now'])) return false;
        $v = TemporalValue::parse(trim((string) $value, " \t\r\n"), $spec['type'], 'ymd');
        $now = TemporalValue::parse((string) $clock['now'], 'datetime_seconds', 'ymd');
        if ($v['state'] !== 'ok' || $now['state'] !== 'ok') return false;
        $date = $v['type'] === 'date';
        if (!empty($spec['notFuture'])
            && ($date ? strcmp($v['value'], (string) $clock['today']) > 0 : $v['seconds'] > $now['seconds'] + TemporalLogic::AFTER_SAVE_SLACK_S)) {
            return true;
        }
        if (!empty($spec['notPast']) && empty($spec['notFuture']) && !$clockAnchor) {
            return $date ? strcmp($v['value'], (string) $clock['today']) >= 0 : $v['seconds'] >= $now['seconds'];
        }
        return false;
    }

    /** The project's log reader (ValueStamps), once per request; null when the log cannot be resolved. */
    private function valueStamps($pid)
    {
        if ($this->valueStampsOverride !== null) return $this->valueStampsOverride;
        $key = (string) $pid;
        if (!array_key_exists($key, $this->valueStampsMemo)) {
            $server = new \DateTimeZone(date_default_timezone_get());
            $zone = $this->clockZone($pid);
            $this->valueStampsMemo[$key] = ValueStamps::forProject(new Scan\ModuleDb($this), $pid, $server, $zone === null ? $server : $zone);
        }
        return $this->valueStampsMemo[$key];
    }

    /**
     * Range mode (@UVRANGE): each saved number must lie inside the "hard"
     * range, and is reported as unusual outside the "soft" one. The verdict is
     * Logic::rangeVerdict, the twin of the browser's. A blank value checks
     * nothing; a value that is not a number is implausible ("not-a-number").
     * Soft findings are logged and scanned like hard ones: "softBlock" only
     * decides whether the browser asks before saving.
     *
     * With a growth "reference" the limits apply to the value's z-score
     * against that reference (GrowthReference), for the sex and the age, or
     * the length or height, the rule reads from other fields. The value is
     * first multiplied by the rule's "measureScale", if any. A measurement at
     * or below 0 is implausible ("not-positive"). When there is no z-score (a
     * blank or unreadable input, a sex code that is neither "male" nor
     * "female", an age or length outside the reference) the rule's
     * "plausible" range, in the reference's unit, judges the value
     * ("plausible-low", "plausible-high"), and the value is listed under
     * 'unevaluated' with the reason. An input this context could not
     * resolve, and a reference that cannot be read, are rule problems. A
     * growth finding carries the reference id, and the z-score when there is
     * one.
     */
    private function findingsRange(array $rule, $type, array $values, array $dupes, $onForm, $project_id, $record, $event_id, array $resolution)
    {
        $out = ['invalid' => [], 'unconfigurable' => []];
        $spec = ['decimalComma' => !empty($rule['decimalComma'])];
        foreach (['softLo' => 'rangeSoftLo', 'softHi' => 'rangeSoftHi', 'hardLo' => 'rangeHardLo', 'hardHi' => 'rangeHardHi'] as $k => $rk) {
            if (isset($rule[$rk])) $spec[$k] = $rule[$rk];
        }
        $isGrowth = isset($rule['rangeReference']);
        // the z-score is a plain number, whatever the field's decimal mark
        if ($isGrowth) $spec['decimalComma'] = false;
        $growth = null;
        foreach ($rule['fields'] as $field) {
            if (isset($dupes[$field])) continue;
            if ($onForm !== null && !isset($onForm[$field])) continue;
            $value = isset($values[$field]) ? $values[$field] : null;
            if ($value === null || is_array($value)) continue;
            $verdictOf = (string) $value;
            $extra = [];
            if ($isGrowth) {
                $m = GrowthReference::measure((string) $value, !empty($rule['decimalComma']),
                    isset($rule['rangeMeasureScale']) ? $rule['rangeMeasureScale'] : null);
                if ($m['state'] === 'blank') continue;
                $extra = ['reference' => (string) $rule['rangeReference']];
                if ($m['state'] !== 'ok') {
                    $out['invalid'][] = ['field' => $field, 'value' => $value, 'algo' => 'range', 'type' => 'range',
                                         'reason' => $m['state']] + $extra;
                    continue;
                }
                // The inputs are the same for every field of the rule: read once,
                // and only for a value there is to judge.
                if ($growth === null) $growth = $this->growthInputs($rule, $values, $resolution, $project_id);
                if (isset($growth['problem'])) {
                    $out['unconfigurable'][] = ['fields' => [$field], 'why' => $growth['problem']];
                    continue;
                }
                if ($growth['state'] !== 'ok') {
                    // No z-score: the "plausible" range judges, if the rule has one.
                    $p = GrowthReference::plausible($m['y'], isset($rule['rangePlausibleLo']) ? $rule['rangePlausibleLo'] : null,
                        isset($rule['rangePlausibleHi']) ? $rule['rangePlausibleHi'] : null);
                    if ($p !== null) {
                        $out['invalid'][] = ['field' => $field, 'value' => $value, 'algo' => 'range', 'type' => 'range',
                                             'reason' => $p] + $extra;
                    }
                    $out['unevaluated'][] = ['field' => $field, 'why' => $growth['why']] + $extra;
                    continue;
                }
                // WHO's correction for how the child was measured (yAdd) is
                // added to the measurement before it is scored
                $z = GrowthReference::zScore($growth['entry'], $growth['table'], $growth['sex'], $growth['x'], $m['y'] + $growth['yAdd']);
                if ($z['state'] !== 'ok') {
                    // only "outside": table() refuses rows that do not cover "valid"
                    $out['unconfigurable'][] = ['fields' => [$field],
                        'why' => 'the growth reference "' . $rule['rangeReference'] . '" has no row at this '
                               . ($growth['entry']['axis'] === 'age' ? 'age' : $growth['entry']['axis']) . ' — field skipped'];
                    continue;
                }
                $verdictOf = $z['z'];
                $extra['z'] = $z['z'];
            }
            $r = Logic::rangeVerdict($spec, $verdictOf);
            if ($r['tier'] === 'ok' || $r['tier'] === 'inert') continue;
            $out['invalid'][] = ['field' => $field, 'value' => $value, 'algo' => 'range', 'type' => 'range',
                                 'reason' => $r['reason']] + $extra;
        }
        return $out;
    }

    /**
     * Delta mode (@UVDELTA): each saved number may move from the earlier value
     * its "vs" names by at most "max" (in its unit) and "maxPct" (a percent of
     * the earlier value), in the rule's direction. The verdict is
     * Logic::deltaVerdict, the twin of the browser's; a finding's reason is
     * the way the value moved, "up" or "down".
     *
     * A blank value or a blank earlier value checks nothing: on a first visit
     * or instance there is no earlier one (the extended path resolves an
     * absent row to blank). A "vs" this context could not resolve is
     * reported, never read as blank, as the assert above is (M-01); so is a
     * saved value, or an earlier one, that is not a number this rule can
     * read. A "vs" that resolved to the field itself in this same entry (the
     * extended path's deltaVsValueSelf: [baseline_arm_1][weight] saved on the
     * baseline visit) has nothing to compare with there.
     */
    private function findingsDelta(array $rule, $type, array $values, array $dupes, $onForm, $project_id, $record, $event_id, array $resolution)
    {
        $out = ['invalid' => [], 'unconfigurable' => []];
        $vs = isset($rule['deltaVs']) && is_string($rule['deltaVs']) ? $rule['deltaVs'] : '';
        if (array_key_exists('deltaVsValue', $rule)) {
            // Compiled on the extended (event/instance) path, which resolved it.
            $earlier = $rule['deltaVsValue'];
            if (!is_string($earlier)) {
                $why = 'the "vs" value ' . $vs . ' could not be resolved';
                if (isset($rule['deltaVsValueWhy']) && is_string($rule['deltaVsValueWhy'])) {
                    $why .= ' (' . rtrim($rule['deltaVsValueWhy'], '.') . ')';
                }
                $out['unconfigurable'][] = ['fields' => $rule['fields'], 'why' => $why . ' — field skipped'];
                return $out;
            }
        } else {
            $op = ModeRegistry::operandRef($vs);
            $state = $op === null ? null : (isset($resolution[$op[1]]) ? $resolution[$op[1]] : 'ok');
            if ($op === null) {
                $out['unconfigurable'][] = ['fields' => $rule['fields'], 'why' => 'the "vs" value cannot be evaluated — field skipped'];
                return $out;
            }
            if ($state !== 'ok') {
                $out['unconfigurable'][] = ['fields' => $rule['fields'], 'why' => 'the "vs" value ' . self::resolutionProblem($state, $op[1])];
                return $out;
            }
            $v = isset($values[$op[1]]) ? $values[$op[1]] : '';
            $earlier = is_array($v) ? '' : (string) $v;
        }
        // An earlier value marked with a Missing Data Code was not entered.
        if (self::isMissingCode($earlier, $this->missingDataCodes($project_id))) $earlier = '';
        $self = isset($rule['deltaVsValueSelf']) ? $rule['deltaVsValueSelf'] : null;
        // Long numbers pay the record's evaluation budget (Logic::deltaVerdict).
        // The extended audit and the scan start one per record; a save with no
        // event or instance rule has not, and starts it here.
        if ($this->temporalBudget === null) $this->temporalBegin($project_id);
        $budget = $this->temporalBudget;
        $charge = function ($units) use ($budget) { return $budget->take($units); };
        $spec = [
            'max' => isset($rule['deltaMax']) ? $rule['deltaMax'] : null,
            'maxPct' => isset($rule['deltaMaxPct']) ? $rule['deltaMaxPct'] : null,
            'direction' => isset($rule['deltaDirection']) ? $rule['deltaDirection'] : 'both',
            'decimalComma' => !empty($rule['decimalComma']),
            'earlierComma' => !empty($rule['deltaVsComma']),
        ];
        foreach ($rule['fields'] as $field) {
            if (isset($dupes[$field])) continue;
            if ($onForm !== null && !isset($onForm[$field])) continue;
            $value = isset($values[$field]) ? $values[$field] : null;
            if ($value === null || is_array($value)) continue;
            if ($self === $field) continue;
            $r = Logic::deltaVerdict($spec, (string) $value, $earlier, $charge);
            if ($r['verdict'] === 'ok' || $r['verdict'] === 'inert') continue;
            if ($r['verdict'] === 'unknown') {
                $why = $r['reason'] === 'not-a-number' ? 'the saved value is not a number this rule can read'
                     : ($r['reason'] === 'earlier-not-a-number' ? 'the "vs" value ' . $vs . ' is not a number this rule can read'
                     : ($r['reason'] === 'limit' ? 'the evaluation budget for this record is spent, so the change was not worked out'
                     : 'the value and its "vs" value are too long to compare'));
                $out['unconfigurable'][] = ['fields' => [$field], 'why' => $why . ' — field skipped'];
                continue;
            }
            // vs: what the value was compared with, so two tags on one field
            // (the previous visit, baseline) log findings that can be told apart.
            $out['invalid'][] = ['field' => $field, 'value' => $value, 'algo' => 'delta', 'type' => 'delta', 'reason' => $r['verdict'],
                'vs' => $vs];
        }
        return $out;
    }

    /**
     * Write-once mode (@UVWRITEONCE): the saved value must be the value the
     * field is locked to (Logic::writeOnceVerdict), worked out from every save
     * of the field on this record, event and instance in REDCap's log
     * (ValueHistory). The log is the only source, so a value written by the
     * API or an import is judged like one typed on a form, once the audit or
     * the scan reads it. The module never writes the locked value back. A log
     * that cannot be read in full, a save it does not say plainly, a value it
     * never shows saved, or user roles that cannot be read when a change needs
     * them, is a rule problem ("not checked"), never a pass.
     */
    private function findingsWriteonce(array $rule, $type, array $values, array $dupes, $onForm, $project_id, $record, $event_id, array $resolution, array $meta = [])
    {
        $out = ['invalid' => [], 'unconfigurable' => []];
        $instance = isset($meta['instance']) ? max(1, (int) $meta['instance']) : 1;
        $codes = isset($meta['missingCodes']) && is_array($meta['missingCodes']) ? $meta['missingCodes'] : $this->missingDataCodes($project_id);
        $isMissing = function ($v) use ($codes) { return self::isMissingCode($v, $codes); };
        $roles = isset($rule['writeonceRoles']) && is_array($rule['writeonceRoles']) ? $rule['writeonceRoles'] : [];
        $holds = function ($user) use ($roles, $project_id) { return $this->userHoldsRole($project_id, $user, $roles); };
        $history = null;
        foreach ($rule['fields'] as $field) {
            if (isset($dupes[$field])) continue;
            if ($onForm !== null && !isset($onForm[$field])) continue;
            $state = isset($resolution[$field]) ? $resolution[$field] : 'ok';
            if ($state !== 'ok') {
                $out['unconfigurable'][] = ['fields' => [$field], 'why' => 'the saved value ' . self::resolutionProblem($state, $field)];
                continue;
            }
            $value = isset($values[$field]) ? $values[$field] : '';
            if (is_array($value)) {
                $out['unconfigurable'][] = ['fields' => [$field], 'why' => 'the saved value is not one value — field not checked'];
                continue;
            }
            if ($history === null) $history = $this->valueHistory($project_id);
            if ($history === false) {
                $out['unconfigurable'][] = ['fields' => [$field], 'why' => 'the project log could not be read — field not checked'];
                continue;
            }
            $h = $history->saves($record, $event_id, $instance, $field);
            if ($h['state'] === 'capped' || $h['state'] === 'unknown') {
                $unknown = ['delete' => 'the project log holds a deletion this check cannot place (part of the record, or one arm of it)',
                            'noevent' => 'the project log holds a save of this field that names no event',
                            'start' => 'the project log does not show the record being made (it was renamed, came with a copied project, or the record id field was renamed), so its first saves are not read',
                            'startrow' => 'the row of the project log that made the record could not be read plainly (its record id line comes after a Text or Notes value), so its first saves are not read',
                            'unnamed' => 'the log reader was not told to keep this field',
                            'read' => 'the project log could not be read'];
                $why = isset($h['why'], $unknown[$h['why']]) ? $unknown[$h['why']] : $unknown['read'];
                $out['unconfigurable'][] = ['fields' => [$field], 'why' => ($h['state'] === 'capped'
                    ? 'the project log holds more saves of this record and event than are read, so its first save of this value is not known'
                    : $why) . ' — field not checked'];
                continue;
            }
            $since = isset($rule['writeonceSince']) && is_string($rule['writeonceSince']) ? $rule['writeonceSince'] : null;
            $sinceTs = $since === null ? null : $history->logTimeOf($since);
            if ($since !== null && $sinceTs === null) {
                $out['unconfigurable'][] = ['fields' => [$field], 'why' => '"since" is not a date this rule can read — field not checked'];
                continue;
            }
            $r = Logic::writeOnceVerdict($h['saves'], (string) $value, $holds, $isMissing, $sinceTs, $since);
            if ($r['verdict'] === 'ok') continue;
            if ($r['verdict'] === 'unknown') {
                $why = ['ambiguous' => 'the project log does not say plainly what one save of it wrote',
                        'gap' => 'the project log does not show the save of the value it holds',
                        'roles' => 'the user roles could not be read to tell whether a change was made by a role that may make it'];
                $out['unconfigurable'][] = ['fields' => [$field], 'why' => (isset($why[$r['why']]) ? $why[$r['why']] : $why['gap']) . ' — field not checked'];
                continue;
            }
            $finding = ['field' => $field, 'value' => $value, 'algo' => 'writeonce', 'type' => 'writeonce', 'reason' => $r['verdict']];
            // when the value was locked (minutes are enough to find the save in REDCap's log) and when it was changed
            if (is_string($r['lockedAt'])) $finding['lockedAt'] = substr($r['lockedAt'], 0, 16);
            if (is_string($r['changedAt'])) $finding['changedAt'] = substr($r['changedAt'], 0, 16);
            $out['invalid'][] = $finding;
        }
        return $out;
    }

    /**
     * @UVWRITEONCE "page" hook: a field is locked on the page when its saved
     * value is a value (not blank, not a Missing Data Code) and the person
     * looking holds none of the rule's roles. A survey respondent holds no
     * role. Every other field is left off the page, and so is a rule with no
     * field left, or one whose "since" is a day still to come in the
     * project's clock (the audit locks nothing before it either). The roles
     * and "since" never reach the page (serverKeys). Roles that cannot be
     * read lock the field for everyone, with writeonceRolesUnread set so the
     * note says the role could not be checked: a lock left open would let
     * anyone change the value. Saved values that cannot be read lock nothing
     * (a blank field may still be filled in), and writeonceValuesUnread makes
     * the note say the lock could not be checked here.
     */
    private function pageWriteonce(array $rules, $pid, $context, $record, $instrument, $event_id, $repeat_instance)
    {
        $out = [];
        $codes = null;
        $user = null;
        $today = null;
        // every locked field's saved value in one read, not one per rule
        $all = [];
        foreach ($rules as $r) {
            if (is_array($r) && (isset($r['type']) ? $r['type'] : '') === 'writeonce' && empty($r['configError'])) {
                foreach ((isset($r['fields']) && is_array($r['fields'])) ? $r['fields'] : [] as $f) $all[(string) $f] = true;
            }
        }
        $values = null;
        $resolution = [];
        foreach ($rules as $r) {
            if (!is_array($r) || (isset($r['type']) ? $r['type'] : '') !== 'writeonce' || !empty($r['configError'])) {
                $out[] = $r;
                continue;
            }
            if ($record === null || $record === '' || $pid === null) continue;   // a new record: nothing saved to lock
            if (isset($r['writeonceSince']) && is_string($r['writeonceSince'])) {
                if ($today === null) $today = (string) $this->serverClock($pid)['today'];
                if (strcmp($today, $r['writeonceSince']) < 0) continue;   // the lock starts later
            }
            $roles = isset($r['writeonceRoles']) && is_array($r['writeonceRoles']) ? $r['writeonceRoles'] : [];
            if ($roles && $context !== 'survey') {
                if ($user === null) $user = (string) $this->currentUsername();
                $holds = $this->userHoldsRole($pid, $user, $roles);
                if ($holds === true) continue;
                // roles that cannot be read lock the field, and the note says why
                if ($holds === null) $r['writeonceRolesUnread'] = true;
            }
            $fields = isset($r['fields']) && is_array($r['fields']) ? $r['fields'] : [];
            if ($values === null) {
                try {
                    $values = $this->readValues($pid, $record, array_keys($all), $event_id, $instrument, $repeat_instance, true, $resolution);
                } catch (\Throwable $e) {
                    $values = false;
                }
            }
            if ($values === false) {
                // nothing is known to lock: the field stays open, and the note says why
                $r['writeonceValuesUnread'] = true;
                $out[] = $r;
                continue;
            }
            if ($codes === null) $codes = $this->missingDataCodes($pid);
            $locked = [];
            foreach ($fields as $f) {
                if ((isset($resolution[$f]) ? $resolution[$f] : 'ok') !== 'ok') continue;
                $v = isset($values[$f]) ? $values[$f] : '';
                if (is_array($v) || trim((string) $v, " \t\r\n") === '' || self::isMissingCode($v, $codes)) continue;
                $locked[] = $f;
            }
            if (!$locked) continue;
            $r['fields'] = $locked;
            // A dropdown or SQL field whose saved code is no longer a choice
            // shows blank: the page gets the code to put back as an option
            // (QRIDWriteOnceInit). Only for a choice list: a text box shows the
            // value in its own format (a date as d-m-Y), not as saved.
            $dd = $this->dataDictionary($pid) ?: [];
            foreach ($locked as $f) {
                $ft = isset($dd[$f]['field_type']) ? $dd[$f]['field_type'] : '';
                if ($ft === 'dropdown' || $ft === 'sql') $r['writeonceSaved'][$f] = (string) $values[$f];
            }
            $out[] = $r;
        }
        return $out;
    }

    /**
     * @UVWRITEONCE "field" hook: a field whose value REDCap computes
     * (@CALCTEXT, @CALCDATE) changes whenever its inputs do, and one with
     * @SETVALUE (or its alias @PREFILL) is written again each time its form or
     * survey page opens, and one with @DOWNLOAD-COUNT each time its file is
     * downloaded, so none of them can be locked. Nor can a field whose
     * widget keeps its own copy of the text: the rich text editor (@RICHTEXT)
     * writes its copy into the field when the form is saved, after the save
     * guard has run, and an ontology search box (BioPortal, FHIR) does the
     * same with its pick.
     */
    private function annotateWriteonceField(array $frag, $name, array $meta, $pid)
    {
        $ann = isset($meta['field_annotation']) ? (string) $meta['field_annotation'] : '';
        if (preg_match('/@(CALCTEXT|CALCDATE)\b/i', $ann, $m)) {
            return ['error' => AnnotationRules::TAG_WRITEONCE . ' cannot lock a field with @' . strtoupper($m[1])
                . ' — REDCap works its value out again whenever the fields it reads change.', '_tag' => AnnotationRules::TAG_WRITEONCE];
        }
        if (preg_match('/@(SETVALUE|PREFILL)\b/i', $ann, $m)) {
            return ['error' => AnnotationRules::TAG_WRITEONCE . ' cannot lock a field with @' . strtoupper($m[1])
                . ' — REDCap writes its value again each time the form or survey page opens.', '_tag' => AnnotationRules::TAG_WRITEONCE];
        }
        if (preg_match('/@RICHTEXT\b/i', $ann)) {
            return ['error' => AnnotationRules::TAG_WRITEONCE . ' cannot lock a field with @RICHTEXT'
                . ' — the rich text editor keeps its own copy of the text and saves it over the field.', '_tag' => AnnotationRules::TAG_WRITEONCE];
        }
        if (preg_match('/@DOWNLOAD-COUNT\b/i', $ann)) {
            return ['error' => AnnotationRules::TAG_WRITEONCE . ' cannot lock a field with @DOWNLOAD-COUNT'
                . ' — REDCap adds one to it each time someone downloads the file it counts.', '_tag' => AnnotationRules::TAG_WRITEONCE];
        }
        // A Text field's choices hold "SERVICE:CATEGORY" when it searches an
        // ontology (BIOPORTAL:, FHIR: and any other service REDCap adds).
        $enum = isset($meta['select_choices_or_calculations']) ? ltrim((string) $meta['select_choices_or_calculations']) : '';
        if (isset($meta['field_type']) && $meta['field_type'] === 'text' && preg_match('/^[A-Za-z][A-Za-z0-9_-]*:/', $enum)) {
            return ['error' => AnnotationRules::TAG_WRITEONCE . ' cannot lock a field that searches an ontology'
                . ' — its search box keeps its own text and saves it over the field.', '_tag' => AnnotationRules::TAG_WRITEONCE];
        }
        return $frag;
    }

    /**
     * @UVWRITEONCE "dictionary" hook: every role it names must be a role of
     * this project, by the name the User Rights page shows or by its unique
     * role name (compared as Logic::roleKey: any letter case, spaces folded),
     * so a misspelt role does not lock out the people it names.
     * Roles that cannot be read are not refused here (the page then locks
     * the field for everyone and the audit says when it needed them).
     */
    private function annotateWriteonceDictionary(array $frag, $name, $types, $choices, $pid = null)
    {
        $roles = isset($frag['writeonceRoles']) && is_array($frag['writeonceRoles']) ? $frag['writeonceRoles'] : [];
        if (!$roles || $pid === null) return $frag;
        $book = $this->roleBook($pid);
        if ($book === null) return $frag;
        $unknown = [];
        foreach ($roles as $role) if (!isset($book['roles'][Logic::roleKey($role)])) $unknown[] = (string) $role;
        if (!$unknown) return $frag;
        return ['error' => (count($unknown) === 1
            ? '"' . $unknown[0] . '" is not a role of this project'
            : '"' . implode('", "', $unknown) . '" are not roles of this project')
            . ' — use the role name the User Rights page shows, or its unique role name.', '_tag' => AnnotationRules::TAG_WRITEONCE];
    }

    /**
     * @UVJUSTIFY "field" hook: the reason cannot be the field itself.
     */
    private function annotateJustifyField(array $frag, $name, array $meta, $pid)
    {
        if (isset($frag['justifyReason']) && $frag['justifyReason'] === $name) {
            return ['error' => '"reason" names this field itself — name the field where the reason is written, e.g. "[' . $name . '_reason]".',
                '_tag' => AnnotationRules::TAG_JUSTIFY];
        }
        return $frag;
    }

    /**
     * @UVJUSTIFY "dictionary" hook: the reason field must exist, sit on the
     * same form as the field, so the reason is given where the value is, and
     * take a reason: a Text or Notes field, a dropdown, radio or SQL field.
     * It must also be a field only the person fills in, since any value in it
     * stands for every broken value of the field: not the record ID field,
     * not one REDCap fills in itself (JUSTIFY_FILLED_TAGS), not a read-only
     * one, not one bare @HIDDEN hides on every page (@HIDDEN-FORM, -SURVEY,
     * -APP and -PDF hide it in one place, and a hidden row gives no reason on
     * the page), not one with a @UVJUSTIFY of its own, which would let two
     * fields stand for each other, and not a write-once one, since a reason
     * must be cleared when the value changes for another reason. A tag name
     * inside another tag's value is text (AnnotationRules::maskValues). A
     * field another rule returns a value to is refused in applyJustify, which
     * sees every rule.
     */
    private function annotateJustifyDictionary(array $frag, $name, $types, $choices, $pid = null)
    {
        $reason = isset($frag['justifyReason']) ? (string) $frag['justifyReason'] : '';
        $dd = $this->dataDictionary($pid) ?: [];
        if ($reason === '' || !$dd) return $frag;
        $err = function ($why) { return ['error' => $why, '_tag' => AnnotationRules::TAG_JUSTIFY]; };
        if (!isset($dd[$reason])) return $err('"reason" names "[' . $reason . ']", which is not a field of this project.');
        $form = isset($dd[$name]['form_name']) ? (string) $dd[$name]['form_name'] : '';
        $there = isset($dd[$reason]['form_name']) ? (string) $dd[$reason]['form_name'] : '';
        if ($form !== $there) {
            return $err('"reason" names "[' . $reason . ']", which is on the form "' . $there . '", not on "' . $form
                . '" with this field — the reason is given on the form where the value is entered.');
        }
        $type = isset($dd[$reason]['field_type']) ? (string) $dd[$reason]['field_type'] : '';
        if (!in_array($type, self::JUSTIFY_REASON_TYPES, true)) {
            return $err('"reason" names "[' . $reason . ']", a "' . $type . '" field — the reason is written in a Text or Notes field, or picked in a dropdown, radio or SQL field.');
        }
        if ($reason === $this->recordIdFieldOf($pid)) {
            return $err('"reason" names "[' . $reason . ']", the record ID field — it is never blank, so it would release every broken value; name a field the person fills in.');
        }
        $ann = AnnotationRules::maskValues(isset($dd[$reason]['field_annotation']) ? (string) $dd[$reason]['field_annotation'] : '');
        $filled = implode('|', array_map(function ($t) { return preg_quote($t, '/'); }, self::JUSTIFY_FILLED_TAGS));
        if (preg_match('/@(' . $filled . ')(?![\w-])/i', $ann, $m)) {
            return $err('"reason" names "[' . $reason . ']", which has @' . strtoupper($m[1])
                . ' — REDCap fills it in, so it would hold a reason nobody wrote; name a field the person fills in.');
        }
        if (preg_match('/@(READONLY(?:-[A-Z]+)?)(?![\w-])/i', $ann, $m)) {
            return $err('"reason" names "[' . $reason . ']", which has @' . strtoupper($m[1])
                . ' — the person cannot write a reason there; name a field the person fills in.');
        }
        if (preg_match('/@HIDDEN(?![\w-])/i', $ann)) {
            return $err('"reason" names "[' . $reason . ']", which has @HIDDEN'
                . ' — the field is hidden on every page, so the person cannot write a reason there; name a field the person fills in.');
        }
        if (preg_match('/' . preg_quote(AnnotationRules::TAG_JUSTIFY, '/') . '(?![\w-])/i', $ann)) {
            return $err('"reason" names "[' . $reason . ']", which carries ' . AnnotationRules::TAG_JUSTIFY
                . ' itself — the two fields could stand for each other; name a field that only holds the reason.');
        }
        if (preg_match('/' . preg_quote(AnnotationRules::TAG_WRITEONCE, '/') . '(?![\w-])/i', $ann)) {
            return $err('"reason" names "[' . $reason . ']", which carries ' . AnnotationRules::TAG_WRITEONCE
                . ' — a saved reason could not be cleared when the value changes for another reason; name a field the person can clear.');
        }
        return $frag;
    }

    /** The field types a @UVJUSTIFY reason may be (annotateJustifyDictionary). */
    const JUSTIFY_REASON_TYPES = ['text', 'notes', 'dropdown', 'radio', 'sql'];

    /**
     * The action tags with which REDCap fills a field in itself, so it never
     * holds a reason (annotateJustifyDictionary). Add a tag here to refuse it.
     * @UVWRITEONCE's lockReadsLate keeps a shorter list on purpose: the page
     * takes the values lockReads compares after REDCap's start-up, so it
     * leaves out only the tags REDCap fills later (@LATITUDE, @LONGITUDE).
     */
    const JUSTIFY_FILLED_TAGS = ['CALCTEXT', 'CALCDATE', 'DEFAULT', 'SETVALUE', 'PREFILL',
        'NOW-SERVER', 'NOW-UTC', 'NOW', 'TODAY-SERVER', 'TODAY-UTC', 'TODAY',
        'APPUSERNAME-APP', 'USERNAME', 'LATITUDE', 'LONGITUDE', 'DOWNLOAD-COUNT',
        'LANGUAGE-CURRENT-FORM', 'LANGUAGE-CURRENT-SURVEY'];

    /**
     * Justify mode (@UVJUSTIFY) finds nothing of its own: it marks the
     * findings of the field's other rules (markJustified).
     */
    private function findingsJustify(array $rule, $type, array $values, array $dupes, $onForm, $project_id, $record, $event_id, array $resolution, array $meta = [])
    {
        return ['invalid' => [], 'unconfigurable' => []];
    }

    /**
     * Whether $user holds one of $roles in project $pid: true, false (also for
     * a user with no rights row in the project, such as a survey respondent or
     * an administrator who was never added), or null when the roles cannot be
     * read. Role names compare as Logic::roleKey; usernames without letter case.
     */
    private function userHoldsRole($pid, $user, array $roles)
    {
        if (!$roles) return false;
        $book = $this->roleBook($pid);
        if ($book === null) return null;
        $u = strtolower(trim((string) $user));
        if ($u === '' || !isset($book['users'][$u])) return false;
        foreach ($roles as $role) {
            if (in_array(Logic::roleKey($role), $book['users'][$u], true)) return true;
        }
        return false;
    }

    /**
     * The project's user roles, once per request (RoleBook::read): null when
     * they cannot be read. Rights that expire are compared with today on
     * PHP's clock, as REDCap compares them.
     */
    private function roleBook($pid)
    {
        if ($this->roleBookOverride !== null) return $this->roleBookOverride === false ? null : $this->roleBookOverride;
        $key = (string) (int) $pid;
        if (array_key_exists($key, $this->roleBookMemo)) return $this->roleBookMemo[$key];
        $today = (new \DateTimeImmutable('now'))->format('Y-m-d');
        return $this->roleBookMemo[$key] = RoleBook::read(new Scan\ModuleDb($this), $pid, $today);
    }

    /**
     * The project's log reader for @UVWRITEONCE (ValueHistory), once per
     * request, keeping the fields the project's write-once rules lock; false
     * when the log cannot be resolved.
     */
    private function valueHistory($pid)
    {
        if ($this->valueHistoryOverride !== null) return $this->valueHistoryOverride;
        $key = (string) (int) $pid;
        if (!array_key_exists($key, $this->valueHistoryMemo)) {
            $fields = [];
            foreach ($this->getRules($pid) as $r) {
                if (!is_array($r) || (isset($r['type']) ? $r['type'] : '') !== 'writeonce') continue;
                foreach ((isset($r['fields']) && is_array($r['fields'])) ? $r['fields'] : [] as $f) $fields[] = (string) $f;
            }
            $server = new \DateTimeZone(date_default_timezone_get());
            $zone = $this->clockZone($pid);
            $h = ValueHistory::forProject(new Scan\ModuleDb($this), $pid, $fields, $server, $zone === null ? $server : $zone,
                $this->valueHistoryOptions($pid));
            $this->valueHistoryMemo[$key] = $h === null ? false : $h;
        }
        return $this->valueHistoryMemo[$key];
    }

    /**
     * What ValueHistory needs to know of the project: the data dictionary's
     * order, in which the log writes a save's fields, and its Text and Notes
     * fields, whose typed value may look like more lines
     * (ValueHistory::parseRow); the record id field, whose line in an INSERT
     * is the record's start; and whether a DELETE may cover one arm only: a project of
     * several arms, or one whose arms cannot be read.
     */
    private function valueHistoryOptions($pid)
    {
        $dd = $this->dataDictionary($pid) ?: [];
        $arms = [];
        $events = $this->temporalShape($pid, $dd)->events();
        foreach ($events as $e) $arms[isset($e['arm']) ? (string) $e['arm'] : '?'] = true;
        $free = [];
        foreach ($dd as $f => $m) {
            $t = isset($m['field_type']) ? (string) $m['field_type'] : '';
            if ($t === 'text' || $t === 'notes') $free[(string) $f] = true;
        }
        $id = $this->recordIdFieldOf($pid);
        return ['order' => array_flip(array_map('strval', array_keys($dd))), 'freeText' => $free,
                'recordIdField' => $id === '' ? null : $id,
                'multiArm' => !$events || count($arms) !== 1 || isset($arms['?'])];
    }

    /** What a finding carries for the scan (php/modes.json findingKeys "scan"), as violation key => text. */
    private static function scanFindingKeys(array $v)
    {
        $out = [];
        foreach (ModeRegistry::findingKeys() as $fk => $to) {
            if (isset($to['scan'], $v[$fk]) && is_scalar($v[$fk]) && !isset($out[$to['scan']])) $out[$to['scan']] = (string) $v[$fk];
        }
        return $out;
    }

    /**
     * The inputs of a growth-reference range rule, read for one entry:
     * ['state' => 'ok', 'entry', 'table', 'sex' => 'male'|'female', 'x',
     * 'yAdd' (added to the measurement before it is scored)], or
     * ['state' => 'inert', 'why'] when an input is blank (or a Missing Data
     * Code) or unreadable, the sex code is neither "male" nor "female", or the
     * age, length or height is outside the reference, or ['problem' => why]
     * when an input could not be resolved or the reference cannot be read.
     * What the inputs mean is GrowthReference::inputsFor.
     */
    private function growthInputs(array $rule, array $values, array $resolution, $pid)
    {
        $codes = $this->missingDataCodes($pid);
        $read = [];
        foreach (ModeRegistry::operandKeys() as $rk) {
            $k = $rk['key'];
            if (!isset($rule[$k])) continue;
            if (array_key_exists($rk['value'], $rule)) {
                // compiled on the extended (event/instance) path, which resolved it
                $v = $rule[$rk['value']];
                if (!is_string($v)) return ['problem' => 'the input ' . $rule[$k] . ' could not be resolved — field skipped'];
            } else {
                $op = ModeRegistry::operandRef($rule[$k]);
                if ($op === null) return ['problem' => 'the input ' . $rule[$k] . ' cannot be evaluated — field skipped'];
                $state = isset($resolution[$op[1]]) ? $resolution[$op[1]] : 'ok';
                if ($state !== 'ok') return ['problem' => 'the input ' . self::resolutionProblem($state, $op[1])];
                $v = isset($values[$op[1]]) ? $values[$op[1]] : '';
                $v = is_array($v) ? '' : (string) $v;
            }
            // the browser's trim set (QRID_whenTrim): \v or \0 is not whitespace there
            $read[$k] = self::isMissingCode($v, $codes) ? '' : trim($v, " \t\r\n");
        }
        $dir = $this->referenceDir();
        $entry = GrowthReference::entry($rule['rangeReference'], $dir);
        if ($entry === null) {
            $why = GrowthReference::unavailableWhy($rule['rangeReference'], $dir);
            return ['problem' => 'the growth reference "' . $rule['rangeReference'] . '" is not available on this server'
                . ($why !== null ? ' (' . $why . ')' : '') . ' — field skipped'];
        }
        try {
            $table = GrowthReference::table($entry);
        } catch (\Throwable $e) {
            return ['problem' => 'the growth reference "' . $rule['rangeReference'] . '" cannot be used: ' . $e->getMessage()];
        }
        $in = GrowthReference::inputsFor($rule, $read, $entry);
        if ($in['state'] !== 'ok') return $in;
        return ['state' => 'ok', 'entry' => $entry, 'table' => $table, 'sex' => $in['sex'], 'x' => $in['x'], 'yAdd' => $in['yAdd']];
    }

    /**
     * The folder of extra growth references an administrator named in the
     * system setting "reference-data-dir", or null (the bundled ones only).
     */
    private function referenceDir()
    {
        try {
            $dir = $this->getSystemSetting('reference-data-dir');
        } catch (\Throwable $e) {
            return null;
        }
        return (is_string($dir) && trim($dir) !== '') ? trim($dir) : null;
    }

    /** @var array|null a pinned clock (tests); null reads the real one */
    private $clockOverride = null;
    /** Tests set a log reader here (ValueStamps or an object with savedAt()). */
    private $valueStampsOverride = null;
    /** Tests set the @UVWRITEONCE log reader here (ValueHistory, an object with saves() and logTimeOf(), or false). */
    private $valueHistoryOverride = null;
    /** pid => ValueHistory|false */
    private $valueHistoryMemo = [];
    /** Tests set the user roles here (roleBook's shape, or false for "cannot be read"). */
    private $roleBookOverride = null;
    /** pid => roleBook()|null */
    private $roleBookMemo = [];
    /** pid => ValueStamps|null */
    private $valueStampsMemo = [];
    /** @var array zone name ('' = the server's own) => that clock, read once per request */
    private $clockMemo = [];

    /**
     * Today and now for the @UVWINDOW "notFuture" check, read once per request
     * so every field of one save, page or scan request is judged against the
     * same instant. The zone is the project's "window-timezone" setting, else
     * the server's own (the zone REDCap stamps its dates in). A project whose
     * sites sit ahead of the server sets it, or "now" typed there is "future".
     *
     * @return array{today:string, now:string}  'Y-m-d' and 'Y-m-d H:i:s'
     */
    private function serverClock($pid = null)
    {
        if ($this->clockOverride !== null) return $this->clockOverride;
        $zone = $this->clockZone($pid);
        $key = $zone === null ? '' : $zone->getName();
        if (!isset($this->clockMemo[$key])) {
            $now = $zone === null ? new \DateTimeImmutable('now') : new \DateTimeImmutable('now', $zone);
            $this->clockMemo[$key] = self::clockStamp($now);
        }
        return $this->clockMemo[$key];
    }

    /**
     * The clock for one moment: its wall-clock day and time, plus what the page
     * needs to keep that time right while it stays open (QRID_clockAt): the
     * moment in UTC milliseconds, the zone's offset from UTC in seconds, and the
     * zone's next offset change within a year, in UTC milliseconds, with the
     * offset after it (both null when there is none).
     */
    public static function clockStamp(\DateTimeImmutable $now)
    {
        $ts = (int) $now->format('U');
        $out = ['today' => $now->format('Y-m-d'), 'now' => $now->format('Y-m-d H:i:s'),
                'utc' => $ts * 1000 + (int) $now->format('v'), 'offset' => (int) $now->getOffset(),
                'next' => null, 'offsetAfter' => null];
        $changes = $now->getTimezone()->getTransitions($ts, $ts + 366 * 86400);
        if (is_array($changes)) {
            foreach ($changes as $c) {
                if ($c['ts'] > $ts && (int) $c['offset'] !== $out['offset']) {
                    $out['next'] = $c['ts'] * 1000;
                    $out['offsetAfter'] = (int) $c['offset'];
                    break;
                }
            }
        }
        return $out;
    }

    /** The project's "window-week-start" setting: sunday, else monday. */
    private function weekStart($pid)
    {
        $v = null;
        try { $v = $this->getProjectSetting('window-week-start', $pid); } catch (\Throwable $e) {}
        return $v === 'sunday' ? 'sunday' : 'monday';
    }

    /** The project's "window-timezone" setting as a zone; null for none or an unknown name. */
    private function clockZone($pid = null)
    {
        $name = null;
        try { $name = $this->getProjectSetting('window-timezone', $pid); } catch (\Throwable $e) {}
        $name = is_string($name) ? trim($name) : '';
        if ($name === '' || !self::isClockZone($name)) return null;
        return new \DateTimeZone($name);
    }

    /** Whether $name is a timezone the "window-timezone" setting accepts (an IANA name). */
    private static function isClockZone($name)
    {
        return is_string($name) && in_array($name, \DateTimeZone::listIdentifiers(\DateTimeZone::ALL), true);
    }

    /**
     * Check mode (@UVALIDATE, single|pooled): the check character and/or
     * format of each value, through the same verdict functions the browser
     * twins.
     */
    private function findingsCheck(array $rule, $type, array $values, array $dupes, $onForm, $project_id, $record, $event_id, array $resolution)
    {
        $out = ['invalid' => [], 'unconfigurable' => []];
        $algo    = isset($rule['algorithm']) && $rule['algorithm'] !== '' ? $rule['algorithm'] : 'iso7064_mod37_36';
        $source  = isset($rule['source']) && $rule['source'] !== '' ? $rule['source'] : 'normalized_id';
        $strip   = isset($rule['strip']) ? $rule['strip'] : "-/ _|\\";
        $pattern = isset($rule['idPattern']) ? $rule['idPattern'] : null;
        $unconfigurable = []; $notChecked = [];
        foreach ($rule['fields'] as $field) {
            if (isset($dupes[$field])) continue;
            if ($onForm !== null && !isset($onForm[$field])) continue;
            $value = isset($values[$field]) ? $values[$field] : null;
            // blank as the browser sees it: input.value.trim() === "" (VAL-3)
            if ($value === null || CheckCharacter::browserTrim($value) === '') continue;
            // ONE config array for both verdicts: the single and pooled twins
            // read the same keys, so there is no second argument list to keep in
            // step when a rule gains an option.
            $vcfg = [
                'algorithm'   => $algo, 'source' => $source, 'strip' => $strip,
                'idPattern'   => $pattern,
                'alternates'  => isset($rule['alternates']) ? $rule['alternates'] : null,
                'keepChars'   => isset($rule['keepChars']) ? $rule['keepChars'] : '',
                'idLengths'   => isset($rule['idLengths']) ? $rule['idLengths'] : null,
                'idMinLen'    => isset($rule['idMinLen']) ? $rule['idMinLen'] : null,
                'idMaxLen'    => isset($rule['idMaxLen']) ? $rule['idMaxLen'] : null,
                'expectedIds' => isset($rule['expectedIds']) ? $rule['expectedIds'] : null,
            ];
            $res = ($type === 'pooled')
                ? CheckCharacter::validatePooledField($vcfg, $value)
                : CheckCharacter::validateSingleField($vcfg, $value);
            if (isset($res['reason']) && $res['reason'] === 'unconfigurable') {
                // The rule cannot produce a trustworthy verdict (unsafe lengths,
                // uncompilable pattern, PCRE engine failure). Surface it instead
                // of treating it as valid — a silent pass is the one outcome an
                // auditor can never see (COR-002).
                $unconfigurable[] = $field;
            } elseif (isset($res['reason']) && $res['reason'] === 'not-checked') {
                // The server cannot say what the browser's RegExp makes of
                // this value: a character outside printable ASCII that the
                // pattern can match and the two engines read differently, or
                // a PCRE limit hit mid-match. Reported, never passed as valid
                // (VAL-1).
                $notChecked[] = $field;
            } elseif (empty($res['ok'])) {
                $out['invalid'][] = ['field' => $field, 'value' => $value, 'algo' => $algo, 'type' => $type,
                                     'reason' => isset($res['reason']) ? $res['reason'] : ''];
            }
        }
        if ($unconfigurable) {
            $out['unconfigurable'][] = ['fields' => $unconfigurable, 'why' => 'rule cannot be evaluated server-side (unsafe or uncompilable configuration)'];
        }
        if ($notChecked) {
            $out['unconfigurable'][] = ['fields' => $notChecked, 'why' => 'the server cannot tell how the browser reads this value against the format pattern (a character outside printable ASCII that the two regex engines read differently, or a regex engine limit); check the value by hand'];
        }
        return $out;
    }

    // -- logging ------------------------------------------------------------

    /** The project's log-privacy mode, resolved with the explicit hook PID. */
    private function logMode($pid)
    {
        try {
            $mode = $this->getProjectSetting('log-values', $pid);
            return ($mode === null || $mode === '') ? 'hashed' : $mode;
        } catch (\Throwable $e) {
            return 'hashed'; // never let a settings read decide between logging raw and not logging
        }
    }

    /** Whether verbose diagnostic detail may be logged (admin opt-in per project). */
    private function debugEnabled($pid)
    {
        try {
            return (bool) $this->getProjectSetting('debug-log', $pid);
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Module-held secret for keyed hashing, generated once and stored as a
     * system setting. A plain unsalted SHA-256 of a low-entropy study ID is
     * enumerable offline and links the same value across projects; an
     * HMAC with a server-held key keeps within-project repeat correlation (the
     * stated purpose) without either property (SEC-004).
     */
    private function hmacKey()
    {
        if ($this->hmacKey !== false) return $this->hmacKey;
        $this->hmacKey = null;
        try {
            $key = $this->getSystemSetting('log-hmac-key');
            if (!is_string($key) || strlen($key) < 64) {
                $key = bin2hex(random_bytes(32));
                $this->setSystemSetting('log-hmac-key', $key);
            }
            $this->hmacKey = $key;
        } catch (\Throwable $e) {
            // System settings unavailable: identifiers are OMITTED below rather
            // than falling back to an unkeyed hash an attacker could enumerate.
        }
        return $this->hmacKey;
    }

    /** Project-scoped keyed hash of an identifier, or null when no key exists. */
    private function hashedIdentifier($pid, $value)
    {
        $key = $this->hmacKey();
        if ($key === null) return null;
        return hash_hmac('sha256', (string) $value, $key . '|' . (string) $pid);
    }

    /**
     * Record an invalid value found on the server. The "log-values" project
     * setting controls how much identifying material the entry carries (UV-005):
     *   hashed (default) — value as project-keyed HMAC, record ID raw (staff can
     *                      fix the record)
     *   none   (strict)  — value omitted AND record ID as keyed HMAC, for sites
     *                      where record IDs are themselves participant identifiers
     *   raw              — value and record ID raw (explicit opt-in)
     *   off              — no server-side detection logging at all
     * Field / instrument / event / instance are logged in every mode except off.
     * A keyed hash is pseudonymization, not anonymity: treat the module log as
     * identifying data for access/retention purposes (see README).
     */
    private function logInvalid($mode, $pid, $record, $field, $value, $algo, $type, $instrument, $event_id, $repeat_instance, $reason, array $extra = [], $message = 'invalid-id-saved')
    {
        if ($mode === 'off') return; // detection logging disabled entirely
        $entry = [
            'field'      => (string) $field,
            'type'       => (string) $type,
            'algorithm'  => (string) $algo,
            'reason'     => (string) $reason,
            'instrument' => (string) $instrument,
            'event_id'   => (string) $event_id,
            'instance'   => (string) ($repeat_instance ?: 1),
        ];
        // as_of: the day a today-relative @UVWINDOW part was judged against.
        foreach ($extra as $k => $v) if (!isset($entry[$k])) $entry[$k] = (string) $v;
        if ($mode === 'none') {
            $h = $this->hashedIdentifier($pid, (string) $record);
            if ($h !== null) $entry['record_hmac'] = $h;
            else $entry['hmac_unavailable'] = '1';
        } else {
            $entry['record'] = (string) $record;
        }
        if ($mode === 'raw') {
            $entry['value'] = $value;
        } elseif ($mode !== 'none') {
            $h = $this->hashedIdentifier($pid, (string) $value);
            if ($h !== null) $entry['value_hmac'] = $h;
            else $entry['hmac_unavailable'] = '1';
        }
        $this->log($message, $entry);
    }

    /**
     * The live uniqueness check has no transport on this page — operational
     * signal, no identifiers. Without it @UVUNIQUE cannot check anything in the
     * browser (it fails open and never traps a save), so this must be visible
     * rather than silent: a project would otherwise believe duplicates were
     * being caught live when nothing was happening. The post-save audit and the
     * Validation scan still catch duplicates either way.
     */
    private function logNoUniqueTransport($why, $instrument, $context)
    {
        try {
            $this->log('uvalidate-no-unique-transport', [
                'why'        => (string) $why,
                'instrument' => (string) $instrument,
                'context'    => (string) $context,
                'effect'     => 'the live duplicate check is inert on this page; the post-save audit and the Validation scan still apply',
            ]);
        } catch (\Throwable $ignored) {
        }
    }

    /** A rule the server could not evaluate — operational signal, no identifiers. */
    /**
     * A value a growth rule could not work out a z-score for (a blank or
     * unreadable input, a sex code that is neither code, an age outside the
     * reference): not a finding and not a rule problem, but a project missing
     * its dates of birth should not read as one with no findings. Holds no
     * value; the record follows the project's log mode, as a finding's does.
     */
    private function logNotEvaluated($mode, $pid, $record, $ruleIndex, array $u, $instrument, $event_id, $repeat_instance)
    {
        if ($mode === 'off') return;
        try {
            $entry = [
                'rule'       => (string) ($ruleIndex + 1),
                'field'      => (string) $u['field'],
                'why'        => (string) $u['why'],
                'instrument' => (string) $instrument,
                'event_id'   => (string) $event_id,
                'instance'   => (string) ($repeat_instance ?: 1),
            ];
            if (isset($u['reference'])) $entry['reference'] = (string) $u['reference'];
            if ($mode === 'none') {
                $h = $this->hashedIdentifier($pid, (string) $record);
                if ($h !== null) $entry['record_hmac'] = $h;
                else $entry['hmac_unavailable'] = '1';
            } else {
                $entry['record'] = (string) $record;
            }
            $this->log('uvalidate-not-evaluated', $entry);
        } catch (\Throwable $ignored) {
        }
    }

    private function logUnconfigurable($ruleIndex, array $fields, $why, $instrument, $event_id, $repeat_instance)
    {
        try {
            $this->log('uvalidate-unconfigurable', [
                'rule'       => (string) ($ruleIndex + 1),
                'fields'     => implode(', ', $fields),
                'why'        => (string) $why,
                'instrument' => (string) $instrument,
                'event_id'   => (string) $event_id,
                'instance'   => (string) ($repeat_instance ?: 1),
            ]);
        } catch (\Throwable $ignored) {
        }
    }

    /**
     * An audit failure, logged with the SAME privacy posture the project chose
     * for detections: raw record only in hashed/raw modes, keyed HMAC in strict
     * mode, and NO record identifier at all in off mode (the entry itself is
     * still written — it is operational, not a detection). Exception messages
     * can embed data values, so the message text is only included when the
     * project's debug setting is on; class + file:line are always safe.
     */
    private function logAuditError($mode, $pid, $record, $instrument, \Throwable $e, $stage)
    {
        try {
            $entry = [
                'stage'      => (string) $stage,
                'instrument' => (string) $instrument,
                'error'      => get_class($e),
                'where'      => basename($e->getFile()) . ':' . $e->getLine(),
            ];
            if ($mode === 'none') {
                $h = $this->hashedIdentifier($pid, (string) $record);
                if ($h !== null) $entry['record_hmac'] = $h;
            } elseif ($mode !== 'off') {
                $entry['record'] = (string) $record;
            }
            if ($this->debugEnabled($pid)) {
                $entry['detail'] = substr((string) $e->getMessage(), 0, 500);
            }
            $this->log('uvalidate-audit-error', $entry);
        } catch (\Throwable $ignored) {
            // logging itself failed — nothing more we can safely do
        }
    }

    // -- client injection ---------------------------------------------------

    private function injectClient($pid = null, $context = 'form', $record = null, $instrument = null, $event_id = null, $repeat_instance = 1)
    {
        $config = $this->buildClientConfig($pid, $context, $record, $instrument, $event_id, $repeat_instance);
        if (empty($config['rules'])) return; // nothing configured for this project
        $engineUrl = $this->getUrl('js/engine.js');
        // Live uniqueness (@UVUNIQUE) needs a transport: the framework's
        // JavaScript Module Object (module.ajax, CSRF-protected, survey-aware).
        // Initialized only when a unique rule is live, so other pages carry no
        // extra script.
        //
        // is_callable, NOT method_exists: the External Modules framework exposes
        // these through AbstractExternalModule::__call(), and method_exists()
        // returns FALSE for a magic-proxied method. Guarding with method_exists
        // silently skipped this whole block on a real REDCap — no exception, no
        // jsmoName, @UVUNIQUE inert in production — while every mocked test
        // passed, because the test stub declares the methods for real. Found on
        // pid 149, v1.4.0. is_callable() honours __call(), so it is true in both
        // shapes.
        //
        // A missing transport is now LOGGED, not swallowed: the module's rule is
        // that nothing fails silently, and the old empty catch hid exactly the
        // diagnosis this bug needed. The client still fails open (never traps a
        // save) and the post-save audit remains the net.
        if (ModeRegistry::rulesNeed($config['rules'], 'transport')) {
            $why = null;
            try {
                if (is_callable([$this, 'initializeJavascriptModuleObject'])) {
                    $js = $this->initializeJavascriptModuleObject();
                    // Older framework builds echo the bootstrap themselves and
                    // return null; newer ones hand back the markup. Support both.
                    if (is_string($js) && $js !== '') echo $js . "\n";
                } else {
                    $why = 'the framework does not expose initializeJavascriptModuleObject()';
                }
                $name = is_callable([$this, 'getJavascriptModuleObjectName'])
                    ? $this->getJavascriptModuleObjectName() : null;
                if (is_string($name) && $name !== '') $config['jsmoName'] = $name;
                elseif ($why === null) $why = 'the framework returned no JavaScript module object name';
            } catch (\Throwable $e) {
                $why = 'the framework threw ' . get_class($e) . ' while initializing the JavaScript module object';
            }
            if ($why !== null) $this->logNoUniqueTransport($why, $instrument, $context);
        }
        // Embed the config as INERT JSON (not executable JS); the engine parses
        // this element itself, so no config global is ever written. The hex
        // flags escape < > & ' " to \uXXXX and the default slash escaping is
        // kept (JSON_UNESCAPED_SLASHES is deliberately NOT used), so no project
        // setting — pattern, strip, keepChars — can close the <script> element
        // or inject markup. Fixes the stored-XSS breakout (UV-001).
        $flags = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE;
        $json = json_encode($config, $flags);
        if ($json === false) {
            // The payload carries SAVED VALUES since cross-form literals (1.6.0)
            // and event/instance snapshots, so one stored value that is not
            // valid UTF-8 (legacy imports) made json_encode fail and the early
            // return below then removed EVERY rule from the page in silence.
            // Only the rule that cannot be encoded is given up, and visibly.
            $config['rules'] = self::encodableRules($config['rules'], $flags, $context === 'survey');
            $json = json_encode($config, $flags);
        }
        if ($json === false) return; // never inject malformed config
        echo '<script type="application/json" id="inspire-validator-config">'
            . $json . '</script>' . "\n";
        echo '<script src="' . htmlspecialchars($engineUrl, ENT_QUOTES) . '"></script>' . "\n";
    }

    /**
     * choicesAll is the FIELD's full code list, so every branch of one choices
     * rule carries the same copy: a 2,000-option field with 50 @UVCHOICES tags
     * put 767 KB of repeated codes on every page load. It travels once on the
     * rule and the engine hands it to each branch. Only when EVERY branch
     * agrees, so a branch that lacks the list still fails visibly in the engine
     * instead of inheriting one it was never given.
     */
    private static function hoistChoicesAll(array $rules)
    {
        foreach ($rules as $i => $r) {
            if (!is_array($r) || empty($r['branches']) || !is_array($r['branches']) || count($r['branches']) < 2) continue;
            $all = null;
            foreach ($r['branches'] as $b) {
                if (!is_array($b) || !isset($b['choicesAll']) || !is_array($b['choicesAll'])
                    || ($all !== null && $b['choicesAll'] !== $all)) continue 2;
                $all = $b['choicesAll'];
            }
            $rules[$i]['choicesAll'] = $all;
            foreach ($r['branches'] as $bi => $b) unset($rules[$i]['branches'][$bi]['choicesAll']);
        }
        return $rules;
    }

    /**
     * Replace each rule json_encode() refuses with the deferred stub the engine
     * already renders for an unreadable source, leaving every other rule live.
     * The stub keeps only the rule's type and field names, which are designer
     * configuration and ASCII by validation. It runs after buildClientConfig,
     * so on a survey it says nothing of why (ModeRegistry::withoutSurveyTexts).
     */
    private static function encodableRules(array $rules, $flags, $survey = false)
    {
        foreach ($rules as $i => $rule) {
            if (!is_array($rule) || json_encode($rule, $flags) !== false) continue;
            $stub = [
                'type' => isset($rule['type']) && is_string($rule['type']) ? $rule['type'] : 'single',
                'fields' => array_values(array_filter(
                    isset($rule['fields']) && is_array($rule['fields']) ? $rule['fields'] : [],
                    function ($f) { return is_string($f) && preg_match('/^[a-z][a-z0-9_]*$/D', $f); }
                )),
                'deferred' => true,
                'deferredWhy' => ['A saved value this rule reads is not valid UTF-8 text, so the rule cannot run in the browser.'],
                'blockSave' => 'off',
            ];
            $rules[$i] = $survey ? ModeRegistry::withoutSurveyTexts($stub) : $stub;
        }
        return $rules;
    }

    /** Build the engine's config object from module settings. */
    private function buildClientConfig($pid = null, $context = 'form', $record = null, $instrument = null, $event_id = null, $repeat_instance = 1)
    {
        $rules = $this->getRules($pid);
        // Inject only rules that touch the instrument being rendered — and only
        // their on-instrument fields. A rule whose field lives on another form can
        // never bind here (its field never appears in this page's DOM), yet each
        // injected rule installs its own document.body MutationObserver; a
        // rule-heavy project otherwise stacks one observer per PROJECT rule on
        // EVERY form, so a single DOM mutation fans out to all of them and freezes
        // the tab (PER-003, the 1.5.1 known perf issue). The post-save audit and the
        // Validation scan still cover every rule on every form.
        $rules = $this->rulesOnInstrument($rules, $pid, $instrument, $context === 'survey');

        $config = array_merge($this->defaults(), [
            'singleFields' => [],
            'pooledFields' => [],
            'context'      => $context,
            'rules'        => $rules,
        ]);

        $config['rules'] = $this->foldTemporalRules($rules, $pid, $record, $instrument, $event_id, $repeat_instance, $context);
        // A mode whose rules depend on the saved entry and on who is looking
        // (php/modes.json hooks.page) keeps, drops or narrows them here.
        foreach (ModeRegistry::all() as $m) {
            $hook = ModeRegistry::hook($m['mode'], 'page');
            if ($hook !== null) $config['rules'] = $this->$hook($config['rules'], $pid, $context, $record, $instrument, $event_id, $repeat_instance);
        }
        $config['rules'] = self::markLockReads($config['rules'], $this->fieldsOnInstrument($pid, $instrument), $this->dataDictionary($pid) ?: [],
            ($pid !== null && $event_id !== null) ? $this->temporalShape($pid) : null, $event_id, $repeat_instance);
        // _origin is ours, and it stops here. Not a disclosure - 'settings' or
        // 'annotation' tells a reader nothing - but this payload is built per
        // page and per rule, and an unexplained key in the engine's input is
        // what a future strict-shape check rejects.
        foreach ($config['rules'] as $i => $r) {
            if (is_array($r) && array_key_exists('_origin', $r)) unset($config['rules'][$i]['_origin']);
        }
        $config['rules'] = self::hoistChoicesAll($config['rules']);
        // Where an @UVEXISTS lookup searches stays on the server: the endpoint
        // re-reads it from the stored rule, so the page never needs it.
        foreach ($config['rules'] as $i => $r) {
            if (is_array($r)) $config['rules'][$i] = ModeRegistry::clientShape($r, $context === 'survey');
        }
        // "Today" for @UVWINDOW notFuture is the SERVER's today, not the
        // computer's: a browser clock set a day ahead must not accept tomorrow's
        // date. Sent only when a rule on this page reads it.
        if (ModeRegistry::rulesNeed($config['rules'], 'clock')) $config['clock'] = $this->serverClock($pid);
        // On a survey, Previous Page and Save & Return Later save without
        // finishing the response; a rule that waits for a complete response
        // does not hold them (REQ-7).
        if ($context === 'survey' && $config['rules']) {
            $partial = ModeRegistry::partialSave();
            if ($partial !== null) {
                foreach ($config['rules'] as $r) {
                    if (is_array($r) && in_array($r['type'] ?? '', $partial['types'], true)) { $config['partialSave'] = $partial; break; }
                }
            }
        }
        // A record not saved yet carries REDCap's guess at its id. The live
        // uniqueness check must not skip a record another user saves under that
        // id meanwhile (UNQ-6), so the page says so. Unknown counts as saved:
        // the check then skips the record, as it always did.
        if ($record !== null && $record !== '' && $pid !== null) {
            foreach ($config['rules'] as $r) {
                if (!is_array($r) || ($r['type'] ?? '') !== 'unique') continue;
                $rd = $this->recordDagOf($pid, $record);
                if (is_array($rd) && $rd['found'] === false) $config['newRecord'] = true;
                break;
            }
        }
        // A form the person may only read, or a locked one, shows the record
        // as it is saved: the page fills no field a rule returns to (EXS-2).
        if ($context !== 'survey' && $record !== null && $record !== '' && $pid !== null) {
            foreach ($rules as $r) {
                if (!is_array($r) || !ModeRegistry::returnFields($r)) continue;
                if ($this->formReadOnly($pid, $record, $instrument, $event_id, $repeat_instance)) $config['formReadOnly'] = true;
                break;
            }
        }
        // The project's Missing Data Codes (codes only, never their labels): a
        // field marked with one holds the code, which the checks leave alone.
        if ($config['rules']) {
            $codes = $pid !== null ? $this->missingDataCodes($pid) : [];
            if ($codes) $config['missingCodes'] = array_map('strval', array_keys($codes));
        }
        // after the codes: a saved input holding one is blank (growthPageNeed)
        $config = $this->attachGrowthTables($config);
        // after the tables: a chart is drawn only where the page judges the rule
        $config = $this->attachGrowthCurves($config);
        // The date fields of this form shown day- or month-first: a condition on
        // the page compares the value REDCap stores (Y-M-D), as the server does.
        if ($config['rules'] && $pid !== null && is_string($instrument) && $instrument !== '') {
            $formats = $this->pageDateFormats($pid, $instrument);
            if ($formats) $config['dateFormats'] = $formats;
            // The fields of this form that hold numbers, with their decimal
            // mark: a condition on the page reads 12,5 as 12.5, as the server
            // does (conditionNumbers, AST-1).
            $marks = array_intersect_key($this->conditionNumbers($pid), $this->fieldsOnInstrument($pid, $instrument) ?: []);
            if ($marks) $config['numberMarks'] = $marks;
        }
        // The saved value of each field a today-relative @UVWINDOW part judges (a
        // "from" of today or now, notPast, notFuture). The page judges a "from"
        // of today or now and notPast only for a value that is new or changed:
        // a saved value is judged against the day it was saved, after saving
        // (findingsWindow). notFuture still flags an unchanged value after
        // today (it was after every earlier day too), but a pass shows no
        // green "OK": the value may have been in the future on its save day
        // (WIN-2). A field whose saved value could not be read, or is not
        // valid UTF-8, maps to null, and the page treats it as unchanged.
        $todayFields = self::windowTodayFields($config['rules']);
        if ($todayFields) {
            $config['windowSaved'] = $this->windowSavedValues($pid, $record, $instrument, $event_id, $repeat_instance, $todayFields);
        }
        // A survey page never shows why a rule is not checked on it, and a
        // step after clientShape (the growth table cap) may have said why:
        // this pass takes every such text off (surveyShape's "drop" keys).
        // The one step after it, encodableRules, leaves it off on a survey.
        if ($context === 'survey') {
            foreach ($config['rules'] as $i => $r) {
                if (is_array($r)) $config['rules'][$i] = ModeRegistry::withoutSurveyTexts($r);
            }
        }
        return $config;
    }

    /**
     * Whether this person sees $instrument of $record read only (EXS-2): their
     * rights to the form are "read only", or the form (this event and
     * instance) or the whole record is locked. REDCap then disables every
     * field, and a page that filled the returned fields would show values the
     * record does not hold. A lock that cannot be read counts as none: the
     * page fills as it did before.
     */
    private function formReadOnly($pid, $record, $instrument, $event_id, $instance)
    {
        if (!is_string($instrument) || $instrument === '') return false;
        $rights = $this->userFormRights($pid);
        if (is_array($rights) && isset($rights[$instrument]) && (string) $rights[$instrument] === '2') return true;
        $db = new Scan\ModuleDb($this);
        try {
            $r = $db->select('SELECT 1 FROM redcap_locking_data WHERE project_id = ? AND record = ? AND event_id = ?'
                . ' AND form_name = ? AND instance = ? LIMIT 1',
                [(int) $pid, (string) $record, (int) $event_id, $instrument, (int) ($instance ?: 1)]);
            if (is_array($r) && $r) return true;
        } catch (\Throwable $e) {
        }
        try {
            $r = $db->select('SELECT 1 FROM redcap_locking_records WHERE project_id = ? AND record = ? LIMIT 1',
                [(int) $pid, (string) $record]);
            if (is_array($r) && $r) return true;
        } catch (\Throwable $e) {
        }
        return false;
    }

    /** Whether a @UVWINDOW rule or branch has a part judged against today: a "from" of today or now, notPast or notFuture. */
    private static function windowTodayRelative(array $r)
    {
        $a = isset($r['windowAnchor']) ? $r['windowAnchor'] : null;
        return $a === 'today' || $a === 'now' || !empty($r['windowNotPast']) || !empty($r['windowNotFuture']);
    }

    /** The fields of the page's @UVWINDOW rules with a part judged against today. */
    private static function windowTodayFields(array $rules)
    {
        $out = [];
        foreach ($rules as $r) {
            if (!is_array($r) || !empty($r['configError']) || (isset($r['type']) ? $r['type'] : '') !== 'window') continue;
            $nodes = [$r];
            if (isset($r['branches']) && is_array($r['branches'])) foreach ($r['branches'] as $b) if (is_array($b)) $nodes[] = $b;
            foreach ($nodes as $n) {
                if (!self::windowTodayRelative($n)) continue;
                foreach ((isset($r['fields']) ? $r['fields'] : []) as $f) $out[(string) $f] = true;
            }
        }
        return array_keys($out);
    }

    /** field => saved value (null when it could not be read) for this entry; empty for a record not saved yet. */
    private function windowSavedValues($pid, $record, $instrument, $event_id, $repeat_instance, array $fields)
    {
        if ($record === null || $record === '' || $pid === null) return new \stdClass();
        $resolution = [];
        try {
            $values = $this->readValues($pid, $record, $fields, $event_id, $instrument, $repeat_instance, true, $resolution);
        } catch (\Throwable $e) {
            $values = [];
            foreach ($fields as $f) $resolution[$f] = 'unreadable';
        }
        $out = [];
        foreach ($fields as $f) {
            $ok = (isset($resolution[$f]) ? $resolution[$f] : 'ok') === 'ok';
            $v = isset($values[$f]) ? $values[$f] : '';
            // A value that is not valid UTF-8 would stop the whole page config
            // from encoding; it maps to null, as one that could not be read.
            $out[$f] = ($ok && !is_array($v) && preg_match('//u', (string) $v) === 1) ? (string) $v : null;
        }
        return $out;
    }

    /** field => [type, format] for the date and datetime fields of $instrument not shown as Y-M-D. */
    private function pageDateFormats($pid, $instrument)
    {
        $out = [];
        foreach (($this->dataDictionary($pid) ?: []) as $f => $meta) {
            if (!is_array($meta) || !isset($meta['form_name']) || $meta['form_name'] !== $instrument) continue;
            $tv = TemporalValue::fromValidation(self::validationOf($meta));
            if ($tv !== null && $tv['format'] !== 'ymd') $out[(string) $f] = [$tv['type'], $tv['format']];
        }
        return $out;
    }

    /**
     * The growth tables the @UVRANGE rules on this page read, as
     * config.growth: reference id => GrowthReference::pageCopy. Only the rows
     * the page can read travel (growthPageNeed): one sex's when the sex is a
     * saved value, a few rows when the age or length is, none for a branch
     * whose condition is settled false. The catalog's "pageTables" caps them,
     * counted by GrowthReference::pageWeight (a whole table is tens of
     * kilobytes). A rule, or a branch, that needs more, or whose reference can
     * no longer be read, is deferred with the reason (deferredOnSave): the
     * page still checks the measurement itself, and the post-save audit and
     * the scan check the rest.
     */
    private function attachGrowthTables(array $config)
    {
        $dir = null;
        $cap = null;
        $need = [];     // reference id => what the page reads of it
        $entries = [];  // reference id => [entry, table]
        foreach ($config['rules'] as $i => $r) {
            if (!is_array($r) || !empty($r['configError']) || !empty($r['deferred'])) continue;
            $branched = isset($r['branches']) && is_array($r['branches']);
            foreach ($branched ? $r['branches'] : [$r] as $bi => $n) {
                if (!is_array($n) || !isset($n['rangeReference']) || !is_string($n['rangeReference'])) continue;
                // An input withheld from this page means no z-score here: the
                // browser only judges the measurement itself, so the table
                // would be dead weight against the page cap. A branch deferred
                // for another reason is not judged here either.
                if ($this->anyOperandWithheld($n) || !empty($n['deferred'])) continue;
                // A branch whose condition the server settled false can never
                // apply on this page. It is marked idle, so the page does not
                // read the table it was not sent as one it lost (a
                // configuration error that would stop every reference on the
                // field: js/engine.js makeVariant).
                if (isset($n['whenAst']) && is_array($n['whenAst']) && self::settledFalse($n['whenAst'], $n)) {
                    if ($branched) $config['rules'][$i]['branches'][$bi]['growthIdle'] = true;
                    else $config['rules'][$i]['growthIdle'] = true;
                    continue;
                }
                if ($cap === null) {
                    $dir = $this->referenceDir();
                    $cap = GrowthReference::catalog($dir)['pageTables'];
                }
                $id = $n['rangeReference'];
                $why = null;
                if (!isset($entries[$id])) {
                    $entry = GrowthReference::entry($id, $dir);
                    if ($entry === null) {
                        $gone = GrowthReference::unavailableWhy($id, $dir);
                        $why = 'the growth reference "' . $id . '" is not available on this server' . ($gone !== null ? ' (' . $gone . ')' : '') . '.';
                    } else {
                        try {
                            $entries[$id] = [$entry, GrowthReference::table($entry)];
                        } catch (\Throwable $e) {
                            $why = 'the growth reference "' . $id . '" cannot be used: ' . $e->getMessage();
                        }
                    }
                }
                if ($why === null) {
                    $want = $this->growthPageNeed($n, $entries[$id][0], $config);
                    $merged = isset($need[$id]) ? self::mergePageNeed($need[$id], $want) : $want;
                    $before = 0.0;
                    foreach ($need as $nid => $nn) $before += GrowthReference::pageWeight($nn);
                    $after = $before - (isset($need[$id]) ? GrowthReference::pageWeight($need[$id]) : 0.0) + GrowthReference::pageWeight($merged);
                    if ($after > $cap + 1e-9) {
                        $num = function ($w) { return floor($w) == $w ? (string) (int) $w : rtrim(sprintf('%.1f', $w), '0'); };
                        $why = 'this form already carries the rows of ' . $num($before) . ' growth reference table'
                            . ($before == 1 ? '' : 's') . ', and this one would take it past the most one page may carry ('
                            . $cap . '), so this check runs when the record is saved'
                            . (empty($n['rangeReturn']) ? '' : ', and the fields it returns are filled then') . '.';
                    } else {
                        $need[$id] = $merged;
                    }
                }
                if ($why !== null) {
                    // The save's audit reads the table on the server:
                    // deferredOnSave tells the browser to say "checked when
                    // saved", not "not checked at all". A branch is built on
                    // its own in the browser, so only it is marked.
                    $mark = ['deferred' => true, 'deferredOnSave' => true, 'deferredWhy' => [$why]];
                    if ($branched) $config['rules'][$i]['branches'][$bi] = $mark + $config['rules'][$i]['branches'][$bi];
                    else $config['rules'][$i] = $mark + $config['rules'][$i];
                }
            }
        }
        $tables = [];
        foreach ($need as $id => $nn) {
            $full = $nn['xs'] === null && !empty($nn['sexes']['male']) && !empty($nn['sexes']['female']);
            $tables[$id] = GrowthReference::pageCopy($entries[$id][0], $entries[$id][1], $full ? null : $nn);
        }
        if ($tables) $config['growth'] = $tables;
        return $config;
    }

    /**
     * Whether a folded condition (Logic::fold) is false whatever the page
     * does: every comparison in it was settled on the server.
     */
    private static function settledFalse(array $ast, array $node)
    {
        $live = function ($a) use (&$live) {
            if (!is_array($a) || !isset($a[0])) return true;
            if ($a[0] === 'const') return false;
            if ($a[0] === 'not') return $live($a[1]);
            if ($a[0] === 'and' || $a[0] === 'or') {
                foreach ($a[1] as $c) if ($live($c)) return true;
                return false;
            }
            return true;   // a comparison the page reads, or a node not known here
        };
        if ($live($ast)) return false;
        return !Logic::evaluate($ast, [], Logic::BLANK_INERT, !empty($node['caseSensitive']));
    }

    /**
     * What a page reads of one rule's reference: ['sexes' => [male, female
     * => bool], 'xs' => null (every row) | [axis value, ...]]. A sex saved on
     * another form (a literal input) needs that sex's rows only, or none when
     * it is neither code; an age or length worked out from saved inputs needs
     * the rows at that value only, or none when it cannot be worked out. The
     * reading is the browser's (growthVerdict): trimmed, a Missing Data Code
     * blank, a saved date read as Y-M-D.
     */
    private function growthPageNeed(array $n, array $entry, array $config)
    {
        $codes = isset($config['missingCodes']) && is_array($config['missingCodes']) ? $config['missingCodes'] : [];
        $lit = function ($key) use ($n, $codes) {
            $op = isset($n[$key]) ? $n[$key] : null;
            if (!is_array($op) || !isset($op[0]) || $op[0] !== 'lit') return null;
            $v = trim(is_scalar($op[1] ?? null) ? (string) $op[1] : '', " \t\r\n");
            return in_array($v, $codes, true) ? '' : $v;
        };
        $out = ['sexes' => ['male' => true, 'female' => true], 'xs' => null];
        $sex = $lit('rangeSexOp');
        if ($sex !== null) {
            $which = GrowthReference::sexOf($sex, $n);
            $out['sexes'] = ['male' => $which === 'male', 'female' => $which === 'female'];
        }
        $in = ['comma' => !empty($n['rangeAxisComma'])];
        // A correction of the length or height for how the child was measured
        // moves the axis value: one typed on this page can be either.
        if (isset($n['rangeMeasuredOp']) && isset($entry['position']) && $entry['position']['corrects'] === 'axis') {
            $how = $lit('rangeMeasuredOp');
            if ($how === null) return $out;
            $in['byAdd'] = GrowthReference::positionAdd($entry, GrowthReference::positionOf($how, $n), null, 'axis');
        }
        if (isset($n['rangeAgeDobOp']) || isset($n['rangeAgeAtOp'])) {
            foreach (['dob' => 'rangeAgeDobOp', 'at' => 'rangeAgeAtOp'] as $w => $k) {
                $v = $lit($k);
                if ($v === null) return $out;   // read live on the page: every row
                $type = isset($n['range' . ucfirst($w) . 'Type']) ? $n['range' . ucfirst($w) . 'Type'] : 'date';
                $p = TemporalValue::parse($v, $type, 'ymd');
                if ($p['state'] !== 'ok') { $out['xs'] = []; return $out; }
                $in[$w . 'Days'] = (int) floor($p['seconds'] / 86400);
            }
        } else {
            foreach (['days' => 'rangeAgeDaysOp', 'months' => 'rangeAgeMonthsOp', 'by' => 'rangeByOp'] as $w => $k) {
                if (!isset($n[$k])) continue;
                $v = $lit($k);
                if ($v === null) return $out;
                $in[$w] = $v;
            }
        }
        $a = GrowthReference::axisValue($entry, $in);
        $out['xs'] = $a['state'] === 'ok' ? [$a['x']] : [];
        return $out;
    }

    /**
     * What the growth charts and page tables of this page ("show") need from
     * the server. A chart of measurements gets its reference curves
     * (GrowthCurve::build) in config.growthCurves, keyed by GrowthCurve::key so
     * charts that draw the same curves share one copy, with the rule's
     * growthCurveKey pointing at it; a table of reference values ("rows":
     * "curve") gets its rows in config.growthCurveTables, one copy per table
     * that holds the same values, with the rule's growthTableKey pointing at
     * it. Both are built for the sexes the page can read (growthPageNeed): one
     * when the sex is a saved value, both when it is typed on this page. The
     * reference's source title travels as growthChartSource, for the chart's
     * caption.
     *
     * Only a rule or branch the page judges with its table (config.growth)
     * draws: a deferred one, an idle one or one with an input withheld shows
     * nothing. The catalog's "pageCharts" caps the curve sets and the tables
     * of reference values together; each distinct set counts once, whichever
     * branch it belongs to. A part left off (past the cap, curves that cannot
     * be sent, no sex to draw for, or a "show" that does not fit the reference:
     * annotateRangeDictionary) carries the reason (growthChartWhy, a note for
     * staff), and nothing else changes: a chart never decides what is checked.
     */
    private function attachGrowthCurves(array $config)
    {
        if (empty($config['growth']) || !is_array($config['growth'])) return $config;
        $dir = null;
        $cap = null;
        $sets = [];      // curve key => the curve set
        $setWhy = [];    // curve key => why its curves cannot be sent
        $tables = [];    // table key => [sex => rows]
        $tableKeys = []; // what a table of reference values holds => its table key
        $entries = [];   // reference id => [entry, table], or null when it cannot be read
        $used = function () use (&$sets, &$tables) { return count($sets) + count($tables); };
        foreach ($config['rules'] as $i => $r) {
            if (!is_array($r) || !empty($r['configError']) || !empty($r['deferred'])) continue;
            $branched = isset($r['branches']) && is_array($r['branches']);
            foreach ($branched ? $r['branches'] : [$r] as $bi => $n) {
                if (!is_array($n) || !isset($n['rangeShow']) || !is_array($n['rangeShow']) || !$n['rangeShow']) continue;
                if (!isset($n['rangeReference']) || !is_string($n['rangeReference']) || !isset($config['growth'][$n['rangeReference']])) continue;
                if (!empty($n['deferred']) || !empty($n['growthIdle']) || $this->anyOperandWithheld($n)) continue;
                if ($cap === null) {
                    $dir = $this->referenceDir();
                    $cap = GrowthReference::catalog($dir)['pageCharts'];
                }
                $id = $n['rangeReference'];
                if (!array_key_exists($id, $entries)) {
                    $entry = GrowthReference::entry($id, $dir);
                    try {
                        $entries[$id] = $entry === null ? null : [$entry, GrowthReference::table($entry)];
                    } catch (\Throwable $e) {
                        $entries[$id] = null;
                    }
                }
                if ($entries[$id] === null) continue;
                list($entry, $table) = $entries[$id];
                $need = $this->growthPageNeed($n, $entry, $config);
                $sexes = array_keys(array_filter($need['sexes']));
                $show = $n['rangeShow'];
                $add = [];
                // the dictionary hook's reasons (a part that does not fit the reference) stand
                $why = isset($n['growthChartWhy']) && is_array($n['growthChartWhy']) ? $n['growthChartWhy'] : [];
                if (isset($entry['sourceTitle'])) $add['growthChartSource'] = $entry['sourceTitle'];
                $scale = isset($n['rangeMeasureScale']) && is_string($n['rangeMeasureScale']) ? $n['rangeMeasureScale'] : null;
                // a sex saved on another form that is blank or neither code:
                // no curves or reference values to draw for this record
                $noSex = !$sexes
                    ? 'the sex saved for this record is blank or neither the rule\'s male nor its female code, so there is nothing to draw.'
                    : null;
                // a chart of z-scores draws flat lines: it needs no curves
                if (isset($show['chart']) && is_array($show['chart']) && !isset($why['chart'])
                        && (isset($show['chart']['y']) ? $show['chart']['y'] : 'value') === 'value') {
                    $c = $show['chart'];
                    $kinds = GrowthOutputs::lineKinds();
                    $of = $kinds[$c['lines']['kind']];
                    $zs = [];
                    foreach ($c['lines']['values'] as $v) $zs[] = GrowthReference::lineZ($of, $v);
                    // the band is drawn between two more curves: the soft limits
                    $band = null;
                    if (!empty($c['bands'])) {
                        $band = [];
                        foreach (['rangeSoftLo', 'rangeSoftHi'] as $k) {
                            if (isset($n[$k]) && is_string($n[$k]) && $n[$k] !== '') {
                                $band[] = GrowthCurve::numText($n[$k]);
                                $zs[] = (float) $n[$k];
                            } else {
                                $band[] = null;
                            }
                        }
                    }
                    $fixed = isset($c['span']) && is_array($c['span']);
                    $span = GrowthCurve::span($entry, $fixed ? $c['span'] : null);
                    $key = GrowthCurve::key($id, $scale, $span, $c['lines']['kind'], $c['lines']['values'], $band);
                    if ($noSex !== null) {
                        $why['chart'] = $noSex;
                    } elseif (isset($setWhy[$key])) {
                        $why['chart'] = $setWhy[$key];
                    } elseif (!isset($sets[$key]) && $used() >= $cap) {
                        $why['chart'] = self::chartCapWhy($used(), $cap);
                    } else {
                        $set = isset($sets[$key]) ? $sets[$key] : null;
                        if ($set === null) {
                            $at = count($c['lines']['values']);
                            $bandAt = null;
                            if ($band !== null) {
                                $bandAt = [$band[0] === null ? null : $at, $band[1] === null ? null : $at + ($band[0] === null ? 0 : 1)];
                            }
                            $set = ['kind' => $c['lines']['kind'], 'values' => $c['lines']['values'], 'band' => $bandAt,
                                    'span' => $span, 'xScale' => $table['scale']];
                        }
                        foreach ($sexes as $sex) {
                            if (array_key_exists($sex, $set)) continue;
                            $b = GrowthCurve::build($entry, $table, $sex, $zs, $span, $scale, $fixed ? 1 : GrowthCurve::MAX_ZOOM);
                            if (isset($b['why'])) {
                                $setWhy[$key] = $b['why'];
                                break;
                            }
                            $set[$sex] = $b === null ? null : ['d' => $b['d'], 'lines' => $b['lines']];
                            // the least the y axis spans, when the lines do not reach it
                            if ($b !== null && ($b['extent'][0] !== null || $b['extent'][1] !== null)) $set[$sex]['extent'] = $b['extent'];
                        }
                        if (isset($setWhy[$key])) {
                            // every rule drawing these curves is told why; the set is not sent
                            $why['chart'] = $setWhy[$key];
                            unset($sets[$key]);
                        } else {
                            $sets[$key] = $set;
                            $add['growthCurveKey'] = $key;
                        }
                    }
                }
                if (isset($show['table']['rows']) && $show['table']['rows'] === 'curve' && !isset($why['table'])) {
                    $t = $show['table'];
                    $tier = isset($n['rangeReturnLimits']) && $n['rangeReturnLimits'] === 'hard' ? 'Hard' : 'Soft';
                    $lim = function ($k) use ($n) { return isset($n[$k]) && is_string($n[$k]) && $n[$k] !== '' ? $n[$k] : null; };
                    $ctx = ['limits' => ['lo' => $lim('range' . $tier . 'Lo'), 'hi' => $lim('range' . $tier . 'Hi')],
                            'decimals' => isset($n['rangeDecimals']) ? (int) $n['rangeDecimals'] : 1,
                            'percentileDecimals' => isset($n['rangePercentileDecimals']) ? (int) $n['rangePercentileDecimals']
                                : GrowthReference::DEFAULT_PERCENTILE_DECIMALS,
                            'comma' => !empty($n['decimalComma']), 'scale' => $scale,
                            'unit' => isset($n['rangeUnit']) && is_string($n['rangeUnit']) && $n['rangeUnit'] !== '' ? $n['rangeUnit'] : null];
                    $held = json_encode([$id, $t['span'], $t['step'], $t['columns'], $ctx]);
                    if ($noSex !== null) {
                        $why['table'] = $noSex;
                    } elseif (!isset($tableKeys[$held]) && $used() >= $cap) {
                        $why['table'] = self::chartCapWhy($used(), $cap);
                    } else {
                        if (!isset($tableKeys[$held])) {
                            $tableKeys[$held] = 't' . count($tableKeys);
                            $tables[$tableKeys[$held]] = [];
                        }
                        $tk = $tableKeys[$held];
                        foreach ($sexes as $sex) {
                            if (!isset($tables[$tk][$sex])) {
                                $tables[$tk][$sex] = GrowthCurve::tableRows($entry, $table, $sex, $t['span'], $t['step'], $t['columns'], $ctx);
                            }
                        }
                        $add['growthTableKey'] = $tk;
                    }
                }
                if ($why) $add['growthChartWhy'] = $why;
                if (!$add) continue;
                if ($branched) $config['rules'][$i]['branches'][$bi] = $add + $config['rules'][$i]['branches'][$bi];
                else $config['rules'][$i] = $add + $config['rules'][$i];
            }
        }
        // a set given up after an earlier rule took it (its other sex could
        // not be sent): that rule is told why too
        if ($setWhy) {
            foreach ($config['rules'] as $i => $r) {
                if (!is_array($r)) continue;
                $branched = isset($r['branches']) && is_array($r['branches']);
                foreach ($branched ? $r['branches'] : [$r] as $bi => $n) {
                    if (!is_array($n) || !isset($n['growthCurveKey']) || !isset($setWhy[$n['growthCurveKey']])) continue;
                    $n['growthChartWhy'] = ['chart' => $setWhy[$n['growthCurveKey']]]
                        + (isset($n['growthChartWhy']) && is_array($n['growthChartWhy']) ? $n['growthChartWhy'] : []);
                    unset($n['growthCurveKey']);
                    if ($branched) $config['rules'][$i]['branches'][$bi] = $n;
                    else $config['rules'][$i] = $n;
                }
            }
        }
        if ($sets) $config['growthCurves'] = $sets;
        if ($tables) $config['growthCurveTables'] = $tables;
        return $config;
    }

    /** Why a chart or a table of reference values is left off this page: the "pageCharts" cap. */
    private static function chartCapWhy($count, $cap)
    {
        if ($cap === 0) {
            return 'this server shows no growth charts or tables of reference values on a page ("pageCharts" is 0). '
                . 'The check itself is not affected.';
        }
        return 'this form already shows ' . $count . ' growth chart' . ($count === 1 ? '' : 's')
            . ' or table' . ($count === 1 ? '' : 's') . ' of reference values, the most one page may show (' . $cap
            . '). The check itself is not affected.';
    }

    /** Two pages' reads of one reference together: every row either needs. */
    private static function mergePageNeed(array $a, array $b)
    {
        return ['sexes' => ['male' => !empty($a['sexes']['male']) || !empty($b['sexes']['male']),
                            'female' => !empty($a['sexes']['female']) || !empty($b['sexes']['female'])],
                'xs' => ($a['xs'] === null || $b['xs'] === null) ? null : array_merge($a['xs'], $b['xs'])];
    }

    /** True when foldOperand withheld one of this rule's or branch's operands. */
    private function anyOperandWithheld(array $node)
    {
        foreach (ModeRegistry::operandKeys() as $spec) {
            $op = isset($node[$spec['op']]) ? $node[$spec['op']] : null;
            if (is_array($op) && isset($op[0]) && $op[0] === 'withheld') return true;
        }
        return false;
    }

    /**
     * Resolve every "when" condition for THIS page and attach the folded
     * result as the rule's/branch's `whenAst` (SEC-005).
     *
     * A condition may reference fields that are not on the instrument being
     * rendered. Their values must never be sent to the browser: a survey
     * respondent, or a user without rights to that instrument, can read
     * anything the page carries. So each comparison over such a field is
     * evaluated HERE and shipped as a boolean (Logic::fold); comparisons over
     * fields of this instrument stay live and the browser reads them from the
     * form. The page ends up carrying field names, the designer's own
     * literals, and booleans — never a record value.
     *
     * Values are read in ONE getData call for all rules. Without a record
     * (a brand-new form) there is nothing to read and every off-page
     * comparison folds against '' — exactly what REDCap's own branching sees.
     */
    private function foldRuleConditions(array $rules, $pid, $record, $instrument, $event_id, $repeat_instance, $context = 'form')
    {
        // condition text => parsed AST, for every live rule/branch on the page
        $asts = [];
        $refs = [];
        $hasOperands = false;
        foreach ($rules as $r) {
            if (!empty($r['configError'])) continue;
            // Both the "when" gate and the "assert" test (constraint mode) are
            // folded the same way: a comparison the browser can read live stays
            // live; one needing an off-instrument field is settled on the server.
            foreach (ModeRegistry::conditionTexts($r) as $w) {
                if (isset($asts[$w])) continue;
                $p = Logic::parse($w);
                if (empty($p['ok'])) continue; // a bad condition is already a configError rule
                $asts[$w] = $p['ast'];
                foreach (Logic::referencedFields($p['ast']) as $ref) $refs[$ref[0]] = true;
            }
            // An operand ("from" of @UVWINDOW) is a VALUE the verdict reads, not
            // a comparison, so it cannot be settled to a boolean here; see
            // $foldOperand below.
            foreach (ModeRegistry::operandTexts($r) as $text) {
                $op = ModeRegistry::operandRef($text);
                if ($op === null) continue;
                $refs[$op[1]] = true;
                $hasOperands = true;
            }
        }
        if (!$asts && !$hasOperands) return $rules;

        // Fields the browser can read on this page. An UNKNOWN instrument means
        // the dictionary is unavailable, so we cannot tell what is on the page:
        // nothing is live, and every rule is deferred below. Declaring the refs
        // live instead (the pre-1.6.0 fallback) shipped live ['ref', …] operands
        // for fields that are not in the DOM, which the browser then read as ''
        // and validated against — a verdict computed from a value it never had
        // (M-03). $live = [] alone is not enough: with no live side fold() never
        // sets $frozen, so the deferral has to be forced explicitly.
        $live = $this->fieldsOnInstrument($pid, $instrument);
        $unknownForm = ($live === null);
        if ($unknownForm) $live = [];
        // A survey shown one section per page renders only this page's fields,
        // so a field answered on another page is off-page like a field of
        // another form, and is settled here from the saved response (REQ-4).
        if (!$unknownForm && $context === 'survey') {
            $page = $this->surveyPageFields($pid, $instrument);
            if ($page !== null) $live = array_intersect_key($live, $page);
        }

        $values = [];
        $resolution = [];
        if ($refs && $record !== null && $record !== '') {
            try {
                $values = $this->readValues($pid, $record, array_keys($refs), $event_id, $instrument, $repeat_instance, true, $resolution);
            } catch (\Throwable $e) {
                // readValues already reports 'unreadable' per field for a failed
                // read; this catch only covers a throw from outside it.
                foreach (array_keys($refs) as $rf) $resolution[$rf] = 'unreadable';
            }
        }

        // Off-page fields this viewer is already entitled to read. Their values
        // may be baked into the shipped condition so a cross-form comparison
        // stays LIVE instead of freezing at page-load truth (see Logic::fold).
        $disclosable = $this->disclosableFields($pid, $context, array_keys($refs), $instrument);

        // A field we could not actually resolve must never be baked and must
        // never be evaluated: baking it would ship ['lit',''] and validate the
        // user's keystrokes against a value we never read, which is exactly the
        // frozen-verdict class 1.6.0 set out to remove (H-01/H-04/M-01).
        $unresolved = [];
        foreach ($resolution as $f => $state) {
            if ($state !== 'ok') {
                $unresolved[$f] = $state;
                unset($disclosable[$f]);
            }
        }

        // Every condition is folded once per ROLE (gate / assert polarity) and
        // once per CASE MODE: text => [0 => case-insensitive (the default),
        // 1 => case-sensitive], and a test also 2 => "exact" (AST-2). Two
        // rules may share a condition string and differ only in
        // "caseSensitive" or "exact", and an off-page comparison settled to a
        // constant under one mode is not the constant the other would produce.
        $folded = [];
        $foldedAssert = [];
        $numbers = $this->conditionNumbers($pid);
        $frozen = [];   // condition text => a live side had to be given up
        $blocked = [];  // condition text => field => why it could not be resolved
        $snapshot = []; // condition text => off-page fields baked at render time
        foreach ($asts as $w => $ast) {
            $f = false;
            $b = [];
            $sn = [];
            $folded[$w] = [];
            $folded[$w][0] = Logic::fold($ast, $values, $live, $disclosable, $f, $unresolved, $b, $sn, Logic::BLANK_INERT, false, $numbers);
            // Freshness diagnostics come from reference resolution and liveness
            // alone, never from a verdict, so the pass above already recorded
            // them; this one discards its own.
            $fCs = false;
            $bCs = [];
            $snCs = [];
            $folded[$w][1] = Logic::fold($ast, $values, $live, $disclosable, $fCs, $unresolved, $bCs, $snCs, Logic::BLANK_INERT, true, $numbers);
            $frozen[$w] = $f || $unknownForm;
            $blocked[$w] = $b;
            $snapshot[$w] = $sn;
            // FOLDED TWICE, because this map is keyed by condition TEXT and one
            // string may legally serve as both a gate and a test — the same rule
            // can carry {"assert":"[a]<=[b]","when":"[a]<=[b]"}. A comparison
            // settled off-page becomes ['const', bool], and since a blank
            // operand now settles to the CALLER's polarity (CRIT-01), one
            // constant cannot stand for both roles: the gate copy would ship an
            // assert's "passes" as "this rule applies". Parsing stays single
            // pass; only the fold is repeated, over a handful of small ASTs.
            // The freshness diagnostics come from reference resolution and
            // liveness alone — never from a verdict — so this pass discards
            // them rather than overwriting the ones above.
            // A test has a third text mode, "exact" (index 2, testTextMode).
            foreach ([0 => false, 1 => true, 2 => Logic::EXACT] as $cs => $mode) {
                $f2 = false;
                $b2 = [];
                $sn2 = [];
                $foldedAssert[$w][$cs] = Logic::fold($ast, $values, $live, $disclosable, $f2, $unresolved, $b2, $sn2, Logic::BLANK_PASSES, $mode, $numbers);
            }
        }
        // With the form unknown, NOTHING is live — not because these fields are
        // genuinely elsewhere but because we cannot see the page at all. Every
        // comparison therefore looks fully off-page. Naming those fields as a
        // snapshot would dress "we cannot tell" up as a freshness warning; the
        // rule is deferred below with its own, accurate reason.
        if ($unknownForm) foreach ($snapshot as $w => $_) $snapshot[$w] = [];
        // Surface every unresolved reference as a visible configuration notice
        // rather than a silent non-verdict. Reasons are collected PER RULE: a
        // page-global list assigned to whichever rule deferred first blamed that
        // rule for a field its own condition never mentions, and told the rule
        // whose problem it actually was nothing at all.
        $notes = [];    // rule index => [reason, ...]
        $noteFor = function ($i, $cond) use ($blocked, &$notes) {
            foreach (isset($blocked[$cond]) ? $blocked[$cond] : [] as $field => $state) {
                $notes[$i][$field . '|' . $state] = self::resolutionProblem($state, $field);
            }
        };
        // An OPERAND cannot fold to a boolean: the verdict compares it with
        // what the user types. Five outcomes, in this order:
        //   unresolved         deferred, with the reason
        //   form unknown       deferred (the reason is added at the end)
        //   on this page       live ['ref', field, null], read as typed
        //   entitled off-page  its saved value as ['lit', Y-M-D] plus a
        //                      snapshot note, so the client never blocks on it
        //   anything else      ['withheld']: a survey respondent or a user
        //                      without rights to that form must not see the
        //                      value, and a window built from it would show it.
        //                      The rule stays live for what does not need it
        //                      ("notFuture", "notPast"); the browser says the rest is
        //                      checked after saving.
        // $withhold (ModeRegistry::withholds): the rule checks something that
        // does not need this operand (@UVWINDOW "notFuture" or "notPast"), so an unresolved
        // one is withheld, with its reason ('withheldWhy'), and the rule stays live.
        $foldOperand = function ($text, $withhold = false) use ($live, $values, $disclosable, $unresolved, $unknownForm) {
            $out = ['op' => null, 'deferred' => true, 'why' => [], 'snapshot' => []];
            $op = ModeRegistry::operandRef($text);
            if ($op === null) return $out;
            $f = (string) $op[1];
            if (isset($unresolved[$f])) {
                $out['why'][$f . '|' . $unresolved[$f]] = self::resolutionProblem($unresolved[$f], $f);
                if ($withhold) {
                    $out['op'] = ['withheld'];
                    $out['deferred'] = false;
                    $out['withheldWhy'] = true;
                }
                return $out;
            }
            if ($unknownForm) return $out;
            if (isset($live[$f])) {
                $out['op'] = ['ref', $f, null];
                $out['deferred'] = false;
                return $out;
            }
            $out['deferred'] = false;
            if (isset($disclosable[$f])) {
                $v = isset($values[$f]) ? $values[$f] : '';
                $out['op'] = ['lit', is_array($v) ? '' : (string) $v];
                $out['snapshot'][$f] = true;
            } else {
                $out['op'] = ['withheld'];
            }
            return $out;
        };
        foreach ($rules as $i => $r) {
            if (!empty($r['configError'])) continue;
            // Off-page operands are read ONCE, when the page is built. If someone
            // edits that other form in another tab the verdict here goes stale,
            // and a wrong HARD block would otherwise be a dead end with no
            // explanation (M-02). Name the fields so the client can downgrade the
            // rule to advisory and tell the user to reload. The set is collected
            // across the "when" AND the "assert": staleness in the gate decides
            // whether the rule APPLIES, which is exactly as wrong as a stale
            // verdict, and a rule-level key is written once at the end so the
            // second condition cannot overwrite the first's fields (H-01).
            $snapFields = [];
            // A snapshot read by a condition (not by the "from" date itself): whether
            // the rule applies may be stale, so not even "notFuture" blocks.
            $gateSnap = false;
            if (isset($r['when']) && isset($folded[$r['when']])) {
                $rules[$i]['whenAst'] = $folded[$r['when']][empty($r['caseSensitive']) ? 0 : 1];
                // An unresolvable "when" gates on a value we never read, so the
                // rule must not act on it either.
                if (!empty($blocked[$r['when']])) { $rules[$i]['deferred'] = true; $noteFor($i, $r['when']); }
                // A gate that had to give up a live side is stale in the same way
                // an assert is: defer rather than gate on page-load truth.
                if (!empty($frozen[$r['when']])) $rules[$i]['deferred'] = true;
                foreach (isset($snapshot[$r['when']]) ? $snapshot[$r['when']] : [] as $sf => $_) { $snapFields[$sf] = true; $gateSnap = true; }
            }
            // The TEST conditions ("assert", and any a mode declares with role
            // "test" in php/modes.json) fold with the test polarity.
            foreach (ModeRegistry::refKeys('cond', 'test') as $rk) {
                $tk = $rk['key'];
                if (!isset($r[$tk]) || !isset($folded[$r[$tk]])) continue;
                $rules[$i][$rk['ast']] = $foldedAssert[$r[$tk]][self::testModeIndex($r)];
                // A frozen TEST must never block: its verdict is stale the
                // moment the user types, and the post-save audit re-checks it.
                if (!empty($frozen[$r[$tk]])) $rules[$i]['deferred'] = true;
                if (!empty($blocked[$r[$tk]])) $noteFor($i, $r[$tk]);
                foreach (isset($snapshot[$r[$tk]]) ? $snapshot[$r[$tk]] : [] as $sf => $_) { $snapFields[$sf] = true; $gateSnap = true; }
            }
            foreach (ModeRegistry::operandKeys() as $rk) {
                if (!isset($r[$rk['key']])) continue;
                $fo = $foldOperand($r[$rk['key']], ModeRegistry::withholds($rk, $r));
                if ($fo['op'] !== null) $rules[$i][$rk['op']] = $fo['op'];
                if ($fo['deferred']) $rules[$i]['deferred'] = true;
                if (!empty($fo['withheldWhy'])) $rules[$i][$rk['op'] . 'Why'] = array_values($fo['why']);
                else foreach ($fo['why'] as $wk => $wt) $notes[$i][$wk] = $wt;
                foreach ($fo['snapshot'] as $sf => $_) $snapFields[$sf] = true;
            }
            if ($snapFields) $rules[$i]['snapshotFields'] = array_keys($snapFields);
            if ($gateSnap) $rules[$i]['snapshotGate'] = true;
            if (isset($r['branches']) && is_array($r['branches'])) {
                // Both marks below are kept PER BRANCH GROUP. A mode with groups
                // (@UVRANGE: one per reference) chooses a branch once per group
                // (Branching, QRID_activeVariants), so one reference's selector
                // says nothing about which branch of another applies; collected
                // across the rule, a saved value read by one reference's "when"
                // made every other reference on the field advisory. A mode with
                // no groups has one group, which is the whole rule, as before.
                $groupKey = ModeRegistry::branchGroup(ModeRegistry::modeOfType(isset($r['type']) ? $r['type'] : ''));
                // An unresolved SELECTOR makes the branch decision undecidable, and
                // the client would otherwise fall through to the fallback branch and
                // enforce it (H-01). Mark EVERY branch of the group deferred, not
                // just the blocked one, so no branch of it can be selected and
                // enforced on this page.
                $selectorBlocked = [];   // group => field => state
                // A SELECTOR settled on the server picks the branch from page-load
                // truth. Whichever branch that turns out to be, its verdict rests
                // on a value that may already have changed, so the staleness
                // belongs to the whole group — every branch of it is marked,
                // because only the selected one's BLOCK setting is ever consulted
                // (H-01).
                $selectorSnapshot = [];  // group => field => true
                $groupsSeen = [];
                foreach ($r['branches'] as $bi => $b) {
                    $g = Branching::groupOf($b, $groupKey);
                    $groupsSeen[$g] = true;
                    if (!isset($b['when']) || $b['when'] === '') continue;
                    if (!empty($blocked[$b['when']])) {
                        foreach ($blocked[$b['when']] as $bf => $bstate) $selectorBlocked[$g][$bf] = $bstate;
                    }
                    foreach (isset($snapshot[$b['when']]) ? $snapshot[$b['when']] : [] as $sf => $_) $selectorSnapshot[$g][$sf] = true;
                }
                $severalGroups = count($groupsSeen) > 1;
                foreach ($r['branches'] as $bi => $b) {
                    $g = Branching::groupOf($b, $groupKey);
                    $gBlocked = isset($selectorBlocked[$g]) ? $selectorBlocked[$g] : [];
                    $bWhy = [];
                    $bSnap = isset($selectorSnapshot[$g]) ? $selectorSnapshot[$g] : [];
                    $bGate = (bool) $bSnap;
                    if (isset($b['when']) && isset($folded[$b['when']])) {
                        $rules[$i]['branches'][$bi]['whenAst'] = $folded[$b['when']][empty($b['caseSensitive']) ? 0 : 1];
                        if (!empty($blocked[$b['when']])) {
                            $rules[$i]['branches'][$bi]['deferred'] = true;
                            $noteFor($i, $b['when']);
                            foreach ($blocked[$b['when']] as $bf => $bs) $bWhy[$bf . '|' . $bs] = self::resolutionProblem($bs, $bf);
                        }
                        if (!empty($frozen[$b['when']])) $rules[$i]['branches'][$bi]['deferred'] = true;
                    }
                    foreach (ModeRegistry::refKeys('cond', 'test') as $rk) {
                        $tk = $rk['key'];
                        if (!isset($b[$tk]) || !isset($folded[$b[$tk]])) continue;
                        $rules[$i]['branches'][$bi][$rk['ast']] = $foldedAssert[$b[$tk]][self::testModeIndex($b)];
                        if (!empty($frozen[$b[$tk]])) $rules[$i]['branches'][$bi]['deferred'] = true;
                        if (!empty($blocked[$b[$tk]])) {
                            $noteFor($i, $b[$tk]);
                            foreach ($blocked[$b[$tk]] as $bf => $bs) $bWhy[$bf . '|' . $bs] = self::resolutionProblem($bs, $bf);
                        }
                        foreach (isset($snapshot[$b[$tk]]) ? $snapshot[$b[$tk]] : [] as $sf => $_) { $bSnap[$sf] = true; $bGate = true; }
                    }
                    foreach (ModeRegistry::operandKeys() as $rk) {
                        if (!isset($b[$rk['key']])) continue;
                        $fo = $foldOperand($b[$rk['key']], ModeRegistry::withholds($rk, $b));
                        if ($fo['op'] !== null) $rules[$i]['branches'][$bi][$rk['op']] = $fo['op'];
                        if ($fo['deferred']) $rules[$i]['branches'][$bi]['deferred'] = true;
                        if (!empty($fo['withheldWhy'])) $rules[$i]['branches'][$bi][$rk['op'] . 'Why'] = array_values($fo['why']);
                        else foreach ($fo['why'] as $wk => $wt) { $bWhy[$wk] = $wt; $notes[$i][$wk] = $wt; }
                        foreach ($fo['snapshot'] as $sf => $_) $bSnap[$sf] = true;
                    }
                    // M-01: branch configs never inherit rule-level keys on the
                    // client, so a branch's snapshot/deferral diagnostics have to
                    // be written ONTO the branch or they are silently dropped.
                    if ($bSnap) $rules[$i]['branches'][$bi]['snapshotFields'] = array_keys($bSnap);
                    if ($bGate) $rules[$i]['branches'][$bi]['snapshotGate'] = true;
                    if ($gBlocked) {
                        $rules[$i]['branches'][$bi]['deferred'] = true;
                        // With other groups beside it, the page names this
                        // group's deferral whether or not any of its
                        // conditions holds (QRID_buildVariants).
                        if ($severalGroups) $rules[$i]['branches'][$bi]['selectorBlocked'] = true;
                        foreach ($gBlocked as $bf => $bs) $bWhy[$bf . '|' . $bs] = self::resolutionProblem($bs, $bf);
                    }
                    if ($bWhy) $rules[$i]['branches'][$bi]['deferredWhy'] = array_values($bWhy);
                }
                // Only when no group can choose a branch is the field not
                // checked here at all.
                if ($selectorBlocked && count($selectorBlocked) === count($groupsSeen)) {
                    $rules[$i]['deferred'] = true;
                    foreach ($selectorBlocked as $gBlocked) {
                        foreach ($gBlocked as $bf => $bs) {
                            $notes[$i][$bf . '|' . $bs] = self::resolutionProblem($bs, $bf)
                                . ' No branch can be chosen, so the field is not checked here.';
                        }
                    }
                }
            }
        }
        foreach ($notes as $i => $why) {
            if (isset($rules[$i])) $rules[$i]['deferredWhy'] = array_values($why);
        }
        // A rule deferred ONLY because the dictionary was unavailable has no
        // blocked field to name, but the designer still deserves a reason.
        if ($unknownForm) {
            foreach ($rules as $i => $r) {
                if (!empty($r['deferred']) && empty($rules[$i]['deferredWhy'])) {
                    $rules[$i]['deferredWhy'] = ['could not read the data dictionary for this project, so the '
                        . 'fields on this form are unknown — the rule is not checked here rather than '
                        . 'checked against values that may not be on the page.'];
                }
            }
        }
        return $rules;
    }

    /**
     * The off-page fields whose SAVED VALUE may be baked into this page's
     * conditions, as (field => true). Everything here fails CLOSED: any doubt
     * returns the field as non-disclosable, which costs only live reactivity
     * (the rule goes advisory and the server audit still RECORDS a violation
     * after the write — detection, not prevention), whereas
     * a wrong "yes" would put a record value in front of someone with no right
     * to it — the SEC-005 leak this module exists to prevent.
     *
     * Three gates, all required:
     *   1. Data entry only. A survey page is rendered for an unauthenticated
     *      respondent, so nothing is ever disclosable there.
     *   2. A REDCap-authenticated username.
     *   3. Per-INSTRUMENT rights for that user: REDCap's own granularity. A
     *      form the user may open is one whose values they can already read,
     *      so baking a value in discloses nothing new — this is exactly what
     *      REDCap's stock branching logic already ships to the page.
     *
     * Fields of the rendered instrument are excluded: they are live refs
     * already and never need a baked literal.
     */
    private function disclosableFields($pid, $context, array $refs, $instrument = null)
    {
        if ($context !== 'form' || !$refs) return [];
        try {
            $forms = $this->userFormRights($pid);
            if ($forms === null) return [];
            $allForms = ($forms === true);
            $dd = $this->dataDictionary($pid);
            if (!$dd) return [];
            $out = [];
            foreach ($refs as $f) {
                if (!isset($dd[$f]['form_name'])) continue;          // unknown field -> no
                $form = $dd[$f]['form_name'];
                if ($instrument !== null && $form === $instrument) continue;  // already live
                // An administrator reads every instrument, so there is no map
                // to consult and nothing to bar.
                if (!$allForms && !array_key_exists($form, $forms)) continue;   // no entry -> no
                // REDCap form rights: 1 view/edit, 2 read-only and 3 edit survey
                // responses imply the user can read the form; any other value
                // does not (ScanPageView::FORM_READ_LEVELS).
                if (!$allForms && !ScanPageView::formLevelReads($forms[$form])) continue;
                $out[$f] = true;
            }
            return $out;
        } catch (\Throwable $e) {
            return [];   // fail closed
        }
    }

    /**
     * This user's per-instrument rights for $pid as (form_name => level), or
     * NULL when they cannot be established — which callers must treat as "no
     * rights", never as "all rights".
     *
     * Deliberately does NOT call \REDCap::getUserRights($pid) as the primary
     * source. That method's FIRST parameter is the user list, not the project
     * id, so passing a pid there returns rights for a user named "151" —
     * i.e. nothing — and the whole feature would go quietly inert on a real
     * REDCap while every mock passed. That is precisely how @UVUNIQUE shipped
     * dead in v1.4.0 (see the is_callable/method_exists note above), so the
     * framework-native User::getRights($pid) is tried FIRST and the static is
     * only a fallback, called with no arguments and filtered here by username.
     *
     * TRI-STATE, and every caller must handle all three:
     *   array  the per-instrument rights map
     *   true   EVERY instrument - a REDCap administrator, who has no rights row
     *   null   could not be established, which clears nothing
     *
     * `true` is not a convenience. Folding it into "an array containing every
     * form" would need the dictionary read here, and would make an
     * administrator's entitlement a snapshot that goes stale the moment an
     * instrument is added.
     */
    private function userFormRights($pid)
    {
        $user = $this->currentUsername();
        if ($user === null) return null;

        // 0. AN ADMINISTRATOR HAS NO RIGHTS ROW TO READ, and never needed one:
        //    a REDCap super-user bypasses project rights entirely. Returning
        //    null here - "rights could not be established" - made every
        //    instrument unclearable and the entitlement gate barred every rule,
        //    which on the first live pilot was indistinguishable from a user
        //    with genuinely no access. Asked of the framework, never inferred
        //    from the absence itself; see ScanPageView::isAdministrator().
        try {
            if (is_callable([$this, 'getUser'])
                    && ScanPageView::isAdministrator($this->getUser())) {
                return true;                      // every instrument; see the tri-state above
            }
        } catch (\Throwable $e) {
        }

        // 1. Framework-native: an unambiguous, project-scoped signature.
        try {
            if (is_callable([$this, 'getUser'])) {
                $u = $this->getUser();
                if ($u && is_callable([$u, 'getRights'])) {
                    $r = $u->getRights($pid);
                    // Some framework builds key rights BY PROJECT ID. Read
                    // through that shape, not past it: $r['forms'] on a pid-keyed
                    // array is simply unset, which reads here as "rights could
                    // not be established" - safe, but it silently disables the
                    // feature on those builds. ScanPageView::scanScope() already
                    // reads through it for group_id; this is the same shape, one
                    // helper over.
                    if (is_array($r) && isset($r[$pid]) && is_array($r[$pid])) $r = $r[$pid];
                    if (is_array($r) && isset($r['forms']) && is_array($r['forms'])) return $r['forms'];
                }
            }
        } catch (\Throwable $e) {
        }

        // 2. Fallback: the static, called with NO arguments so the parameter
        //    order cannot be got wrong, then keyed by username. It answers for
        //    the project of the REQUEST whatever $pid is, so it is not asked
        //    about any other project (rights elsewhere: userRightsIn()).
        try {
            $current = null;
            try { $current = $this->getProjectId(); } catch (\Throwable $e) {}
            if ($current !== null && $current !== '' && (int) $current !== (int) $pid) return null;
            if (is_callable(['\REDCap', 'getUserRights'])) {
                $all = \REDCap::getUserRights();
                if (is_array($all) && isset($all[$user]) && is_array($all[$user])
                    && isset($all[$user]['forms']) && is_array($all[$user]['forms'])) {
                    return $all[$user]['forms'];
                }
            }
        } catch (\Throwable $e) {
        }
        return null;   // fail closed
    }

    /**
     * The authenticated username, or null when this request has none (survey
     * respondent, cron, API). Tries the External Modules user object first and
     * falls back to REDCap's USERID constant; both are guarded because neither
     * exists in every context this module runs in.
     */
    private function currentUsername()
    {
        try {
            if (is_callable([$this, 'getUser'])) {
                $u = $this->getUser();
                if ($u && is_callable([$u, 'getUsername'])) {
                    $name = $u->getUsername();
                    if (is_string($name) && $name !== '') return $name;
                }
            }
        } catch (\Throwable $e) {
        }
        if (defined('USERID')) {
            $name = constant('USERID');
            if (is_string($name) && $name !== '') return $name;
        }
        return null;
    }

    /**
     * Every field one rule READS: its own, its when/assert operands, and its
     * composite unique partners.
     *
     * The same three sources scanPlan() unions into $readSet, gathered per rule
     * rather than per project.
     *
     * IT IS NO LONGER THE ENTITLEMENT DERIVATION, and this sentence replaces one
     * that said it was ("kept beside them so the two cannot drift: a source
     * added to the read set and forgotten here would be a field the scan reads
     * and never checks the reader's right to"). That mechanism is gone: the
     * entitlement now comes from $readSet itself, in scanPlan(), because two
     * derivations of one set is two things that can drift and the drift is what
     * opened the hole. What remains for this helper is the per-rule form the
     * enforceFormRights gate needs - and that gate has no production caller.
     *
     * @return string[]
     */
    private static function ruleRefFields(array $r)
    {
        if (TemporalRules::extended($r)) return TemporalRules::fields($r);
        $out = [];
        foreach ((isset($r['fields']) && is_array($r['fields'])) ? $r['fields'] : [] as $f) {
            $out[(string) $f] = true;
        }
        foreach (ModeRegistry::refFields($r) as $f) $out[(string) $f] = true;
        return array_keys($out);
    }

    /**
     * Whether this reader may read $form, from REDCap's own per-instrument
     * rights. Fails CLOSED at every step.
     *
     * REDCap's levels: 0 no access; 1 view/edit, 2 read-only and 3 edit survey
     * responses all imply the form can be read. A form with NO entry is not an
     * unrestricted form - it is a form the rights row says nothing about, and
     * saying nothing is not granting.
     */
    private static function mayReadForm($rights, $form)
    {
        // TRUE is userFormRights()'s "every instrument": a REDCap administrator,
        // who has no per-instrument rights row because a super-user does not
        // need one. Distinct from NULL, which is "could not be established" and
        // still clears nothing.
        if ($rights === true) return true;
        if (!is_array($rights)) return false;                  // unestablished -> clears nothing
        if (!array_key_exists($form, $rights)) return false;   // no entry -> no
        return ScanPageView::formLevelReads($rights[$form]);   // 1, 2 or 3 only (SEC-1)
    }

    /**
     * All active rules, from BOTH configuration channels:
     *   1. the repeatable "rules" project settings (module Configure dialog),
     *   2. @UVALIDATE field annotations (Online Designer / data dictionary CSV).
     * A field claimed by more than one rule gets a duplicate-rule config error on
     * the client, so the two channels cannot silently fight over a field.
     *
     * $fields: a live lookup asks about one field, so it passes that field and
     * only the rules it can be judged by are built: the field's own tags, the
     * settings rules, and each tag that can return a value, since a return
     * elsewhere can refuse the field's rule. Branching resolves sharing field by
     * field, so the rules on the field come out as the full list has them, but
     * for the other fields a merged rule names (tests/rule_scope_php.php). A
     * lookup reads only its own field of a rule. A page and a save build every
     * rule: staff see the configuration errors of every form (PRF-3).
     */
    /** @var array|null per-request memo: pid => resolved rule list */
    private $rulesMemo = [];

    private function getRules($pid = null, ?array $fields = null)
    {
        // Memoized per request. A finding cites a rule by ORDINAL, and the
        // report resolves that ordinal against getRules() again; unmemoized,
        // those were two independent reads, and anything that changed the rule
        // list between them shifted every ordinal so that every label, message
        // and assertion in the report attached to the wrong rule with nothing to
        // detect it. Stable rule identity is Tasks 5-6; this closes the window
        // in the meantime.
        $key = (string) ($pid === null ? '' : $pid) . '|qualified=' . ($this->temporalOptions($pid, true)['qualified'] ? '1' : '0');
        if ($fields !== null) {
            $fields = array_values(array_unique(array_map('strval', $fields)));
            sort($fields);
            $key .= '|fields=' . implode(',', $fields);
        }
        if (array_key_exists($key, $this->rulesMemo)) return $this->rulesMemo[$key];

        // WHERE A RULE CAME FROM TRAVELS ON THE RULE.
        //
        // It used to be inferred from a count: the caller was expected to say
        // how many leading entries were settings rules, and everything after
        // that boundary was an annotation rule. No caller ever passed the
        // count, so every settings rule was named through the annotation branch
        // - and the count could not have been made correct anyway, because
        // Branching::resolve() below DROPS rules that lost every field to a
        // branch rule and APPENDS synthesized ones, so no integer boundary
        // survives it. A key on the rule does survive it: resolve() copies
        // surviving rules wholesale.
        $out = [];
        foreach ($this->getSettingRules($pid) as $r) { $r['_origin'] = 'settings'; $out[] = $r; }
        $scope = $fields === null ? null : array_fill_keys($fields, true);
        foreach ($this->getAnnotationRules($pid, $scope) as $r) { $r['_origin'] = 'annotation'; $out[] = $r; }
        // Shared fields become explicit per-field branch rules (or config
        // errors when the sharing is illegal), so the client engine, the
        // audit, and the snapshot all consume one resolved structure.
        //
        // Stored only on success: a throw must NOT be memoized as an answer,
        // because a failed read judged as "no rules" is the H-05 mistake.
        //
        // And the DICTIONARY is part of "success". Annotation rules are read out
        // of it and setting rules are validated against it, so a list built
        // without one is not "no rules" - it is "we could not tell". Memoizing
        // that made one transient dictionary failure permanent for the request,
        // which is round 4's A4 defect one layer up: keying the dictionary cache
        // by pid did NOT let a later scan recover, because the poisoned answer
        // had already been stored here. Found by the probe written for A4.
        foreach ($out as &$candidate) {
            if (empty($candidate['configError']) && TemporalRules::extended($candidate)) {
                $dd = $this->dataDictionary($pid) ?: [];
                $errors = TemporalRules::validate($candidate);
                if (!isset($extendedShape)) {
                    $extendedShape = TemporalMetadata::load($pid, $dd, $shapeUnread);
                    // A rule judged against events that could not be read is
                    // a configuration error the project does not have (R5-4).
                    if ($shapeUnread) $this->noteConfigUnread($pid, 'the project\'s events');
                }
                $errors = array_merge($errors, TemporalRules::validateProject($candidate, $extendedShape));
                foreach (TemporalRules::fields($candidate) as $f) {
                    if ($dd && !isset($dd[$f])) $errors[] = 'Unknown referenced field: ' . $f . '.';
                    elseif (in_array($dd[$f]['field_type'] ?? '', ['file', 'descriptive'], true)) $errors[] = 'Referenced field has no comparable value: ' . $f . '.';
                }
                if (!$this->temporalOptions($pid)['qualified']) $errors[] = 'Enable event and instance references in project settings first.';
                if ($errors) $candidate['configError'] = implode(' ', $errors);
            }
        }
        unset($candidate);
        $resolved = self::applyJustify(self::markReturnClashes(Branching::resolve($out)));
        if ($this->dataDictionary($pid)) $this->rulesMemo[$key] = $resolved;
        return $resolved;
    }

    /**
     * A field takes the value of ONE rule. Two rules that return a value to the
     * same field would overwrite each other in whichever order the page and
     * the save run them, so both are refused, naming each other. Rules on one
     * field (branches) share their fields on purpose: only one is active. Two
     * branch groups of one rule (@UVRANGE: two references) are active at the
     * same time, so they may not share a field either.
     *
     * A rule that returns values may not read a field such a rule returns to
     * (its own or another's): the save works out every returned value from the
     * values saved before any is written, so a chain would write a value read
     * one save late, and the page would follow it only a few steps. The rule
     * that reads is refused, naming the rule that writes.
     *
     * A rule of a locking mode (@UVWRITEONCE) may not lock a field another
     * rule returns to: the save writes a returned value again every time, so
     * the lock would either undo the rule or be broken by it. The lock is
     * refused on that field alone, naming the rule that writes: tags that are
     * written alike on several fields are one rule (AnnotationRules::groupMulti),
     * so the field is split off into a rule of its own and the others keep
     * their lock.
     */
    private static function markReturnClashes(array $rules)
    {
        $owner = [];    // target field => index of the rule that claimed it first
        $clash = [];    // rule index => [target field => the other rule's index]
        $why = [];      // rule index => further reasons
        foreach ($rules as $i => $r) {
            if (!is_array($r) || !empty($r['configError'])) continue;
            foreach (ModeRegistry::returnFields($r) as $f) {
                if (isset($owner[$f]) && $owner[$f] !== $i) {
                    $clash[$i][$f] = $owner[$f];
                    $clash[$owner[$f]][$f] = $i;
                } else {
                    $owner[$f] = $i;
                }
            }
            if (!isset($r['branches']) || !is_array($r['branches'])) continue;
            $mode = ModeRegistry::modeOfType(isset($r['type']) ? $r['type'] : '');
            $key = ModeRegistry::branchGroup($mode);
            if ($key === null) continue;
            $groups = [];
            foreach ($r['branches'] as $b) if (is_array($b)) $groups[Branching::groupOf($b, $key)] = true;
            $seen = [];     // field => the group that returns to it
            $name = ModeRegistry::branchGroupName($mode) ?: 'group';
            $label = function ($x) use ($name) { return $x === '' ? 'the limits without a ' . $name : $name . ' "' . $x . '"'; };
            foreach (array_keys($groups) as $g) {
                foreach (ModeRegistry::returnFields($r, (string) $g) as $f) {
                    if (isset($seen[$f]) && $seen[$f] !== (string) $g) {
                        $why[$i][] = '"[' . $f . ']" is returned to by both ' . $label($seen[$f]) . ' and ' . $label((string) $g)
                            . ', which are judged at the same time — give each its own fields.';
                    } else {
                        $seen[$f] = (string) $g;
                    }
                }
            }
        }
        foreach ($rules as $i => $r) {
            if (!is_array($r) || !empty($r['configError']) || !ModeRegistry::returnFields($r)) continue;
            foreach (TemporalRules::fields($r) as $f) {
                if (!isset($owner[$f])) continue;
                $by = $owner[$f] === $i ? 'this rule' : 'the rule on "' . (isset($rules[$owner[$f]]['fields'][0]) ? $rules[$owner[$f]]['fields'][0] : '?') . '"';
                $why[$i][] = 'it reads "[' . $f . ']", which ' . $by . ' returns a value to — a rule that returns values never '
                    . 'reads a returned value, since the save works every returned value out before it writes any.';
            }
        }
        foreach ($clash as $i => $fields) {
            $parts = [];
            foreach ($fields as $f => $other) {
                $of = isset($rules[$other]['fields'][0]) ? $rules[$other]['fields'][0] : '?';
                $parts[] = '"[' . $f . ']" (also written by the rule on "' . $of . '")';
            }
            $rules[$i]['configError'] = 'a field takes the value of one rule, and this rule\'s "return" shares '
                . implode(', ', $parts) . ' — give each rule its own fields.';
        }
        foreach ($why as $i => $list) {
            if (!empty($rules[$i]['configError'])) continue;
            $rules[$i]['configError'] = ModeRegistry::tagOfType(isset($rules[$i]['type']) ? $rules[$i]['type'] : '')
                . ' "return": ' . implode(' ', array_values(array_unique($list)));
        }
        $split = [];
        foreach ($rules as $i => $r) {
            if (!is_array($r) || !empty($r['configError'])) continue;
            $lockWhy = ModeRegistry::lockReturnWhy(ModeRegistry::modeOfType(isset($r['type']) ? $r['type'] : ''));
            if ($lockWhy === null) continue;
            $fields = isset($r['fields']) && is_array($r['fields']) ? $r['fields'] : [];
            $keep = [];
            $mine = [];     // this rule's refusals, in field order
            foreach ($fields as $f) {
                // a returning rule that is itself refused returns nothing
                if (!isset($owner[$f]) || $owner[$f] === $i || !empty($rules[$owner[$f]]['configError'])) { $keep[] = $f; continue; }
                $by = isset($rules[$owner[$f]]['fields'][0]) ? (string) $rules[$owner[$f]]['fields'][0] : '?';
                $mine[] = array_merge($r, ['fields' => [$f], 'configError' => strtr($lockWhy, ['{field}' => (string) $f, '{by}' => $by])]);
            }
            if (count($keep) === count($fields)) continue;
            if ($keep) {
                $rules[$i]['fields'] = $keep;
            } else {
                // every field refused: the rule's place holds its own first refusal
                $rules[$i] = array_shift($mine);
            }
            foreach ($mine as $x) $split[] = $x;
        }
        foreach ($split as $r) $rules[] = $r;
        return $rules;
    }

    /**
     * A rule on a field that a lock (php/modes.json "lock") holds on this page
     * names in lockReads, for each such field, the other fields it reads that
     * the person may change here: fields of this form ($onPage, field =>
     * true; null when the form's fields are not known) that no lock holds and
     * that REDCap does not fill after the page has started (lockReadsLate).
     * The page takes the value each one opened with after REDCap's own
     * start-up, so a field REDCap fills as the page starts (@NOW, @TODAY,
     * @DEFAULT, @SETVALUE) or one the person cannot change (@READONLY,
     * @HIDDEN) is named: it does not change after that unless the person
     * changes it. The guard item keeps holding the save only once one of
     * them has changed on the page (UV_LOCK_READS in js/engine.js): a
     * violation saved before the page opened does not trap the form, and two
     * locked fields that read each other hold nothing. A rule that reads no
     * such field is not marked. A checkbox is named once per option, as
     * "field(code)", the way the page reads it. A calc, @CALCTEXT or
     * @CALCDATE field is never named, since REDCap works it out again as the
     * page starts: in its place come the fields it is worked out from on
     * this entry that the person may change here, through other calcs too
     * (lockReadsCalc, on the page's event $eventId of $shape and its
     * instance $instance), a checkbox option by option. Those of every calc
     * are worked out together, as the least fixpoint over what each calc
     * reads, so a cycle of calcs gives the same answer whatever the order of
     * the rules. $dd is the data dictionary (field => metadata).
     */
    private static function markLockReads(array $rules, $onPage = null, array $dd = [], ?ProjectShape $shape = null, $eventId = null, $instance = 1)
    {
        $locked = [];
        foreach ($rules as $r) {
            if (!is_array($r) || !empty($r['configError']) || !ModeRegistry::locks(ModeRegistry::modeOfType(isset($r['type']) ? $r['type'] : ''))) continue;
            foreach ((isset($r['fields']) && is_array($r['fields'])) ? $r['fields'] : [] as $f) $locked[(string) $f] = true;
        }
        if (!$locked) return $rules;
        $meta = function ($x) use ($dd) { return isset($dd[$x]) && is_array($dd[$x]) ? $dd[$x] : []; };
        $here = function ($x) use ($locked, $onPage) { return !isset($locked[$x]) && !(is_array($onPage) && !isset($onPage[$x])); };
        // a field that is no calc, as the entries the page compares: field => entry => true
        $plain = function ($x, $code) use ($here, $meta) {
            $m = $meta($x);
            if (!$here($x) || self::lockReadsLate($m)) return [];
            if ((isset($m['field_type']) ? $m['field_type'] : '') !== 'checkbox') return [$x => true];
            $codes = $code !== null ? [$code] : Logic::parseChoiceCodes(isset($m['select_choices_or_calculations']) ? $m['select_choices_or_calculations'] : '');
            $out = [];
            foreach ($codes as $c) $out[$x . '(' . $c . ')'] = true;
            return $out;
        };
        // whether "[token][field]" names this page's event: [event-name], or a name or smart
        // event name that resolves to it for the field's form (ProjectShape::eventId)
        $thisEvent = function ($token, $x) use ($shape, $eventId, $meta) {
            if ($token === 'event-name') return true;
            if ($shape === null || $eventId === null) return false;
            $m = $meta($x);
            $e = $shape->eventId($token, $eventId, isset($m['form_name']) ? (string) $m['form_name'] : '');
            return $e['state'] === 'ok' && (string) $e['event'] === (string) $eventId;
        };
        // every calc the rules read, however far round: calc => [field, code] it reads
        $calcs = [];
        $todo = [];
        foreach ($rules as $r) {
            if (is_array($r) && empty($r['configError'])) foreach (ModeRegistry::refFields($r) as $x) $todo[] = (string) $x;
        }
        while ($todo) {
            $x = array_pop($todo);
            if (isset($calcs[$x])) continue;
            $from = self::lockReadsCalc($meta($x), $thisEvent, $instance);
            if ($from === null) continue;
            $calcs[$x] = $from;
            foreach ($from as $ref) $todo[] = $ref[0];
        }
        // what each calc on this page is worked out from, grown until nothing changes
        $inputs = array_fill_keys(array_keys($calcs), []);
        do {
            $grew = false;
            foreach ($calcs as $c => $from) {
                if (!$here($c)) continue;
                foreach ($from as $ref) {
                    foreach (isset($calcs[$ref[0]]) ? $inputs[$ref[0]] : $plain($ref[0], $ref[1]) as $e => $_) {
                        if (!isset($inputs[$c][$e])) { $inputs[$c][$e] = true; $grew = true; }
                    }
                }
            }
        } while ($grew);
        foreach ($rules as $i => $r) {
            if (!is_array($r) || !empty($r['configError']) || ModeRegistry::locks(ModeRegistry::modeOfType(isset($r['type']) ? $r['type'] : ''))) continue;
            // a reason field (@UVJUSTIFY) only lets the rule's finding save: it is
            // no field the rule judges by, so it never makes a locked field hold the save
            $others = [];
            foreach (array_map('strval', ModeRegistry::refFields($r, ['cond', 'operand', 'fieldList'])) as $x) {
                foreach (isset($calcs[$x]) ? $inputs[$x] : $plain($x, null) as $e => $_) $others[$e] = true;
            }
            $marks = [];
            foreach ((isset($r['fields']) && is_array($r['fields'])) ? $r['fields'] : [] as $f) {
                if (isset($locked[(string) $f]) && $others) $marks[(string) $f] = array_keys($others);
            }
            if ($marks) $rules[$i]['lockReads'] = $marks;
        }
        return $rules;
    }

    /**
     * @UVJUSTIFY: a reason field stands for the broken rules of the modes a
     * reason covers (php/modes.json "justify") on the field it is written on.
     * Each live rule of such a mode on a field with a live justify rule gets
     * justifyBy (field => reason field), which the audit and the scan read
     * (ruleFindings, ModeRegistry::refFields); the page pairs a field with
     * its reason from the justify rule itself (QRIDJustifyInit). A justify
     * rule on a field that no rule of such a mode checks is a configuration
     * error on that field alone: a tag written alike on several fields is one
     * rule (AnnotationRules::groupMulti), so the field is split off into a
     * rule of its own, as markReturnClashes splits a lock.
     *
     * A justify rule whose reason field another live rule returns a value to
     * (ModeRegistry::returnFields, read as markReturnClashes reads it for a
     * lock) is refused on each of its fields: the save writes that field, so
     * it would hold a reason nobody wrote. A returning rule that is refused
     * itself writes nothing.
     */
    private static function applyJustify(array $rules)
    {
        $writer = [];       // field => the first field of the live rule that returns a value to it
        foreach ($rules as $r) {
            if (!is_array($r) || !empty($r['configError'])) continue;
            foreach (ModeRegistry::returnFields($r) as $f) {
                if (!isset($writer[$f])) $writer[$f] = isset($r['fields'][0]) ? (string) $r['fields'][0] : '?';
            }
        }
        $refused = [];
        foreach ($rules as $i => $r) {
            if (!$writer) break;
            if (!is_array($r) || !empty($r['configError']) || ModeRegistry::modeOfType(isset($r['type']) ? $r['type'] : '') !== 'justify') continue;
            $reason = isset($r['justifyReason']) && is_string($r['justifyReason']) ? $r['justifyReason'] : '';
            if (!isset($writer[$reason])) continue;
            $why = '"reason" names "[' . $reason . ']", which the rule on "' . $writer[$reason] . '" returns a value to'
                . ' — the save writes it, so it would hold a reason nobody wrote; name a field the person fills in.';
            $mine = [];
            foreach ((isset($r['fields']) && is_array($r['fields'])) ? $r['fields'] : [] as $f) {
                $mine[] = array_merge($r, ['fields' => [$f], 'configError' => AnnotationRules::TAG_JUSTIFY . ' on "' . $f . '": ' . $why]);
            }
            $rules[$i] = $mine ? array_shift($mine) : array_merge($r, ['configError' => AnnotationRules::TAG_JUSTIFY . ': ' . $why]);
            foreach ($mine as $x) $refused[] = $x;
        }
        foreach ($refused as $r) $rules[] = $r;
        $reasonOf = [];     // field => reason field
        foreach ($rules as $r) {
            if (!is_array($r) || !empty($r['configError']) || ModeRegistry::modeOfType(isset($r['type']) ? $r['type'] : '') !== 'justify') continue;
            if (!isset($r['justifyReason']) || !is_string($r['justifyReason'])) continue;
            foreach ((isset($r['fields']) && is_array($r['fields'])) ? $r['fields'] : [] as $f) $reasonOf[(string) $f] = $r['justifyReason'];
        }
        if (!$reasonOf) return $rules;
        // A field with a rule a reason covers. A rule refused for its own
        // configuration counts: its error already says what to fix.
        $covered = [];
        foreach ($rules as $i => $r) {
            if (!is_array($r) || !ModeRegistry::justifies(ModeRegistry::modeOfType(isset($r['type']) ? $r['type'] : ''))) continue;
            $by = [];
            foreach ((isset($r['fields']) && is_array($r['fields'])) ? $r['fields'] : [] as $f) {
                if (!isset($reasonOf[(string) $f])) continue;
                $covered[(string) $f] = true;
                $by[(string) $f] = $reasonOf[(string) $f];
            }
            if ($by && empty($r['configError'])) $rules[$i]['justifyBy'] = $by;
        }
        $tags = [];
        $not = [];
        foreach (ModeRegistry::all() as $m) {
            if ($m['mode'] === 'justify') continue;
            if ($m['justify'] === true) $tags[] = $m['tag']; else $not[] = $m['tag'];
        }
        $words = function (array $list) {
            $last = array_pop($list);
            return $list ? implode(', ', $list) . ' or ' . $last : (string) $last;
        };
        $why = 'no rule a reason can stand for checks this field — give it a rule of ' . $words($tags)
            . ($not ? ' (a reason does not stand for ' . $words($not) . ')' : '') . '.';
        $split = [];
        foreach ($rules as $i => $r) {
            if (!is_array($r) || !empty($r['configError']) || ModeRegistry::modeOfType(isset($r['type']) ? $r['type'] : '') !== 'justify') continue;
            $fields = isset($r['fields']) && is_array($r['fields']) ? $r['fields'] : [];
            $keep = [];
            $mine = [];
            foreach ($fields as $f) {
                if (isset($covered[(string) $f])) { $keep[] = $f; continue; }
                $mine[] = array_merge($r, ['fields' => [$f], 'configError' => AnnotationRules::TAG_JUSTIFY . ' on "' . $f . '": ' . $why]);
            }
            if (count($keep) === count($fields)) continue;
            if ($keep) {
                $rules[$i]['fields'] = $keep;
            } else {
                $rules[$i] = array_shift($mine);
            }
            foreach ($mine as $x) $split[] = $x;
        }
        foreach ($split as $r) $rules[] = $r;
        return $rules;
    }

    /**
     * Whether REDCap fills a field by itself after the page has started, by
     * its action tags: @LATITUDE and @LONGITUDE, which wait for the
     * browser's position, so the value they write would read as a change by
     * the person. Each is read where annotationTags reads a tag: the name
     * inside another tag's quoted value is text.
     */
    private static function lockReadsLate(array $meta)
    {
        $ann = isset($meta['field_annotation']) ? (string) $meta['field_annotation'] : '';
        return strpos($ann, '@') !== false && self::annotationTags($ann, ['LATITUDE', 'LONGITUDE']) !== [];
    }

    /**
     * The action tags of an annotation that $names lists (upper case, without
     * "@"), in order, each as [name, the text inside its parentheses or null].
     * A tag counts where it starts the annotation or follows a space, "(" or
     * ",", as inside @IF(...), and its name ends before anything but a letter,
     * a digit, "_" or "-"; letter case does not matter. Text in quotes is
     * passed over, so a tag named inside another tag's quoted value
     * (@PLACEHOLDER="near @LATITUDE") is not read, and a quote mark that is
     * never closed makes the rest of the annotation text. The text inside a
     * tag's parentheses runs to the parenthesis that closes the first, quotes
     * respected, or to the end of the annotation when none does.
     */
    private static function annotationTags($ann, array $names)
    {
        $ann = (string) $ann;
        $n = strlen($ann);
        $find = '/\G@(' . implode('|', array_map(function ($x) { return preg_quote((string) $x, '/'); }, $names)) . ')(?![\w-])[ \t]*/i';
        $out = [];
        $i = 0;
        while ($i < $n) {
            $c = $ann[$i];
            if ($c === '"' || $c === "'") {
                $end = strpos($ann, $c, $i + 1);
                if ($end === false) break;
                $i = $end + 1;
                continue;
            }
            if ($c !== '@' || ($i > 0 && strpos(" \t\r\n(,", $ann[$i - 1]) === false) || !preg_match($find, $ann, $m, 0, $i)) {
                $i++;
                continue;
            }
            $i += strlen($m[0]);
            $args = null;
            if ($i < $n && $ann[$i] === '(') {
                $depth = 0;
                for ($j = $i; $j < $n; $j++) {
                    $d = $ann[$j];
                    if ($d === '"' || $d === "'") {
                        $end = strpos($ann, $d, $j + 1);
                        if ($end === false) { $j = $n; break; }
                        $j = $end;
                    } elseif ($d === '(') {
                        $depth++;
                    } elseif ($d === ')' && --$depth === 0) {
                        break;
                    }
                }
                $args = (string) substr($ann, $i + 1, $j - $i - 1);
                $i = $j + 1;
            }
            $out[] = [strtoupper($m[1]), $args];
        }
        return $out;
    }

    /**
     * What a calc, @CALCTEXT or @CALCDATE field's value is worked out from on
     * this entry, as [field, checkbox code or null], or null for a field of
     * any other kind. Of the annotation only the text inside the parentheses
     * of @CALCTEXT(...) and @CALCDATE(...) is read (annotationTags), so a
     * field named in another tag's value is no input. A reference that names
     * an event ("[event][field]") reads that event's value: it is kept when
     * $thisEvent(event, field) says the event is this page's, and left out
     * otherwise, since no one changes that value on this page. A reference
     * followed by an instance reads that instance: "[current-instance]" and
     * the page's own instance number $instance keep it; "[previous-instance]",
     * "[next-instance]", "[first-instance]", "[last-instance]" and any other
     * number name another entry, and it is left out. The name of an
     * "[event][field]" pair's event is no field.
     */
    private static function lockReadsCalc(array $meta, callable $thisEvent, $instance = 1)
    {
        $type = isset($meta['field_type']) ? (string) $meta['field_type'] : '';
        $tags = self::annotationTags(isset($meta['field_annotation']) ? (string) $meta['field_annotation'] : '', ['CALCTEXT', 'CALCDATE']);
        if ($type !== 'calc' && !$tags) return null;
        $text = $type === 'calc' && isset($meta['select_choices_or_calculations']) ? (string) $meta['select_choices_or_calculations'] : '';
        foreach ($tags as $tag) $text .= ' ' . (string) $tag[1];
        preg_match_all('/\[([^\[\]]*)\]/', $text, $m, PREG_OFFSET_CAPTURE);
        $tokens = [];   // [inside the brackets, where it starts, where it ends, field, code]
        foreach ($m[0] as $k => $t) {
            $f = preg_match('/^([a-z][a-z0-9_]*)(?:\(([^()]*)\))?$/D', $m[1][$k][0], $p) ? $p : null;
            $tokens[] = [$m[1][$k][0], $t[1], $t[1] + strlen($t[0]), $f ? $f[1] : null, $f && isset($f[2]) ? $f[2] : null];
        }
        $out = [];
        foreach ($tokens as $k => $t) {
            if ($t[3] === null) continue;
            $next = isset($tokens[$k + 1]) && $tokens[$k + 1][1] === $t[2] ? $tokens[$k + 1] : null;
            if ($next !== null && $next[3] !== null) continue;   // the event of a pair
            if ($next !== null && (in_array($next[0], ['previous-instance', 'next-instance', 'first-instance', 'last-instance'], true)
                    || (ctype_digit($next[0]) && (int) $next[0] !== (int) $instance))) continue;   // another instance
            $prev = $k > 0 && $tokens[$k - 1][2] === $t[1] ? $tokens[$k - 1] : null;
            if ($prev !== null && !$thisEvent($prev[0], $t[3])) continue;   // another event
            $out[$t[3] . "\x1F" . $t[4]] = [$t[3], $t[4]];
        }
        return array_values($out);
    }

    /** Translate the repeatable "rules" project settings into engine rules. */
    private function getSettingRules($pid = null)
    {
        $out = [];
        $subs = $this->getSubSettings('rules', $pid);
        if (!is_array($subs)) return $out;
        $known = $this->projectFieldNames($pid);
        $types = $this->projectFieldTypes($pid);
        $choices = $this->projectFieldChoices($pid);
        $identifiers = $this->projectIdentifierFields($pid);
        $dd = $this->dataDictionary($pid);
        $layout = $pid ? $this->eventLayout($pid) : null;

        foreach ($subs as $s) {
            $rule = $this->settingRowToRule(is_array($s) ? $s : [], $known, $types, $choices, $identifiers, $this->temporalOptions($pid), $dd, $layout);
            if ($rule === null) continue;
            // THE ROW'S OWN ID, CARRIED ONTO THE RULE.
            //
            // ScanPlanner::identify() has always preferred a stored id and has
            // always said so ("a persistent id stored on the row is the right
            // answer, because it survives editing the rule"), and no project
            // ever had one - so the content-hash fallback was what ran, and it
            // cannot survive an edit. Worse, revision() deliberately discards
            // the field list, so two settings rules of the same type and options
            // on DIFFERENT fields share a stem and are told apart only by their
            // position in the list: dragging one row past another swapped their
            // identities and re-attributed every finding stored against them.
            //
            // Attached here rather than in settingRowToRule() because that
            // method returns from two branches and a rule that got its id in
            // only one of them is the same class of half-wiring this release is
            // about.
            if (isset($s['rule-uid']) && is_string($s['rule-uid'])
                    && preg_match('/^[0-9a-f]{16}\z/', $s['rule-uid'])) {
                $rule['rule-uid'] = $s['rule-uid'];
            }
            $out[] = $rule;
        }
        return $out;
    }

    /**
     * Build one engine rule from one settings-dialog row. Shared by the runtime
     * path (getSettingRules) and the save-time gate (validateSettings), so the
     * two can never disagree about what a valid rule is. Returns null for a row
     * with nothing to say, otherwise a rule array (with configError when bad).
     */
    /**
     * The author's own label and message, which belong to EVERY rule kind.
     *
     * These were read inside the constraint|required|unique branch only, so a
     * single or pooled rule - the check-character and ID kinds the module is
     * named after - silently discarded both. The Rule name column was therefore
     * permanently blank for them, MessageCatalog's first tier (the author's own
     * wording) was unreachable for them, and docs/TESTING.md told a tester to
     * verify a label that could never appear on the most common rule kind.
     */
    private static function applyAuthoring(array $rule, array $s)
    {
        if (isset($s['message']) && trim((string) $s['message']) !== '') {
            $rule['message'] = trim((string) $s['message']);
        }
        if (isset($s['rule-note']) && trim((string) $s['rule-note']) !== '') {
            $rule['note'] = trim((string) $s['rule-note']);
        }
        return $rule;
    }

    /**
     * The dialog's "compare text case-sensitively" checkbox, shared by every
     * rule kind: ticked = exact-case text in the rule's "when" and "assert".
     * Unticked leaves the key unset, i.e. the case-insensitive default. EM
     * checkbox values arrive as true / 'true' / '1' depending on the read path.
     */
    private static function settingCaseSensitive(array $s)
    {
        return isset($s['case-sensitive']) && in_array($s['case-sensitive'], [true, 'true', '1', 1], true);
    }

    private function settingRowToRule(array $s, $known, $types, $choices = null, $identifiers = null, array $opts = [], $dd = null, $layout = null)
    {
        // Stored settings can hold surprising shapes after upgrades or manual
        // edits; for these keys only scalars are meaningful — discard anything
        // else instead of warning or letting it reach the engine.
        foreach (['rule-type', 'fields-csv', 'when', 'case-sensitive', 'assert', 'assert-exact', 'message',
                  'unique-with', 'unique-also', 'unique-scope', 'unique-surveys', 'algorithm', 'source',
                  'suggest-fix', 'pattern', 'alternates-json', 'strip',
                  'keep-chars', 'id-lengths', 'id-min-len', 'id-max-len',
                  'expected-count', 'block-save'] as $k) {
            if (isset($s[$k]) && !is_scalar($s[$k])) unset($s[$k]);
        }
        // The rule KIND decides which boxes apply and which field types are
        // eligible. single|pooled are the two types of the check mode;
        // constraint (@UVASSERT-style) and required (@UVREQUIRED-style) are
        // the added modes — their rows read only their own boxes below.
        $ruleType = !empty($s['rule-type']) ? (string) $s['rule-type'] : 'single';
        $mode = ModeRegistry::modeOfType($ruleType);
        $fields = isset($s['fields']) ? $s['fields'] : [];
        if (!is_array($fields)) $fields = [$fields];
        $fields = array_values(array_filter($fields, function ($f) {
            return $f !== null && $f !== '';
        }));

        // Fast entry: comma/space-separated field names typed into one box —
        // the quick way to put many fields under one rule. Merged with (and
        // deduplicated against) the field pickers.
        $csvErrors = [];
        if (isset($s['fields-csv']) && trim((string) $s['fields-csv']) !== '') {
            $extra = preg_split('/[,;\s]+/', trim((string) $s['fields-csv']), -1, PREG_SPLIT_NO_EMPTY);
            foreach ($extra as $f) {
                $f = strtolower($f);
                if (!preg_match('/^[a-z][a-z0-9_]*$/', $f)) {
                    $csvErrors[] = 'fast-entry name "' . $f . '" is not a valid REDCap field name.';
                    continue;
                }
                if (!in_array($f, $fields, true)) $fields[] = $f;
            }
        }
        // Every field the admin referenced (pickers + valid-format fast-entry
        // names), captured BEFORE pruning to known fields so an all-invalid
        // rule can still surface its error instead of vanishing silently.
        $referenced = $fields;
        if ($known !== null) {
            $bad = array_values(array_diff($fields, $known));
            if ($bad) {
                $csvErrors[] = 'field(s) not in this project: ' . implode(', ', $bad)
                    . ' — check spelling against the data dictionary.';
                $fields = array_values(array_intersect($fields, $known));
            }
        }
        // A mode this dialog has no boxes for (php/modes.json "dialog": false)
        // is configured with its action tag. A stored row naming one (a hand
        // edit, or an upgrade) is refused visibly rather than assembled from
        // boxes that do not describe it.
        if ($fields && !ModeRegistry::inDialog($mode)) {
            return [
                'type'        => 'single',
                'fields'      => $fields,
                'configError' => 'rule type "' . $ruleType . '" cannot be set up in this dialog — use the '
                    . ModeRegistry::tag($mode) . ' action tag on the field instead.',
            ];
        }
        // Field-type eligibility is per MODE (COR-003, mirrored from the
        // annotation channel, both read from php/modes.json): check-character/
        // regex rules can only attach to Text/Notes inputs; a constraint reads
        // any scalar field's answer; a required rule additionally excludes calc
        // (the person entering data cannot fill a calc, so requiring one would
        // trap them).
        if ($types !== null && $fields) {
            $elig = ModeRegistry::eligibility($mode);
            $allowed = $elig['fieldTypes'];
            $why = $elig['dialogWhy'];
            $wrong = [];
            foreach ($fields as $f) {
                if (isset($types[$f]) && !in_array($types[$f], $allowed, true)) $wrong[] = $f . ' (' . $types[$f] . ')';
            }
            if ($wrong) {
                $csvErrors[] = $why . ' — remove: ' . implode(', ', $wrong) . '.';
                $fields = array_values(array_filter($fields, function ($f) use ($types, $allowed) {
                    return !isset($types[$f]) || in_array($types[$f], $allowed, true);
                }));
            }
        }
        if (!$fields) {
            if ($csvErrors) {
                // No valid field survived. Do NOT silently drop the rule — emit a
                // config-error rule so the mistake is visible (the client renders
                // it as a page-level notice when the named fields aren't present).
                return [
                    'type'        => 'single',
                    'fields'      => $referenced ?: ['(unknown field)'],
                    'configError' => implode(' ', $csvErrors),
                ];
            }
            return null;
        }

        // Constraint / Required / Unique rows: assemble ONLY their own keys —
        // the algorithm/pattern/pooled boxes visible in the shared dialog do
        // not apply to these modes (their labels say so) and must not leak
        // into the rule. checkFragment routes to the mode's own validator.
        if ($mode !== 'check') {
            $rule = ['type' => $ruleType, 'fields' => $fields];
            if ($mode === 'constraint' && isset($s['assert']) && trim((string) $s['assert']) !== '') {
                $rule['assert'] = trim((string) $s['assert']);
            }
            if ($mode === 'constraint' && isset($s['assert-exact']) && in_array($s['assert-exact'], [true, 'true', '1', 1], true)) {
                $rule['exact'] = true;
            }
            if ($mode === 'unique') {
                if (isset($s['unique-with']) && trim((string) $s['unique-with']) !== '') {
                    $rule['uniqueWith'] = array_map('strtolower',
                        preg_split('/[,;\s]+/', trim((string) $s['unique-with']), -1, PREG_SPLIT_NO_EMPTY));
                }
                if (isset($s['unique-also']) && trim((string) $s['unique-also']) !== '') {
                    $rule['uniqueAlso'] = array_map('strtolower',
                        preg_split('/[,;\s]+/', trim((string) $s['unique-also']), -1, PREG_SPLIT_NO_EMPTY));
                }
                if (!empty($s['unique-scope'])) $rule['uniqueScope'] = strtolower((string) $s['unique-scope']);
                if (isset($s['unique-surveys']) && in_array($s['unique-surveys'], [true, 'true', '1', 1], true)) {
                    $rule['uniqueSurveys'] = true;
                }
            }

            // The author's own name for this rule. config.json has offered

            if (!empty($s['block-save'])) $rule['blockSave'] = $s['block-save'];
            if (isset($s['when']) && trim((string) $s['when']) !== '') $rule['when'] = trim((string) $s['when']);
            if (self::settingCaseSensitive($s)) $rule['caseSensitive'] = true;

            $errors = $csvErrors;
            if (isset($s['references-json']) && trim((string)$s['references-json']) !== '') {
                $rule['references'] = json_decode($s['references-json'], true);
                if (!is_array($rule['references'])) $errors[] = 'Reference bindings must be a JSON object.';
            }
            foreach (AnnotationRules::checkFragment($rule, $opts) as $e) $errors[] = $e;
            // Dictionary-dependent reference checks for every condition key.
            foreach (ModeRegistry::condKeys() as $condKey) {
                if (isset($rule[$condKey]) && $types !== null) {
                    $w = Logic::parse($rule[$condKey], $opts);
                    if (!empty($w['ok'])) {
                        $refErrs = Logic::checkRefs($w['ast'], $types, is_array($choices) ? $choices : [], $condKey);
                        foreach ($refErrs as $e) $errors[] = $e;
                        $why = (!$refErrs && is_array($dd) && $dd) ? self::conditionKindProblem($w['ast'], $dd, $condKey, false) : null;
                        if ($why !== null) $errors[] = $why;
                    }
                }
            }
            // The survey opt-in may never sit on an Identifier field (see
            // SURVEY_ON_IDENTIFIER) — the same guard the annotation channel applies.
            // The identifier map is passed IN (like $types/$choices) — resolving
            // it here would need a project id this method does not have, and
            // falling back to getProjectId() is exactly the unreliable read
            // SEC-002 warns about: on an import/API context it returns null, the
            // dictionary comes back empty, and the guard would silently pass.
            if (!empty($rule['uniqueSurveys'])) {
                // The Identifier refusal covers the primary field(s), the
                // composite "with" fields (H-01) and the "also" fields — an
                // identifying value anywhere in the lookup makes the survey
                // answer an existence oracle.
                $withF = (isset($rule['uniqueWith']) && is_array($rule['uniqueWith'])) ? $rule['uniqueWith'] : [];
                $alsoF = (isset($rule['uniqueAlso']) && is_array($rule['uniqueAlso'])) ? $rule['uniqueAlso'] : [];
                $idField = self::firstIdentifier($identifiers, array_merge($fields, $withF, $alsoF));
                if ($idField !== null) $errors[] = 'field "' . $idField . '": ' . self::SURVEY_ON_IDENTIFIER;
                // And every "also" field on each covered field's own instrument.
                if ($idField === null && $alsoF && is_array($dd)) {
                    foreach ($fields as $f) {
                        $off = self::surveyAlsoProblem($dd, $f, $alsoF);
                        if ($off !== null) { $errors[] = $off; break; }
                    }
                }
            }
            // Composite-key fields: exist, scalar, and not one of the covered
            // fields (a self-composite is a tautology).
            if (isset($rule['uniqueWith']) && is_array($rule['uniqueWith']) && !$errors) {
                foreach (self::checkUniqueWith($rule['uniqueWith'], null, $types) as $e) $errors[] = $e;
                foreach ($fields as $f) {
                    if (in_array($f, $rule['uniqueWith'], true)) {
                        $errors[] = '"with" must not name a field this rule validates ("' . $f . '").';
                    }
                }
                if (!$errors) {
                    foreach ($fields as $f) {
                        $p = self::withPlacementProblem($rule['uniqueWith'], $f, $dd, $layout);
                        if ($p !== null) { $errors[] = $p; break; }
                    }
                }
            }
            // "also" fields: exist, hold one value, and store it the way each
            // covered field does. Naming a covered field is refused per field.
            if (isset($rule['uniqueAlso']) && is_array($rule['uniqueAlso']) && !$errors) {
                foreach (self::checkUniqueAlso($rule['uniqueAlso'], null, $types, null) as $e) $errors[] = $e;
                if (!$errors) {
                    foreach ($fields as $f) {
                        if (in_array($f, $rule['uniqueAlso'], true)) {
                            $errors[] = '"also" must not name a field this rule validates ("' . $f . '") — that field is always searched.';
                            continue;
                        }
                        foreach (self::checkUniqueAlso($rule['uniqueAlso'], $f, null, $dd) as $e) $errors[] = $e;
                        $p = self::alsoPlacementProblem($rule['uniqueAlso'], $f,
                            (isset($rule['uniqueWith']) && is_array($rule['uniqueWith'])) ? $rule['uniqueWith'] : [],
                            isset($rule['uniqueScope']) ? $rule['uniqueScope'] : 'project', $dd, $layout);
                        if ($p !== null) $errors[] = $p;
                    }
                }
            }
            if ($errors) $rule['configError'] = implode(' ', $errors);
            return self::applyAuthoring($rule, $s);
        }

        $rule = [
            'type'   => !empty($s['rule-type']) ? $s['rule-type'] : 'single',
            'fields' => $fields,
        ];
        // Canonicalize the algorithm: the dropdown already stores canonical
        // values, but a shorthand pasted into a future free-text channel (or a
        // hand-edited stored setting) resolves the same way the annotations do.
        if (!empty($s['algorithm']))  $rule['algorithm'] = AnnotationRules::canonicalAlgorithm((string) $s['algorithm']);
        if (!empty($s['source']))     $rule['source']    = $s['source'];
        if (!empty($s['block-save'])) $rule['blockSave'] = $s['block-save'];
        // Presence checks, not empty(): a pattern/strip/keep of the string "0"
        // is legitimate configuration, not an unset box (UX-002).
        if (isset($s['pattern']) && (string) $s['pattern'] !== '')    $rule['idPattern'] = (string) $s['pattern'];
        // Multi-format rules: the dialog holds the same JSON the action tag
        // does, and it is validated by the SAME checkFragment below, so a rule
        // one channel accepts can never be one another channel rejects.
        $altErr = null;
        if (isset($s['alternates-json']) && trim((string) $s['alternates-json']) !== '') {
            $decoded = json_decode(trim((string) $s['alternates-json']), true);
            if (!is_array($decoded)) {
                $altErr = 'the "accepted ID formats" box is not valid JSON — it must be a list like '
                    . '[{"pattern":"FC[1-9]-[0-9]{4}","algorithm":"none","lengths":[8]}].';
            } else {
                $norm = AnnotationRules::normalizeAlternates($decoded);
                if (isset($norm['error'])) $altErr = $norm['error'];
                else $rule['alternates'] = $norm['alternates'];
            }
        }
        if (isset($s['strip']) && (string) $s['strip'] !== '')        $rule['strip']     = (string) $s['strip'];
        if (isset($s['keep-chars']) && (string) $s['keep-chars'] !== '') $rule['keepChars'] = (string) $s['keep-chars'];
        // Optional "when" condition — the rule validates only while it is true.
        // A blank box simply never sets the key (in the annotation JSON channel
        // an explicit "when":"" is a config error instead — it hides a typo).
        if (isset($s['when']) && trim((string) $s['when']) !== '')    $rule['when'] = trim((string) $s['when']);
        if (self::settingCaseSensitive($s)) $rule['caseSensitive'] = true;
        // Opt-in check-character hint. EM checkbox values arrive as true /
        // 'true' / '1' depending on the read path — accept all three; anything
        // else (unchecked, null) leaves the key unset and the default (off).
        if (isset($s['suggest-fix']) && in_array($s['suggest-fix'], [true, 'true', '1', 1], true)) {
            $rule['suggestFix'] = true;
        }

        // Strict validation of the numeric controls: a bad value becomes a
        // visible per-rule config error instead of being silently coerced
        // (intval("abc") == 0 used to disable the check quietly) — UV-008.
        $errors = $csvErrors;

        if (isset($s['expected-count']) && trim((string) $s['expected-count']) !== '') {
            $ec = trim((string) $s['expected-count']);
            if (ctype_digit($ec) && (int) $ec > 0) $rule['expectedIds'] = (int) $ec;
            else $errors[] = 'Expected number of IDs must be a positive whole number (got "' . $ec . '").';
        }

        if (isset($s['id-lengths']) && trim((string) $s['id-lengths']) !== '') {
            $parts = preg_split('/[,\s]+/', trim((string) $s['id-lengths']), -1, PREG_SPLIT_NO_EMPTY);
            $lens = [];
            $bad = false;
            foreach ($parts as $p) {
                if (ctype_digit($p) && (int) $p > 0) $lens[] = (int) $p;
                else { $bad = true; break; }
            }
            if ($bad || !$lens) $errors[] = 'Exact ID length(s) must be positive whole numbers, e.g. "10" or "10, 12".';
            else $rule['idLengths'] = $lens;
        }
        foreach (['id-min-len' => 'idMinLen', 'id-max-len' => 'idMaxLen'] as $k => $rk) {
            if (isset($s[$k]) && trim((string) $s[$k]) !== '') {
                $v = trim((string) $s[$k]);
                if (ctype_digit($v) && (int) $v > 0) $rule[$rk] = (int) $v;
                else $errors[] = ($k === 'id-min-len' ? 'Minimum' : 'Maximum') . ' ID length must be a positive whole number.';
            }
        }

        // One shared semantic validator for every configuration channel:
        // algorithm/source/blockSave whitelists, pattern safety (ReDoS gate,
        // ASCII subset, compilability), none-needs-pattern, "when" syntax, and
        // the hard caps that bound the pooled parser's work (COR-002/PER-002).
        if (isset($s['references-json']) && trim((string)$s['references-json']) !== '') {
                $rule['references'] = json_decode($s['references-json'], true);
                if (!is_array($rule['references'])) $errors[] = 'Reference bindings must be a JSON object.';
            }
            foreach (AnnotationRules::checkFragment($rule, $opts) as $e) $errors[] = $e;

        // Dictionary-dependent "when" reference checks (field exists, checkbox
        // needs a real (code), no file/descriptive refs) — only when the
        // dictionary is available, like the field-name checks above.
        if (isset($rule['when']) && $types !== null) {
            $w = Logic::parse($rule['when'], $opts);
            if (!empty($w['ok'])) {
                $refErrs = Logic::checkRefs($w['ast'], $types, is_array($choices) ? $choices : []);
                foreach ($refErrs as $e) $errors[] = $e;
                $why = (!$refErrs && is_array($dd) && $dd) ? self::conditionKindProblem($w['ast'], $dd, 'when', false) : null;
                if ($why !== null) $errors[] = $why;
            }
        }

        if ($altErr !== null) array_unshift($errors, $altErr);
        if ($errors) $rule['configError'] = implode(' ', $errors);

        return self::applyAuthoring($rule, $s);
    }

    /**
     * Save-time gate for the Configure dialog (framework hook): reject a rule
     * set containing invalid rules BEFORE it is stored, so designers see the
     * problem in the dialog instead of data collectors seeing it on a form
     * (COR-002/UX-001). Defensive by design: if the submitted settings shape is
     * not recognized, validation falls back to the runtime config-error channel
     * rather than blocking saves.
     */
    public function validateSettings($settings)
    {
        try {
            if (!is_array($settings)) return null;
            if (!isset($settings['rules']) || !is_array($settings['rules'])) $settings['rules'] = [];
            $pid = null;
            try { $pid = $this->getProjectId(); } catch (\Throwable $e) {}
            $known = $pid ? $this->projectFieldNames($pid) : null;
            $types = $pid ? $this->projectFieldTypes($pid) : null;
            $choices = $pid ? $this->projectFieldChoices($pid) : null;
            $identifiers = $pid ? $this->projectIdentifierFields($pid) : null;
            $dd = $pid ? $this->dataDictionary($pid) : null;
            $layout = $pid ? $this->eventLayout($pid) : null;
            $wasEnabled = $this->temporalOptions($pid, true)['qualified'];
            $enabled = in_array($settings['enable-event-instance-refs'] ?? $wasEnabled, [true,1,'1','true'],true);
            // Turning the dialect off preserves its authored rules for reactivation.
            $parseExtended = $enabled || $wasEnabled;
            $errors = ($enabled && !$wasEnabled) ? $this->temporalActivationProblems($pid) : [];
            $errors = array_merge($errors, self::crossSettingsProblems($settings, $pid, $known, $identifiers));
            $zone = (isset($settings['window-timezone']) && is_string($settings['window-timezone']))
                ? trim($settings['window-timezone']) : '';
            if ($zone !== '' && !self::isClockZone($zone)) {
                $errors[] = 'Time zone for @UVWINDOW "notFuture": "' . $zone . '" is not a time zone name. '
                    . 'Use a name such as Africa/Douala or Europe/London, or leave it blank to use the time zone of the server.';
            }
            $clean = [];    // assembled live rules, for the cross-rule check below
            $rowNums = [];  // their 1-based dialog row numbers, for messages
            foreach (self::rowsFromFlatSettings($settings) as $i => $row) {
                $rule = $this->settingRowToRule($row, $known, $types, $choices, $identifiers, ['qualified' => $parseExtended], $dd, $layout);
                if ($rule === null) continue;
                if (!empty($rule['configError'])) {
                    $errors[] = 'Rule ' . ($i + 1) . ': ' . $rule['configError'];
                    continue;
                }
                $clean[] = $rule;
                $rowNums[] = $i + 1;
            }
            // Cross-rule sharing legality (branched validation): several rules
            // may cover one field only when the sharing is gated — reject an
            // illegal combination BEFORE it is stored, naming the dialog rows.
            // (Annotations are invisible at dialog-save time; cross-channel
            // conflicts surface at runtime as configError rules instead.)
            foreach (Branching::fieldConflicts($clean) as $field => $c) {
                $nums = [];
                foreach ($c['rules'] as $ri) $nums[] = 'Rule ' . $rowNums[$ri];
                $errors[] = implode(' and ', $nums) . ': ' . Branching::message($field, $c);
            }
            if ($errors) {
                return "The configuration was NOT saved — fix these problems first:\n- " . implode("\n- ", $errors);
            }
            return null;
        } catch (\Throwable $e) {
            return null; // never block settings saves on a validator crash
        }
    }

    /**
     * The problems in the @UVEXISTS cross-project rows of a Configure dialog
     * save: the aliases this project uses, and the projects it answers. A
     * blank row is ignored. $known and $identifiers are this project's field
     * names and Identifier flags (null when unreadable: field checks are then
     * left to the runtime, which refuses what it cannot read).
     * @return string[]
     */
    private static function crossSettingsProblems(array $settings, $pid, $known, $identifiers)
    {
        // Each column is read by instance index, as rowsFromFlatSettings reads
        // the rule rows: a column with a gap must not shift its values into
        // the row above.
        $col = function ($k) use ($settings) {
            return (isset($settings[$k]) && is_array($settings[$k])) ? $settings[$k] : [];
        };
        $rows = function () use ($col) {
            $n = 0;
            foreach (func_get_args() as $k) {
                foreach (array_keys($col($k)) as $i) if (is_int($i) && $i >= $n) $n = $i + 1;
            }
            return $n;
        };
        $isPid = function ($v) { return preg_match('/^[1-9][0-9]{0,9}$/', trim((string) $v)) === 1; };
        $errors = [];
        $aliases = $col('exists-alias');
        $aliasPids = $col('exists-alias-project');
        $seen = [];
        for ($i = 0, $n = $rows('exists-alias', 'exists-alias-project'); $i < $n; $i++) {
            $alias = isset($aliases[$i]) ? strtolower(trim((string) $aliases[$i])) : '';
            $target = isset($aliasPids[$i]) ? trim((string) $aliasPids[$i]) : '';
            if ($alias === '' && $target === '') continue;
            $where = '@UVEXISTS project alias ' . ($i + 1) . ': ';
            if (!preg_match('/^[a-z][a-z0-9_-]{0,39}$/', $alias)) {
                $errors[] = $where . 'the alias must start with a letter and hold only letters, digits, _ and - (at most 40).';
            } elseif (isset($seen[$alias])) {
                $errors[] = $where . 'the alias "' . $alias . '" is set up twice.';
            }
            $seen[$alias] = true;
            if (!$isPid($target)) {
                $errors[] = $where . 'choose the project the alias stands for.';
            } elseif ($pid && (int) $target === (int) $pid) {
                $errors[] = $where . 'the alias stands for this project — a lookup without "project" already looks here.';
            }
        }
        $projects = $col('exists-consumer-project');
        $targets = $col('exists-consumer-targets');
        $returns = $col('exists-consumer-returns');
        $modes = $col('exists-consumer-mode');
        $surveys = $col('exists-consumer-surveys');
        $seen = [];
        for ($i = 0, $n = $rows('exists-consumer-project', 'exists-consumer-targets', 'exists-consumer-returns', 'exists-consumer-mode', 'exists-consumer-surveys'); $i < $n; $i++) {
            $p = isset($projects[$i]) ? trim((string) $projects[$i]) : '';
            $t = isset($targets[$i]) ? trim((string) $targets[$i]) : '';
            if ($p === '' && $t === '') continue;
            $where = 'Projects that may look up values here, row ' . ($i + 1) . ': ';
            if (!$isPid($p)) {
                $errors[] = $where . 'choose the project that may ask.';
            } elseif ($pid && (int) $p === (int) $pid) {
                $errors[] = $where . 'this project does not need to be listed — its own lookups are always answered.';
            } elseif (isset($seen[(int) $p])) {
                $errors[] = $where . 'project ' . (int) $p . ' is listed twice; keep one row for it.';
            }
            if ($isPid($p)) $seen[(int) $p] = true;
            $fields = self::consumerTargets($t);
            if (!$fields) {
                $errors[] = $where . 'list the fields that project may search or filter on, separated by commas ("record" for the record ID).';
                continue;
            }
            foreach (array_keys($fields) as $f) {
                if ($f !== 'record' && is_array($known) && !in_array($f, $known, true)) {
                    $errors[] = $where . '"' . $f . '" is not a field in this project — check the spelling.';
                }
            }
            $mode = isset($modes[$i]) ? trim((string) $modes[$i]) : '';
            if ($mode !== '' && $mode !== 'rights' && $mode !== 'answer') $errors[] = $where . 'choose who gets an answer.';
            $sv = in_array(isset($surveys[$i]) ? $surveys[$i] : false, [true, 1, '1', 'true'], true);
            if ($sv && $mode !== 'answer') {
                $errors[] = $where . 'survey respondents can be answered only when any signed-in user is answered ("Who gets an answer").';
            }
            if ($mode === 'answer' && is_array($identifiers)) {
                foreach (array_keys($fields) as $f) {
                    // "record" is the record-ID field, the first field of the dictionary.
                    $name = $f === 'record' ? ((is_array($known) && $known) ? $known[0] : null) : $f;
                    if ($name !== null && isset($identifiers[$name])) {
                        $errors[] = $where . '"' . $f . '" is an Identifier, so it can be searched only by users with rights in '
                            . 'this project — choose "Only users who have rights" or remove the field.';
                    }
                }
            }
            // Values copied out ("return") are answered only to a user who may
            // read them here: never under "any signed-in user".
            $r = isset($returns[$i]) ? trim((string) $returns[$i]) : '';
            if ($r === '') continue;
            $rf = self::consumerTargets($r);
            if (!$rf) {
                $errors[] = $where . 'the fields it may copy must be field names separated by commas ("record" for the record ID).';
                continue;
            }
            foreach (array_keys($rf) as $f) {
                if ($f !== 'record' && is_array($known) && !in_array($f, $known, true)) {
                    $errors[] = $where . '"' . $f . '" (a field it may copy) is not a field in this project — check the spelling.';
                }
            }
            if ($mode === 'answer') {
                $errors[] = $where . 'values can be copied only by users with rights in this project — choose "Only users who '
                    . 'have rights", or leave the fields it may copy empty.';
            }
        }
        return $errors;
    }

    /**
     * Reassemble per-rule rows from the flat key => [per-instance values] shape
     * validateSettings() receives for repeatable sub-settings.
     */
    private static function rowsFromFlatSettings(array $settings)
    {
        $keys = ['references-json', 'rule-note', 'rule-type', 'fields', 'fields-csv', 'when', 'case-sensitive', 'assert', 'assert-exact', 'message',
                 'unique-with', 'unique-also', 'unique-scope', 'unique-surveys',
                 'algorithm', 'source',
                 'suggest-fix', 'pattern', 'alternates-json', 'strip', 'keep-chars', 'id-lengths', 'id-min-len', 'id-max-len',
                 'expected-count', 'block-save'];
        $n = count($settings['rules']);
        foreach ($keys as $k) {
            if (isset($settings[$k]) && is_array($settings[$k])) $n = max($n, count($settings[$k]));
        }
        $rows = [];
        for ($i = 0; $i < $n; $i++) {
            $row = [];
            foreach ($keys as $k) {
                $row[$k] = (isset($settings[$k]) && is_array($settings[$k]) && array_key_exists($i, $settings[$k]))
                    ? $settings[$k][$i] : null;
            }
            $rows[] = $row;
        }
        return $rows;
    }

    /**
     * Rules declared as @UVALIDATE field annotations. Parsing and validation live
     * in AnnotationRules (pure, unit-tested); this is only the REDCap glue. Tags
     * on non-text fields become a visible config error rather than a silent no-op.
     */
    private function getAnnotationRules($pid = null, ?array $scope = null)
    {
        // the @UVDELTA hooks remember answers for one build of the rules, read
        // with one set of options (deltaRemembered, deltaTagsAsWritten)
        $this->deltaHeldMemo = [];
        $this->deltaTagsMemo = [];
        $dd = $this->dataDictionary($pid);
        if (!$dd) return [];
        $perField = [];
        foreach ($dd as $name => $meta) {
            $ann = isset($meta['field_annotation']) ? (string) $meta['field_annotation'] : '';
            // Cheap pre-filter: every module tag starts with "@UV" (@UVALIDATE,
            // @UVASSERT, …). parseAllTags then finds the real, boundary-checked ones.
            if ($ann === '' || stripos($ann, '@UV') === false) continue;
            // A build for some fields (getRules $fields) reads their tags and
            // every tag that may return a value. A JSON escape could spell
            // "return", so a tag holding a backslash is read too.
            if ($scope !== null && !isset($scope[$name]) && stripos($ann, 'return') === false
                && strpos($ann, '\\') === false) continue;
            $frags = AnnotationRules::parseAllTags($ann, $this->temporalOptions($pid));
            if ($frags === null) continue; // no module tag (e.g. @UVALIDATED)
            // Field-type eligibility is per MODE (php/modes.json): check-
            // character/regex still needs a Text/Notes input; a constraint
            // (@UVASSERT) reads any scalar field's answer, so it accepts
            // dropdowns/dates/etc. A mode may add its own checks on the field's
            // dictionary row through its "field" hook.
            $ftype = isset($meta['field_type']) ? $meta['field_type'] : '';
            foreach ($frags as $k => $frag) {
                if (isset($frag['error'])) continue;
                $mode = ModeRegistry::modeOfType(isset($frag['type']) ? $frag['type'] : '');
                $why = ModeRegistry::ineligibleWhy($mode, $ftype);
                if ($why !== null) {
                    $frags[$k] = ['error' => $why, '_tag' => ModeRegistry::tag($mode)];
                    continue;
                }
                $hook = ModeRegistry::hook($mode, 'field');
                // $k, the tag's place in $frags, lets a hook tell the tags
                // before it apart (@UVDELTA, review R9-7); the others ignore it
                if ($hook !== null) $frags[$k] = $this->$hook($frag, $name, $meta, $pid, $k);
            }
            $perField[$name] = $frags;
        }
        if (!$perField) return [];
        // Dictionary-dependent reference checks for this channel — parseAllTags/
        // checkFragment already validated syntax; whether the referenced fields
        // exist (and checkbox codes are real) needs the dd, which is in hand
        // here. Every condition key is checked (the "when" gate, the "assert"
        // test, ...), then the mode's own "dictionary" hook runs.
        $types = null;
        $choices = null;
        foreach ($perField as $name => $frags) {
            foreach ($frags as $k => $frag) {
                if (isset($frag['error'])) continue;
                $mode = ModeRegistry::modeOfType(isset($frag['type']) ? $frag['type'] : '');
                foreach (ModeRegistry::condKeys() as $condKey) {
                    if (!isset($frag[$condKey])) continue;
                    $w = Logic::parse($frag[$condKey], $this->temporalOptions($pid));
                    if (empty($w['ok'])) continue; // syntax error already surfaced
                    if ($types === null) {
                        $types = $this->projectFieldTypes($pid);
                        $choices = $this->projectFieldChoices($pid);
                    }
                    $errs = Logic::checkRefs($w['ast'], $types === null ? [] : $types, $choices === null ? [] : $choices, $condKey);
                    if (!$errs && $types !== null) {
                        $ddNow = $this->dataDictionary($pid);
                        $why = (is_array($ddNow) && $ddNow) ? self::conditionKindProblem($w['ast'], $ddNow, $condKey, false) : null;
                        if ($why !== null) $errs = [$why];
                    }
                    if ($errs) {
                        $perField[$name][$k] = ['error' => implode(' ', $errs),
                            '_tag' => ModeRegistry::tag($mode)];
                        break;
                    }
                }
                if (isset($perField[$name][$k]['error'])) continue;
                $hook = ModeRegistry::hook($mode, 'dictionary');
                if ($hook === null) continue;
                if ($types === null) {
                    $types = $this->projectFieldTypes($pid);
                    $choices = $this->projectFieldChoices($pid);
                }
                $perField[$name][$k] = $this->$hook($frag, $name, $types, $choices, $pid, $k);
            }
        }
        return AnnotationRules::groupMulti($perField);
    }

    /**
     * @UVUNIQUE "field" hook: refuse the survey opt-in when the primary field,
     * any composite "with" field (H-01) or any "also" field is an Identifier,
     * naming the offending field so a composite hit is not mistaken for the
     * primary one. An "also" field is searched for the typed value, so an
     * answer of "already used" says that value is saved there.
     */
    private function annotateUniqueField(array $frag, $name, array $meta, $pid)
    {
        if (empty($frag['uniqueSurveys'])) return $frag;
        $withF = (isset($frag['uniqueWith']) && is_array($frag['uniqueWith'])) ? $frag['uniqueWith'] : [];
        $alsoF = (isset($frag['uniqueAlso']) && is_array($frag['uniqueAlso'])) ? $frag['uniqueAlso'] : [];
        $idField = self::firstIdentifier($this->projectIdentifierFields($pid), array_merge([$name], $withF, $alsoF));
        if ($idField === null) {
            $off = self::surveyAlsoProblem($this->dataDictionary($pid), $name, $alsoF);
            return $off === null ? $frag : ['error' => $off, '_tag' => AnnotationRules::TAG_UNIQUE];
        }
        $role = $idField === $name ? '' : (in_array($idField, $withF, true) ? 'composite "with" field "' : '"also" field "');
        return ['error' => ($role === '' ? '' : $role . $idField . '": ')
            . self::SURVEY_ON_IDENTIFIER, '_tag' => AnnotationRules::TAG_UNIQUE];
    }

    /**
     * Why the survey opt-in cannot go with these "also" fields, or null.
     *
     * A respondent's "already used" says the value is saved in one of the
     * searched fields. On the tagged field's own instrument those are fields
     * of the survey the respondent is filling in; on any other instrument
     * they may be fields no respondent ever sees, such as a staff-only note,
     * so the opt-in is refused for them. A dictionary that cannot place the
     * fields refuses too.
     */
    /**
     * Whether $field is on $instrument, the survey an unauthenticated caller is
     * taking. A field the dictionary cannot place, or a request with no
     * instrument, is not (fail closed).
     */
    private static function onSurveyInstrument($dd, $field, $instrument)
    {
        if ($instrument === null || $instrument === '') return false;
        $home = (is_array($dd) && isset($dd[$field]['form_name'])) ? (string) $dd[$field]['form_name'] : '';
        return $home !== '' && $home === (string) $instrument;
    }

    private static function surveyAlsoProblem($dd, $field, array $also)
    {
        if (!$also) return null;
        $home = (is_array($dd) && isset($dd[$field]['form_name'])) ? (string) $dd[$field]['form_name'] : null;
        foreach ($also as $a) {
            $form = (is_array($dd) && isset($dd[$a]['form_name'])) ? (string) $dd[$a]['form_name'] : null;
            if ($home !== null && $form === $home) continue;
            return '"also" field "' . $a . '" is not on the instrument of "' . $field . '" ("'
                . ($home === null ? '?' : $home) . '") — with "surveys": true every "also" field must be on that '
                . 'instrument, so a respondent is only told about fields of the survey they are filling in.';
        }
        return null;
    }

    /**
     * @UVUNIQUE "dictionary" hook: composite-key fields must exist and hold ONE
     * scalar value (checkbox is multi-valued; file/descriptive have no
     * comparable value), and "with" naming the field itself is a tautology,
     * not a composite. Each "also" field must exist, hold one value and store
     * it the way the field does (checkUniqueAlso).
     */
    private function annotateUniqueDictionary(array $frag, $name, $types, $choices, $pid = null)
    {
        $errs = [];
        if (isset($frag['uniqueWith'])) $errs = self::checkUniqueWith($frag['uniqueWith'], $name, $types);
        if (!$errs && isset($frag['uniqueWith']) && is_array($frag['uniqueWith']) && $frag['uniqueWith']) {
            $p = self::withPlacementProblem($frag['uniqueWith'], $name, $this->dataDictionary($pid), $pid ? $this->eventLayout($pid) : null);
            if ($p !== null) $errs[] = $p;
        }
        if (!$errs && isset($frag['uniqueAlso']) && is_array($frag['uniqueAlso'])) {
            $dd = $this->dataDictionary($pid);
            $errs = self::checkUniqueAlso($frag['uniqueAlso'], $name, $types, $dd);
            if (!$errs) {
                $p = self::alsoPlacementProblem($frag['uniqueAlso'], $name,
                    (isset($frag['uniqueWith']) && is_array($frag['uniqueWith'])) ? $frag['uniqueWith'] : [],
                    isset($frag['uniqueScope']) ? $frag['uniqueScope'] : 'project', $dd, $pid ? $this->eventLayout($pid) : null);
                if ($p !== null) $errs[] = $p;
            }
        }
        return $errs ? ['error' => implode(' ', $errs), '_tag' => AnnotationRules::TAG_UNIQUE] : $frag;
    }

    /**
     * @UVEXISTS "field" hook: the survey opt-in is refused when any field the
     * lookup touches is an Identifier — the field itself, the fields it is
     * matched by, the searched fields, and the record-ID field for a record-ID
     * lookup. "Found" on an identifying value is the same existence oracle the
     * @UVUNIQUE refusal closes. Fails CLOSED: flags that cannot be read refuse.
     */
    private function annotateExistsField(array $frag, $name, array $meta, $pid)
    {
        if (empty($frag['existsSurveys'])) return $frag;
        $ids = $this->projectIdentifierFields($pid);
        $touch = array_merge([$name],
            (isset($frag['existsLocal']) && is_array($frag['existsLocal'])) ? $frag['existsLocal'] : [],
            (isset($frag['existsTargets']) && is_array($frag['existsTargets'])) ? $frag['existsTargets'] : []);
        // The searched fields of another project are checked there
        // (annotateExistsRemote).
        if (($frag['existsIn'] ?? null) === 'record' && !isset($frag['existsProject'])) {
            $pk = $this->recordIdFieldOf($pid);
            if ($pk !== '') $touch[] = $pk;
        }
        $hit = $ids === null ? $name : self::firstIdentifier($ids, $touch);
        if ($hit === null) return $frag;
        return ['error' => ($ids === null
                ? 'the survey lookup ("surveys") cannot be enabled while the project\'s Identifier flags cannot be read.'
                : 'field "' . $hit . '" is an Identifier, so the survey lookup ("surveys") cannot be enabled: a survey '
                  . 'answer of "found" would let anyone holding the survey link test whether a value is in this study. '
                  . 'Drop "surveys" (staff still get the live check, and the post-save audit and the Validation scan '
                  . 'still cover survey answers), or un-flag the field if it is not identifying.'),
            '_tag' => AnnotationRules::TAG_EXISTS];
    }

    /**
     * @UVEXISTS "dictionary" hook: the searched field and each "match" field
     * must exist and hold one scalar value, the searched field must not be the
     * field itself, a pair of fields compared with each other must hold the
     * same kind of date (or both no date: REDCap stores dates as Y-M-D and
     * dates compare as stored) and, when both hold numbers, use the same
     * decimal mark, and "event" must name an event of the project. "where"
     * must work against the entry found (existsWhereProblem).
     */
    private function annotateExistsDictionary(array $frag, $name, $types, $choices, $pid = null)
    {
        if (isset($frag['existsProject'])) return $this->annotateExistsRemote($frag, $name, $types, $pid);
        $refuse = function ($why) { return ['error' => $why, '_tag' => AnnotationRules::TAG_EXISTS]; };
        $dd = $this->dataDictionary($pid);
        if (!is_array($dd) || !is_array($types)) return $refuse('the data dictionary could not be read, so "in" cannot be checked.');
        $scalar = self::EXISTS_SCALAR_TYPES;
        // The comparison is on stored text, so two fields match only when REDCap
        // stores them the same way: a datetime to the minute never equals one
        // to the second, nor a time to the minute one to the second.
        $family = function ($f) use ($dd) {
            return self::storedKindOf(isset($dd[$f]) ? self::validationOf($dd[$f]) : '');
        };
        $field = function ($f, $role) use ($types, $scalar) {
            if (!isset($types[$f])) return $role . ' "' . $f . '" is not a field in this project — check the spelling.';
            if (!in_array($types[$f], $scalar, true)) {
                return $role . ' "' . $f . '" is a ' . $types[$f] . ' field — the lookup needs one value per field.';
            }
            return null;
        };
        $in = isset($frag['existsIn']) ? $frag['existsIn'] : '';
        $pairs = [];
        if ($in !== 'record') {
            if ($in === $name) return $refuse('"in" names this field itself — a value is always found in its own field. Name the field that holds the saved values.');
            $why = $field($in, '"in" field');
            if ($why !== null) return $refuse($why);
            $pairs[] = [$in, $name];
        }
        foreach ((isset($frag['existsMatch']) && is_array($frag['existsMatch'])) ? $frag['existsMatch'] : [] as $t => $l) {
            $why = $field((string) $t, '"match" target');
            if ($why === null) $why = $field($l, '"match" field');
            if ($why !== null) return $refuse($why);
            if ($l === $name) return $refuse('"match" uses this field itself for "' . $t . '" — it is already the value looked up.');
            $pairs[] = [(string) $t, $l];
        }
        $mark = function ($f) use ($dd) { return self::numberMarkOf(isset($dd[$f]) ? $dd[$f] : null); };
        foreach ($pairs as list($a, $b)) {
            if ($family($a) !== $family($b)) {
                return $refuse('"' . $a . '" holds ' . $family($a) . ' and "' . $b . '" holds ' . $family($b)
                    . ' — values are compared as REDCap stores them, so both must be the same kind.');
            }
            $why = self::markMismatch($a, $mark($a), $b, $mark($b), '');
            if ($why !== null) return $refuse($why);
        }
        if (isset($frag['existsEvent'])) {
            if ($this->eventIdOf($pid, $frag['existsEvent']) === null) {
                return $refuse('"event" "' . $frag['existsEvent'] . '" is not an event of this project — use its unique event name.');
            }
        }
        if (isset($frag['existsWhere'])) {
            $why = $this->existsWhereProblem($frag, $name, $dd, $dd, $pid, '');
            if ($why !== null) return $refuse($why);
        }
        if (isset($frag['existsReturn'])) {
            $why = $this->existsReturnProblem($frag, $name, $dd, $dd, $pid, $pid, '', $this->projectIdentifierFields($pid));
            if ($why !== null) return $refuse($why);
        }
        // "in" on this field's own form (EXS-6): the page sends what that
        // field holds there now, and the record being edited is judged by it,
        // as the audit judges the saved record.
        if ($in !== 'record' && isset($dd[$in]['form_name'], $dd[$name]['form_name'])
                && (string) $dd[$in]['form_name'] === (string) $dd[$name]['form_name']) {
            $frag['existsSelfIn'] = (string) $in;
        }
        return $frag;
    }

    /**
     * Why the "return" of an exists rule on $name cannot work, or null; on
     * success $frag gains existsReturnNumbers (the fields here that hold
     * numbers, where "7.80" and "7.8" are one value). $ddThere is the
     * dictionary of the project searched ($pidThere; $there names it in a
     * message, '' for this one), $ddHere this project's; $idsThere its
     * Identifier flags (null: unreadable, every field counts as one).
     *
     * Each field there exists and holds one value, and is read from the entry
     * the lookup found: the form of "in" or a form that does not repeat (an
     * entry of another repeating form would be any of its instances). With
     * "in":"record" every field must be on a form that does not repeat, and a
     * longitudinal project needs "event", the event the values are read from.
     * In a longitudinal project each field's form must also be in every event
     * the entry can be found in ("event", or each event that holds the form
     * of "in", and with "scope":"event" this field's form too): elsewhere the
     * entry has no value for it. The record ID is in every event. Each field here
     * passes returnTargetBasics (on this form, not this field, not a field
     * the lookup reads, not the record ID, not filled by REDCap) and can hold
     * the value (existsReturnPairProblem). A field flagged Identifier there
     * may go only into a field flagged Identifier here, so a de-identified
     * export does not carry it.
     *
     * Another project's forms, events and choice codes are not named here: a
     * refusal that would name them gives CROSS_RETURN_UNAVAILABLE instead, as
     * annotateExistsRemote does for an event that is not one of its events.
     */
    private function existsReturnProblem(array &$frag, $name, array $ddThere, array $ddHere, $pidThere, $pidHere, $there, $idsThere)
    {
        $pkThere = $ddThere ? (string) array_keys($ddThere)[0] : null;
        $hide = function ($why) use ($there, $pidThere) {
            return $there === '' ? $why : sprintf(self::CROSS_RETURN_UNAVAILABLE, (int) $pidThere);
        };
        $shape = $this->existsEntryShape($frag, $name, $ddThere, $ddHere, $pidThere, $there, '"return"', 'copied');
        if (isset($shape['problem'])) return $shape['hide'] ? $hide($shape['problem']) : $shape['problem'];
        $idsHere = $this->projectIdentifierFields($pidHere);
        $inputs = [];
        foreach ((isset($frag['existsLocal']) && is_array($frag['existsLocal'])) ? $frag['existsLocal'] : [] as $l) $inputs[(string) $l] = true;
        if ($there === '') {
            foreach ((isset($frag['existsTargets']) && is_array($frag['existsTargets'])) ? $frag['existsTargets'] : [] as $t) $inputs[(string) $t] = true;
        }
        $numbers = [];
        foreach ($frag['existsReturn'] as $s => $t) {
            $s = (string) $s;
            $src = $s === 'record' ? $pkThere : $s;
            $short = '"return" "' . $s . '"';
            if ($src === null || !isset($ddThere[$src])) return $short . ' is not a field' . ($there === '' ? ' in this project' : $there) . ' — check the spelling.';
            $ms = $ddThere[$src];
            $ts = isset($ms['field_type']) ? (string) $ms['field_type'] : '';
            if (!in_array($ts, self::EXISTS_SCALAR_TYPES, true)) {
                return $short . $there . ' is a ' . $ts . ' field — "return" copies one value per field.';
            }
            // The record ID is in every event of the record, by either name.
            $why = self::existsEntryPlacement($shape, $frag, $ms, $short . $there, 'Copy', 'copy', $src !== $pkThere);
            if ($why !== null) return $hide($why);
            $label = $short . ' field "' . $t . '"';
            $why = self::returnTargetBasics((string) $t, $short, $label, $name, 'this field', $ddHere, $inputs,
                function (array $mt) use ($ms, $label, $s, $there) {
                    return self::existsReturnPairProblem($ms, $mt, $label, $s, $there);
                });
            if ($why !== null) return $why;
            $idThere = $idsThere === null || self::isIdentifier($idsThere, $src);
            if ($idThere && ($idsHere === null || !self::isIdentifier($idsHere, (string) $t))) {
                return $short . $there . ' is an Identifier' . ($idsThere === null ? ' (the Identifier flags could not be read)' : '')
                    . ', so "[' . $t . ']" must be flagged Identifier too — otherwise a de-identified export would carry it.';
            }
            if (self::numberMarkOf($ddHere[(string) $t]) !== null) $numbers[] = (string) $t;
        }
        $frag['existsReturnNumbers'] = $numbers;
        return null;
    }

    /**
     * Where an entry an exists rule finds can be, in project $pidThere, for a
     * part of the rule that reads fields out of it ($key: '"return"' or
     * '"where"'; $done: what is done with those values, for a message):
     * ['in', 'inForm' (the form of "in"; null with "in":"record"),
     * 'repeating' (form => true for the forms that repeat), 'events' (the
     * event ids the entry can be in: "event", or each event that holds the
     * form of "in", and with "scope":"event" this field's form too; null in a
     * classic project), 'byEvent' (event id => form => true)]. Or
     * ['problem' => why, 'hide' => whether the reason describes how the
     * project searched is built, which another project's refusal must not
     * show]. With "in":"record" a longitudinal project needs "event", an
     * event that does not repeat: the record's entry is its row there.
     */
    private function existsEntryShape(array $frag, $name, array $ddThere, array $ddHere, $pidThere, $there, $key, $done)
    {
        $in = isset($frag['existsIn']) ? (string) $frag['existsIn'] : '';
        $inForm = ($in !== 'record' && isset($ddThere[$in]['form_name'])) ? (string) $ddThere[$in]['form_name'] : null;
        $layout = $this->repeatingLayout($pidThere);
        if ($layout === null) {
            return ['problem' => 'which forms repeat in ' . ($there === '' ? 'this project' : 'project ' . (int) $pidThere)
                . ' could not be read, so ' . $key . ' cannot be checked.', 'hide' => false];
        }
        $long = $this->projectLongitudinal($pidThere);
        if ($in === 'record' && !isset($frag['existsEvent']) && $long !== false) {
            return ['problem' => $key . ' with "in":"record" needs "event" in a longitudinal project: the event whose values are '
                . $done . '.', 'hide' => true];
        }
        $events = null;
        $byEvent = [];
        if ($long !== false) {
            $byEvent = $this->eventForms($pidThere);
            if ($byEvent === null) {
                return ['problem' => 'which forms each event holds in ' . ($there === '' ? 'this project' : 'project ' . (int) $pidThere)
                    . ' could not be read, so ' . $key . ' cannot be checked.', 'hide' => false];
            }
            if (isset($frag['existsEvent'])) {
                $ev = $there === '' ? $this->eventIdOf($pidThere, $frag['existsEvent']) : $this->eventIdIn($pidThere, $frag['existsEvent']);
                $events = $ev === null ? [] : [(int) $ev];
            } else {
                // "scope":"event" looks only in the event of the entry being
                // edited, so only in events that also hold this field's form
                // (the scope is this project's: refused with "project").
                $home = (($frag['existsScope'] ?? null) === 'event' && $there === '' && isset($ddHere[$name]['form_name']))
                    ? (string) $ddHere[$name]['form_name'] : null;
                $events = [];
                foreach ($byEvent as $e => $forms) {
                    if ($inForm === null || !isset($forms[$inForm])) continue;
                    if ($home !== null && !isset($forms[$home])) continue;
                    $events[] = (int) $e;
                }
            }
        }
        if ($in === 'record' && $events && isset($layout['events'][$events[0]])) {
            return ['problem' => $key . ' with "in":"record" reads one entry of "event" "' . $frag['existsEvent'] . '"' . $there
                . ', and that event repeats — name an event that does not repeat.', 'hide' => true];
        }
        return ['in' => $in, 'inForm' => $inForm, 'repeating' => $layout['forms'], 'events' => $events, 'byEvent' => $byEvent];
    }

    /**
     * Why a field of the project searched ($meta, its dictionary row) cannot
     * be read from the one entry an exists rule finds (existsEntryShape
     * $shape), or null: it is on another form that repeats, or (when
     * $checkEvents: the record ID is in every event) on a form missing from
     * an event the entry can be in. $label starts the message; $Verb/$verb
     * say what the rule does with such fields ("Copy", "Test").
     */
    private static function existsEntryPlacement(array $shape, array $frag, array $meta, $label, $Verb, $verb, $checkEvents = true)
    {
        $form = isset($meta['form_name']) ? (string) $meta['form_name'] : '';
        $in = $shape['in'];
        // inForm is null with "in":"record": no repeating form is allowed
        if (isset($shape['repeating'][$form]) && $form !== $shape['inForm']) {
            return $label . ' is on the repeating form "' . $form . '"'
                . ($in === 'record' ? '' : ', not on "' . (string) $shape['inForm'] . '" with "' . $in . '"')
                . ' — the lookup finds one entry, and that form holds many. ' . $Verb
                . ' fields of the form the lookup searches, or of a form that does not repeat.';
        }
        if ($checkEvents && $shape['events'] !== null && $form !== $shape['inForm']) {
            foreach ($shape['events'] as $e) {
                if (isset($shape['byEvent'][$e][$form])) continue;
                return $label . ' is on the form "' . $form . '", which is not in every event '
                    . (isset($frag['existsEvent']) ? '"' . $frag['existsEvent'] . '" names' : 'that holds "' . (string) $shape['inForm'] . '"')
                    . ' — the entry found there has no value for it. Name an "event" that holds both forms, or ' . $verb
                    . ' fields of a form every such event holds.';
            }
        }
        return null;
    }

    /**
     * Why the "where" of an exists rule on $name cannot work against project
     * $pidThere (dictionary $ddThere; $there names it in a message, '' for
     * this one), or null:
     *   - each field it tests is a field there, a checkbox tested as
     *     [field(code)] with one of its codes (Logic::checkRefs);
     *   - a field compared with a field holds the same kind of date, time or
     *     neither, and numbers with the same decimal mark;
     *   - a date, datetime or time field is compared with a literal written
     *     as REDCap stores it (Y-M-D, H:M), a number field with no comma in
     *     its literal ("3,5" would be read as text: write "3.5");
     *   - each field can be read from the entry found (existsEntryShape,
     *     existsEntryPlacement): a field the entry has no value for would read
     *     blank, and a blank passes "<>".
     * Another project's refusal is CROSS_FILTER_UNAVAILABLE whenever the
     * reason would describe its fields, codes, forms or events.
     */
    private function existsWhereProblem(array $frag, $name, array $ddThere, array $ddHere, $pidThere, $there)
    {
        $hide = function ($why) use ($there, $pidThere) {
            return $there === '' ? $why : sprintf(self::CROSS_FILTER_UNAVAILABLE, (int) $pidThere);
        };
        $p = Logic::parse((string) $frag['existsWhere']);
        if (empty($p['ok'])) return 'the "where" condition ' . $p['error'];
        $types = [];
        $choices = [];
        foreach ($ddThere as $f => $m) {
            $types[$f] = isset($m['field_type']) ? (string) $m['field_type'] : '';
            if ($types[$f] === 'checkbox') {
                $choices[$f] = Logic::parseChoiceCodes(isset($m['select_choices_or_calculations']) ? $m['select_choices_or_calculations'] : '');
            }
        }
        $errs = Logic::checkRefs($p['ast'], $types, $choices, 'where');
        if ($errs) return $hide(implode(' ', $errs));
        $why = self::conditionKindProblem($p['ast'], $ddThere, 'where', true);
        if ($why !== null) return $hide($why);
        $shape = $this->existsEntryShape($frag, $name, $ddThere, $ddHere, $pidThere, $there, '"where"', 'tested');
        if (isset($shape['problem'])) return $shape['hide'] ? $hide($shape['problem']) : $shape['problem'];
        $pkThere = $ddThere ? (string) array_keys($ddThere)[0] : null;
        foreach (AnnotationRules::existsWhereFieldsOf((string) $frag['existsWhere']) as $f) {
            $why = self::existsEntryPlacement($shape, $frag, $ddThere[$f], '"where" field "[' . $f . ']"' . $there, 'Test', 'test',
                $f !== $pkThere);
            if ($why !== null) return $hide($why);
        }
        return null;
    }

    /**
     * The first comparison of a parsed condition that cannot answer as meant
     * against the fields of $dd, or null. $key names the condition in the
     * message ("where", "assert", "when"). Values are compared as REDCap
     * stores them, so two fields must hold the same kind of date or time, and
     * a literal compared with a date, a time or a number must be written the
     * stored way (AST-4: '01-01-2000' against a D-M-Y field compared as text
     * and passed every date before 2000). $sameMark also asks two number
     * fields for the same decimal mark: a "where" compares stored text, while
     * "assert" and "when" read each number by its own mark (AST-1).
     */
    private static function conditionKindProblem(array $n, array $dd, $key, $sameMark)
    {
        if ($n[0] === 'and' || $n[0] === 'or') {
            foreach ($n[1] as $c) {
                $why = self::conditionKindProblem($c, $dd, $key, $sameMark);
                if ($why !== null) return $why;
            }
            return null;
        }
        if ($n[0] === 'not') return self::conditionKindProblem($n[1], $dd, $key, $sameMark);
        if ($n[0] !== 'cmp') return null;
        $label = '"' . $key . '"';
        // A checkbox box ([cb(code)]) reads "1" or "0": no kind to compare.
        $field = function ($op) {
            return (($op[0] === 'ref' || $op[0] === 'qref') && $op[2] === null) ? $op[1] : null;
        };
        $kind = function ($f) use ($dd) { return self::storedKindOf(isset($dd[$f]) ? self::validationOf($dd[$f]) : ''); };
        $mark = function ($f) use ($dd) { return self::numberMarkOf(isset($dd[$f]) ? $dd[$f] : null); };
        $a = $field($n[2]);
        $b = $field($n[3]);
        if ($a !== null && $b !== null) {
            if ($kind($a) !== $kind($b)) {
                return $label . ' compares "[' . $a . ']", which holds ' . $kind($a) . ', with "[' . $b . ']", which holds '
                    . $kind($b) . ' — values are compared as REDCap stores them, so both must be the same kind.';
            }
            return $sameMark ? self::markMismatch($a, $mark($a), $b, $mark($b), '') : null;
        }
        $f = $a !== null ? $a : $b;
        $lit = $n[2][0] === 'lit' ? $n[2] : ($n[3][0] === 'lit' ? $n[3] : null);
        if ($f === null || $lit === null || $lit[1] === '') return null;
        $k = $kind($f);
        $v = (string) $lit[1];
        $shown = '"[' . $f . ']"';
        if (strpos($k, 'dates') === 0 && !preg_match(AnnotationRules::WINDOW_LITERAL_RE, $v)) {
            return $label . ' compares ' . $shown . ', which holds ' . $k . ', with \'' . $v . '\' — dates are compared as REDCap '
                . 'stores them, so write it as Y-M-D, such as \'2026-03-01\'' . ($k === 'dates' ? '' : ' or \'2026-03-01 08:30\'') . '.';
        }
        if (strpos($k, 'times') === 0 && !preg_match('/^\d{2}:\d{2}(?::\d{2})?$/D', $v)) {
            return $label . ' compares ' . $shown . ', which holds ' . $k . ', with \'' . $v . '\' — write the time as REDCap stores it, such as \'08:30\'.';
        }
        if ($k === 'minutes and seconds' && !preg_match('/^\d{2}:\d{2}$/D', $v)) {
            return $label . ' compares ' . $shown . ', which holds ' . $k . ', with \'' . $v . '\' — write it as REDCap stores it, such as \'02:30\'.';
        }
        if ($mark($f) !== null && strpos($v, ',') !== false) {
            return $label . ' compares ' . $shown . ', which holds numbers, with \'' . $v . '\' — write numbers in ' . $label . ' with a '
                . 'decimal point, such as \'' . str_replace(',', '.', $v) . '\'.';
        }
        return null;
    }

    /**
     * Why field $mt here cannot hold every value field $ms there may hold, or
     * null. A choice (dropdown, radio, yes/no, true/false) goes into a choice
     * field that has every one of its codes, or into a Text field without
     * validation, which gets the code. A Text field with validation takes a
     * value of the same kind: the same kind of date or time (the order the
     * date is shown in may differ: REDCap stores Y-M-D), a number with the
     * same decimal mark and, when the field fixes the decimal places
     * (integer, number_Ndp), from a field that fixes the same number of
     * them, or any other validation only from the
     * same one. A Notes field and a Text field without validation take any
     * value. Nothing goes into a calc, slider, SQL, checkbox, file or
     * descriptive field.
     */
    private static function existsReturnPairProblem(array $ms, array $mt, $label, $s, $there)
    {
        $choice = ['dropdown', 'radio', 'yesno', 'truefalse'];
        $ts = isset($ms['field_type']) ? (string) $ms['field_type'] : '';
        $tt = isset($mt['field_type']) ? (string) $mt['field_type'] : '';
        if (!in_array($tt, array_merge(['text', 'notes'], $choice), true)) {
            return $label . ' is a ' . $tt . ' field — a copied value goes into a Text, Notes, dropdown, radio, yes/no or true/false field.';
        }
        $vs = strtolower(self::validationOf($ms));
        $vt = strtolower(self::validationOf($mt));
        if (in_array($tt, $choice, true)) {
            if (!in_array($ts, $choice, true)) {
                return $label . ' is a ' . $tt . ' field, and "' . $s . '"' . $there . ' is a ' . $ts
                    . ' field — a choice field takes the code of a choice field.';
            }
            $missing = array_diff(self::choiceCodesOf($ms), self::choiceCodesOf($mt));
            if ($missing) {
                // Another project's codes are not shown (existsReturnProblem).
                if ($there !== '') {
                    return $label . ' lacks a choice code that "' . $s . '"' . $there . ' can hold — give it every code of that field.';
                }
                return $label . ' has no choice ' . implode(', ', array_map(function ($c) { return '"' . $c . '"'; }, array_slice(array_values($missing), 0, 5)))
                    . ' that "' . $s . '" can hold — give it every code of that field.';
            }
            return null;
        }
        if ($tt === 'notes' || $vt === '') return null;
        if (in_array($ts, $choice, true) || $ts === 'sql') {
            return $label . ' has "' . $vt . '" validation, and "' . $s . '"' . $there . ' holds choice codes — copy it into a '
                . 'Text field without validation, or into a choice field.';
        }
        $kindS = self::storedKindOf($vs);
        $kindT = self::storedKindOf($vt);
        if ($kindS !== 'no date or time' || $kindT !== 'no date or time') {
            if ($kindS !== $kindT) {
                return $label . ' holds ' . $kindT . ' and "' . $s . '"' . $there . ' holds ' . $kindS . ' — the value is copied as '
                    . 'REDCap stores it, so both must be the same kind.';
            }
            return null;
        }
        $markT = self::numberMarkOf($mt);
        if ($markT !== null) {
            $markS = self::numberMarkOf($ms);
            if ($markS === null) {
                return $label . ' holds numbers and "' . $s . '"' . $there . ' does not — give "' . $s . '" number validation, or '
                    . 'copy it into a Text field without validation.';
            }
            $why = self::markMismatch($s, $markS, (string) ($mt['field_name'] ?? 'the field here'), $markT, $there);
            if ($why !== null) return $label . ': ' . $why;
            // REDCap's number_Ndp takes exactly N decimal places, and the value
            // is copied as saved: "7" fails number_1dp as "7.25" does.
            $placesS = self::numberPlaces($ms);
            $placesT = self::numberPlaces($mt);
            if ($placesT !== null && $placesS !== $placesT) {
                return $label . ' takes ' . ($placesT === 0 ? 'whole numbers' : 'numbers with exactly ' . $placesT . ' decimal place' . ($placesT === 1 ? '' : 's'))
                    . ' and "' . $s . '"' . $there . ' holds ' . ($placesS === null ? 'any number of decimals' : ($placesS === 0 ? 'whole numbers' : $placesS . ' decimal place' . ($placesS === 1 ? '' : 's')))
                    . ' — give it the same validation as "' . $s . '", or number validation without a place count.';
            }
            return null;
        }
        if ($vs !== $vt) {
            return $label . ' has "' . $vt . '" validation and "' . $s . '"' . $there . ' has ' . ($vs === '' ? 'none' : '"' . $vs . '"')
                . ' — a copied value must pass the field\'s validation, so both need the same one (or none here).';
        }
        return null;
    }

    /**
     * The decimal places a number field allows: 0 for an integer, N for
     * number_Ndp (comma or point), null for any (number, float, a calc, a
     * slider: whole numbers, but REDCap stores what it is given). Only asked
     * of fields numberMarkOf says hold numbers.
     */
    private static function numberPlaces(array $meta)
    {
        $type = isset($meta['field_type']) ? (string) $meta['field_type'] : '';
        if ($type !== 'text') return null;
        $v = strtolower(self::validationOf($meta));
        if ($v === 'int' || $v === 'integer') return 0;
        if (preg_match('/^number_([1-9])dp(?:_comma_decimal)?$/', $v, $m)) return (int) $m[1];
        return null;
    }

    /** The choice codes of a dropdown or radio row; yes/no and true/false hold 1 and 0. */
    private static function choiceCodesOf(array $meta)
    {
        $type = isset($meta['field_type']) ? (string) $meta['field_type'] : '';
        if ($type === 'yesno' || $type === 'truefalse') return ['1', '0'];
        return Logic::parseChoiceCodes(isset($meta['select_choices_or_calculations']) ? $meta['select_choices_or_calculations'] : '');
    }

    /**
     * What repeats in project $pid, or null when that cannot be read:
     * 'forms' => the instruments that repeat on their own in any event
     * (form => true), 'events' => the events that repeat as a whole
     * (event_id => true). REDCap lists a repeating event as "WHOLE", as a
     * node keyed '' or as a bare list; every form of its instance belongs to
     * that one instance, so its forms are not listed.
     */
    private function repeatingLayout($pid)
    {
        $map = $this->repeatingMap($pid);
        if ($map === null) return null;
        $out = ['forms' => [], 'events' => []];
        foreach ($map ?: [] as $event => $node) {
            $whole = !is_array($node);
            foreach (is_array($node) ? $node : [] as $form => $_) {
                if (is_string($form) && $form !== '') $out['forms'][$form] = true;
                else $whole = true;
            }
            if ($whole) $out['events'][(int) $event] = true;
        }
        return $out;
    }

    /**
     * Where REDCap's map of what repeats is read from, in order of trust. Each
     * name is a method repeatMapFrom<Name>($pid). REDCap 17 exposes the map
     * on the \Project object only; some builds also offer the static
     * \REDCap::getRepeatingFormsEvents. Add a source here and a method below.
     */
    const REPEAT_MAP_SOURCES = ['Project', 'Static'];

    /**
     * REDCap's map of what repeats in project $pid: [event_id => node], a node
     * being [form => custom label] for the forms that repeat on their own, or
     * "WHOLE", a node keyed '' or a bare list for an event that repeats as a
     * whole. false when a source answered null or false (each caller keeps
     * its own reading of that); null when no source could be read. The first
     * source that is offered and answers with an array, null or false
     * decides; one that is not offered, throws or answers anything else hands
     * over to the next.
     */
    private function repeatingMap($pid)
    {
        foreach (self::REPEAT_MAP_SOURCES as $name) {
            try {
                $got = $this->{'repeatMapFrom' . $name}((int) $pid);
            } catch (\Throwable $e) {
                continue;
            }
            if (!is_array($got) || !$got[0]) continue;
            if (is_array($got[1])) return $got[1];
            if ($got[1] === null || $got[1] === false) return false;
        }
        return null;
    }

    /** [offered, answer] from \Project::getRepeatingFormsEvents() of project $pid. */
    private function repeatMapFromProject($pid)
    {
        $p = $this->projectObject($pid);
        if (!$p || !is_callable([$p, 'getRepeatingFormsEvents'])) return [false, null];
        return [true, $p->getRepeatingFormsEvents()];
    }

    /** [offered, answer] from the static \REDCap::getRepeatingFormsEvents($pid). */
    private function repeatMapFromStatic($pid)
    {
        if (!is_callable(['\REDCap', 'getRepeatingFormsEvents'])) return [false, null];
        return [true, \REDCap::getRepeatingFormsEvents($pid)];
    }

    /** The forms of each event of project $pid (event_id => form => true), or null when they cannot be read. */
    private function eventForms($pid)
    {
        $p = $this->projectObject($pid);
        if (!$p || !isset($p->eventsForms) || !is_array($p->eventsForms)) return null;
        $out = [];
        foreach ($p->eventsForms as $e => $forms) {
            if (!is_array($forms)) continue;
            foreach ($forms as $f) if (is_string($f) && $f !== '') $out[(int) $e][$f] = true;
        }
        return $out;
    }

    /** Whether project $pid is longitudinal, or null when that cannot be read. */
    private function projectLongitudinal($pid)
    {
        $p = $this->projectObject($pid);
        if ($p && isset($p->longitudinal)) return (bool) $p->longitudinal;
        return null;
    }

    /**
     * Why two fields compared by value cannot be (one holds numbers with a
     * decimal point, the other with a decimal comma), or null. $where names
     * the project of $a when it is another one.
     */
    private static function markMismatch($a, $markA, $b, $markB, $where)
    {
        if ($markA === null || $markB === null || $markA === $markB) return null;
        return '"' . $a . '"' . $where . ' holds ' . self::numberMarkWords($markA) . ' and "' . $b . '" holds '
            . self::numberMarkWords($markB) . ' — numbers are compared by value, so both must use the same decimal mark.';
    }

    /** Field types whose saved value is one comparable value. */
    const EXISTS_SCALAR_TYPES = ['text', 'notes', 'dropdown', 'radio', 'yesno', 'truefalse', 'sql', 'slider', 'calc'];

    /**
     * The @UVEXISTS "dictionary" hook for a rule that searches another project,
     * in this order: the server switch, the alias, the other project's
     * agreement (one message for every reason, CROSS_UNAVAILABLE), and only
     * then that project's dictionary: its searched fields exist and hold one
     * value, each compared pair holds the same kind of date, "event" is one of
     * its events, "surveys" is allowed there, and under the "answer" agreement
     * no searched field is an Identifier there. The "where" fields are
     * searched fields here: the agreement must list them, and "answer" refuses
     * an Identifier among them. "where" must work against that project's
     * entries (existsWhereProblem, one refusal that names nothing there). The
     * resolved project id is kept on the rule as existsPid.
     */
    private function annotateExistsRemote(array $frag, $name, $types, $pid)
    {
        // A REFUSAL RESTING ON A READ THAT FAILED IS NOTED (review R5-4). It
        // stays a refusal - the lookup cannot be checked - but it becomes a
        // configuration error the project does not really have, and the scan
        // fingerprint carries it.
        $b = null;
        $refuse = function ($why) use ($pid, &$b) {
            if ($this->crossUnread($b === null ? 0 : $b)) {
                $this->noteConfigUnread($pid, 'another project\'s settings');
            }
            return ['error' => $why, '_tag' => AnnotationRules::TAG_EXISTS];
        };
        if (!$this->crossProjectOn()) {
            return $refuse('looking in another project ("project") is turned off on this REDCap server — an '
                . 'administrator can turn it on in the module\'s Control Center settings.');
        }
        $b = $this->existsProjectPid($pid, $frag['existsProject']);
        if ($b === null) {
            return $refuse('"project":"' . $frag['existsProject'] . '" is not an alias set up in this project — add it '
                . 'under "@UVEXISTS project aliases" in the module\'s project settings, or use the project id.');
        }
        if ((int) $b === (int) $pid) return $refuse('"project" names this project — leave "project" out to look in this project.');
        $frag['existsPid'] = $b;
        $c = $this->crossGate($pid, $frag);
        if ($c === null) return $refuse(sprintf(self::CROSS_UNAVAILABLE, $b));
        $ddB = $this->dataDictionary($b);
        $ddA = $this->dataDictionary($pid);
        if (!is_array($ddB) || !is_array($ddA) || !is_array($types)) {
            return $refuse('the data dictionary of project ' . $b . ' or of this project could not be read, so the lookup cannot be checked.');
        }
        $remote = function ($f, $role) use ($ddB, $b) {
            if (!isset($ddB[$f])) return $role . ' "' . $f . '" is not a field of project ' . $b . ' — check the spelling.';
            $t = isset($ddB[$f]['field_type']) ? (string) $ddB[$f]['field_type'] : '';
            if (!in_array($t, self::EXISTS_SCALAR_TYPES, true)) {
                return $role . ' "' . $f . '" of project ' . $b . ' is a ' . $t . ' field — the lookup needs one value per field.';
            }
            return null;
        };
        $kindB = function ($f) use ($ddB) { return self::storedKindOf(isset($ddB[$f]) ? self::validationOf($ddB[$f]) : ''); };
        $kindA = function ($f) use ($ddA) { return self::storedKindOf(isset($ddA[$f]) ? self::validationOf($ddA[$f]) : ''); };
        $pairs = [];
        $in = isset($frag['existsIn']) ? $frag['existsIn'] : '';
        if ($in !== 'record') {
            $why = $remote($in, '"in" field');
            if ($why !== null) return $refuse($why);
            $pairs[] = [$in, $name];
        }
        foreach ((isset($frag['existsMatch']) && is_array($frag['existsMatch'])) ? $frag['existsMatch'] : [] as $t => $l) {
            $why = $remote((string) $t, '"match" target');
            if ($why !== null) return $refuse($why);
            if (!isset($types[$l])) return $refuse('"match" field "' . $l . '" is not a field in this project — check the spelling.');
            if (!in_array($types[$l], self::EXISTS_SCALAR_TYPES, true)) {
                return $refuse('"match" field "' . $l . '" is a ' . $types[$l] . ' field — the lookup needs one value per field.');
            }
            if ($l === $name) return $refuse('"match" uses this field itself for "' . $t . '" — it is already the value looked up.');
            $pairs[] = [(string) $t, $l];
        }
        foreach ($pairs as list($there, $here)) {
            if ($kindB($there) !== $kindA($here)) {
                return $refuse('"' . $there . '" of project ' . $b . ' holds ' . $kindB($there) . ' and "' . $here . '" holds '
                    . $kindA($here) . ' — values are compared as REDCap stores them, so both must be the same kind.');
            }
            $why = self::markMismatch($there, self::numberMarkOf(isset($ddB[$there]) ? $ddB[$there] : null),
                $here, self::numberMarkOf(isset($ddA[$here]) ? $ddA[$here] : null), ' of project ' . $b);
            if ($why !== null) return $refuse($why);
        }
        // The agreement lists fields, not events: whether a name is an event
        // there gets the same one answer as every other refusal, so a designer
        // here cannot use the setup error to list that project's events.
        if (isset($frag['existsEvent']) && $this->eventIdIn($b, $frag['existsEvent']) === null) {
            return $refuse(sprintf(self::CROSS_UNAVAILABLE, $b));
        }
        if (!empty($frag['existsSurveys']) && empty($c['surveys'])) {
            return $refuse('project ' . $b . ' does not answer survey respondents of this project — drop "surveys", or ask '
                . 'that project to allow survey answers for this project.');
        }
        if ($c['mode'] === 'answer') {
            $hit = $this->crossIdentifier($b, $frag);
            if ($hit !== null) {
                return $refuse('field "' . $hit . '" of project ' . $b . ' is an Identifier there. That project answers any '
                    . 'signed-in user of this project, so its identifying fields cannot be searched; it can answer only '
                    . 'users with rights there instead ("Who gets an answer").');
            }
        }
        // crossGate already required every "where" field to be on the
        // agreement's list of fields it may search.
        if (isset($frag['existsWhere'])) {
            $why = $this->existsWhereProblem($frag, $name, $ddB, $ddA, $b, ' of project ' . $b);
            if ($why !== null) return $refuse($why);
        }
        // crossGate already required every copied field to be on the agreement's
        // "return" list, which the "answer" mode never has.
        if (isset($frag['existsReturn'])) {
            $why = $this->existsReturnProblem($frag, $name, $ddB, $ddA, $b, $pid, ' of project ' . $b, $this->projectIdentifierFields($b));
            if ($why !== null) return $refuse($why);
        }
        return $frag;
    }

    /**
     * @UVCHOICES "field" hook: matrix rows render different markup than
     * standalone choice fields, so the client cannot hide their options
     * reliably. Refuse instead of half-working.
     */
    private function annotateChoicesField(array $frag, $name, array $meta, $pid)
    {
        $grid = isset($meta['matrix_group_name']) ? trim((string) $meta['matrix_group_name']) : '';
        if ($grid === '') return $frag;
        return ['error' => AnnotationRules::TAG_CHOICES . ' does not support matrix fields '
            . '(this field is in matrix "' . $grid . '") — move the field out of the matrix to filter its choices.',
            '_tag' => AnnotationRules::TAG_CHOICES];
    }

    /**
     * @UVCHOICES "dictionary" hook: codes must exist in the field's OWN choice
     * list, and the full code list travels on the rule (choicesAll) so the
     * client can compute a "show" whitelist's complement without enumerating
     * the DOM (checkbox inputs are only findable by exact name, code included).
     * choicesAll is part of groupMulti's canonical key, so two fields with
     * identical tags but different choice lists never share a rule.
     */
    private function annotateChoicesDictionary(array $frag, $name, $types, $choices, $pid = null)
    {
        $all = (is_array($choices) && isset($choices[$name]))
            ? array_map('strval', $choices[$name]) : [];
        if (!$all) {
            return ['error' => 'this field has no parseable choice list — '
                . AnnotationRules::TAG_CHOICES . ' has nothing to filter.',
                '_tag' => AnnotationRules::TAG_CHOICES];
        }
        $authored = isset($frag['choicesShow']) ? $frag['choicesShow']
            : (isset($frag['choicesHide']) ? $frag['choicesHide'] : []);
        $missing = array_values(array_diff($authored, $all));
        if ($missing) {
            return ['error' => 'choice code(s) '
                . implode(', ', array_map('json_encode', $missing))
                . ' do not exist on this field — its codes are: ' . implode(', ', $all) . '.',
                '_tag' => AnnotationRules::TAG_CHOICES];
        }
        $frag['choicesAll'] = $all;
        return $frag;
    }

    /**
     * @UVWINDOW "field" hook: the field must hold a date, which in REDCap is a
     * Text field with date, datetime or datetime-with-seconds validation. Its
     * type and display format travel on the rule: the browser reads the value
     * as typed, the server reads it as saved (always Y-M-D), and the message
     * shows the bounds the way the field shows dates. Both keys are part of
     * groupMulti's canonical key, so fields with different formats never share
     * a rule.
     */
    private function annotateWindowField(array $frag, $name, array $meta, $pid)
    {
        $validation = self::validationOf($meta);
        $tv = TemporalValue::fromValidation($validation);
        if ($tv === null) {
            return ['error' => AnnotationRules::TAG_WINDOW . ' needs a date field — give this Text field date, datetime '
                . 'or datetime-with-seconds validation' . ($validation !== '' ? ' (it has "' . $validation . '")' : '') . '.',
                '_tag' => AnnotationRules::TAG_WINDOW];
        }
        $unit = isset($frag['windowUnit']) ? $frag['windowUnit'] : null;
        if ($tv['type'] === 'date' && ($unit === 'minutes' || $unit === 'hours')) {
            return ['error' => '"unit" "' . $unit . '" needs a datetime field — this field holds dates without a time, '
                . 'so use days, weeks, months or years.', '_tag' => AnnotationRules::TAG_WINDOW];
        }
        // A "from" of today, now or a written date: of the field's kind, except
        // for a period, which reads only the anchor's day.
        $anchor = isset($frag['windowAnchor']) ? $frag['windowAnchor'] : null;
        $clockAnchor = $anchor === 'today' || $anchor === 'now';
        $hasPeriod = isset($frag['windowPeriod']);
        if ($anchor === 'now' && $tv['type'] === 'date') {
            return ['error' => '"from": "now" is a date and time — this field holds dates without a time, so count from "today".',
                '_tag' => AnnotationRules::TAG_WINDOW];
        }
        if ($anchor === 'today' && $tv['type'] !== 'date' && !$hasPeriod) {
            return ['error' => '"from": "today" is a date — this field holds dates with a time, so count from "now".',
                '_tag' => AnnotationRules::TAG_WINDOW];
        }
        if ($anchor !== null && !$clockAnchor && !$hasPeriod) {
            $at = TemporalLogic::anchorType($anchor);
            if ($at !== null && TemporalValue::family($at) !== TemporalValue::family($tv['type'])) {
                return ['error' => '"from" "' . $anchor . '" is ' . ($at === 'date' ? 'a date without a time' : 'a date with a time')
                    . ' and this field holds ' . ($tv['type'] === 'date' ? 'dates without a time' : 'dates with a time')
                    . ' — write the "from" date the way this field holds dates.', '_tag' => AnnotationRules::TAG_WINDOW];
            }
        }
        // A week starts on the rule's "weekStart", else the project's setting.
        if ($hasPeriod && $frag['windowPeriod'] === 'week' && !isset($frag['windowWeekStart'])) {
            $frag['windowWeekStart'] = $this->weekStart($pid);
        }
        // The parts judged against the project's clock (notFuture, notPast, a
        // "from" of today or now) need the field's value in the clock's zone. A
        // default filled in by @NOW-UTC / @TODAY-UTC (UTC) or @NOW-SERVER /
        // @TODAY-SERVER (the server's zone) is in another zone: where that zone
        // runs ahead, the value reads as in the future; behind, as in the past.
        $clockParts = !empty($frag['windowNotFuture']) || !empty($frag['windowNotPast']) || $clockAnchor;
        if ($clockParts && isset($meta['field_annotation']) && is_string($meta['field_annotation'])
                && preg_match('/@(NOW|TODAY)-(UTC|SERVER)(?![A-Za-z0-9_-])/', $meta['field_annotation'], $tm)) {
            $serverZone = new \DateTimeZone(date_default_timezone_get());
            $fillZone = $tm[2] === 'UTC' ? new \DateTimeZone('UTC') : $serverZone;
            $compZone = $this->clockZone($pid);
            if ($compZone === null) $compZone = $serverZone;
            $ahead = self::zoneAhead($fillZone, $compZone, time());
            $behind = self::zoneAhead($compZone, $fillZone, time());
            $filled = ' cannot be judged on a field filled by @' . $tm[1] . '-' . $tm[2] . ': that value is '
                . $fillZone->getName() . ' time, which ';
            $why = [];
            if (!empty($frag['windowNotFuture']) && $ahead) {
                $why[] = '"notFuture"' . $filled . 'runs ahead of the time this rule compares with (' . $compZone->getName()
                    . '), so it would read as in the future.';
            }
            if (!empty($frag['windowNotPast']) && $behind) {
                $why[] = '"notPast"' . $filled . 'runs behind the time this rule compares with (' . $compZone->getName()
                    . '), so it would read as in the past.';
            }
            if ($clockAnchor && ($ahead || $behind)) {
                $why[] = '"from": "' . $anchor . '"' . $filled . 'is not the time this rule compares with (' . $compZone->getName() . ').';
            }
            if ($why) {
                $text = implode(' ', $why) . ' Set the project setting Time zone for @UVWINDOW "notFuture" to '
                    . $fillZone->getName() . ', or fill the field with @' . $tm[1]
                    . ' if the computers entering data are set to ' . $compZone->getName() . ' time.';
                // A window or period counted from a field or a written date stays:
                // only the parts judged against the clock are dropped, with a note.
                if (!isset($frag['windowFrom']) && ($anchor === null || $clockAnchor)) {
                    return ['error' => $text, '_tag' => AnnotationRules::TAG_WINDOW];
                }
                unset($frag['windowNotFuture'], $frag['windowNotPast']);
                $frag['windowNotFutureOff'] = [$text];
            }
        }
        $frag['dateType'] = $tv['type'];
        $frag['dateFormat'] = $tv['format'];
        return $frag;
    }

    /**
     * Whether $zone's clock is ahead of $than's at $from, or after any change
     * of either zone within the next year.
     */
    private static function zoneAhead(\DateTimeZone $zone, \DateTimeZone $than, $from)
    {
        $points = [(int) $from];
        foreach ([$zone, $than] as $z) {
            $changes = $z->getTransitions((int) $from, (int) $from + 366 * 86400);
            if (is_array($changes)) {
                foreach ($changes as $c) if ($c['ts'] > $from) $points[] = (int) $c['ts'];
            }
        }
        foreach ($points as $ts) {
            $at = new \DateTimeImmutable('@' . $ts);
            if ($zone->getOffset($at) > $than->getOffset($at)) return true;
        }
        return false;
    }

    /**
     * @UVRANGE "field" hook: a Text field must take numbers (validation none,
     * integer or number in any of its forms — modes.json
     * eligibility.textValidations); a calc and a slider always do. A
     * *_comma_decimal field is marked so both runtimes read 17,5 as 17.5, and
     * a calc is marked so the browser never blocks a value nobody typed. The
     * two ranges travel as display texts too ("12 to 17.5 g/dL"), written with
     * the field's own decimal mark, for the message and the scan report.
     */
    private function annotateRangeField(array $frag, $name, array $meta, $pid)
    {
        $ftype = isset($meta['field_type']) ? $meta['field_type'] : '';
        if ($ftype === 'text') {
            $validation = self::validationOf($meta);
            $re = ModeRegistry::eligibility('range')['textValidations'] ?? null;
            if (!is_string($re) || !preg_match('~' . $re . '~', $validation)) {
                return ['error' => AnnotationRules::TAG_RANGE . ' needs a number field — give this Text field integer or '
                    . 'number validation, or none (it has "' . $validation . '").', '_tag' => AnnotationRules::TAG_RANGE];
            }
            if (substr($validation, -strlen('_comma_decimal')) === '_comma_decimal') $frag['decimalComma'] = true;
        } elseif ($ftype === 'calc') {
            $frag['rangeComputed'] = true;
        }
        // Bounds a rule returns, or its page table shows, are written with the
        // decimals the measured field takes: none for an integer, N for
        // number_Ndp, one otherwise ("decimals" says otherwise). Resolved
        // here so both runtimes get a number, never a default of their own:
        // for every rule that returns values, and for a page table with a
        // column written with "decimals" (the catalog's "places").
        $columns = isset($frag['rangeShow']['table']['columns']) && is_array($frag['rangeShow']['table']['columns'])
            ? $frag['rangeShow']['table']['columns'] : [];
        $dec = function ($e) { return isset($e['places']) && $e['places'] === 'decimals'; };
        if ((isset($frag['rangeReturn']) || ($columns && GrowthOutputs::problem() === null && GrowthOutputs::anyWhere($columns, $dec, 'column')))
                && !array_key_exists('rangeDecimals', $frag)) {
            $v = $ftype === 'text' ? strtolower(self::validationOf($meta)) : '';
            if (preg_match('/^number_([1-9])dp/', $v, $dp)) $frag['rangeDecimals'] = min((int) $dp[1], GrowthReference::MAX_DECIMALS);
            elseif ($v === 'int' || $v === 'integer') $frag['rangeDecimals'] = 0;
            else $frag['rangeDecimals'] = 1;
        }
        // A percentile is not in the field's unit: one decimal unless
        // "percentileDecimals" says otherwise, set only when a value written
        // with it is returned or shown (the catalog's "places").
        $pct = function ($e) { return isset($e['places']) && $e['places'] === 'percentileDecimals'; };
        if (!array_key_exists('rangePercentileDecimals', $frag) && GrowthOutputs::problem() === null
                && ((isset($frag['rangeReturn']) && is_array($frag['rangeReturn']) && GrowthOutputs::anyWhere(array_keys($frag['rangeReturn']), $pct))
                    || GrowthOutputs::anyWhere($columns, $pct, 'column'))) {
            $frag['rangePercentileDecimals'] = GrowthReference::DEFAULT_PERCENTILE_DECIMALS;
        }
        $unit = isset($frag['rangeUnit']) ? $frag['rangeUnit'] : '';
        $comma = !empty($frag['decimalComma']);
        // With a growth reference the limits are z-scores: plain numbers,
        // whatever the field's decimal mark.
        $growth = isset($frag['rangeReference']);
        foreach (['Soft', 'Hard'] as $t) {
            $text = self::rangeText($frag['range' . $t . 'Lo'] ?? null, $frag['range' . $t . 'Hi'] ?? null,
                $growth ? '' : $unit, $growth ? false : $comma);
            if ($text !== null) $frag['range' . $t . 'Text'] = ($growth ? 'z-score ' : '') . $text;
        }
        return $frag;
    }

    /**
     * @UVRANGE "dictionary" hook, for a rule with a growth "reference": the
     * reference must be one this server has, with a table that reads; its
     * axis decides between "age" and "by"; and every input must be a field of
     * the right kind. "sex" is a field holding one code (and when it lists
     * its choices, "male" and "female" must be among them); "dob" and "at"
     * are date fields; "days", "months" and "by" are number fields. No input
     * may be the measured field itself. The date types and formats, and
     * whether the number inputs use a decimal comma, travel on the rule.
     */
    private function annotateRangeDictionary(array $frag, $name, $types, $choices, $pid = null)
    {
        if (!isset($frag['rangeReference'])) return $frag;
        $refuse = function ($why) { return ['error' => $why, '_tag' => AnnotationRules::TAG_RANGE]; };
        $id = $frag['rangeReference'];
        $dir = $this->referenceDir();
        $catalog = GrowthReference::catalog($dir);
        $entry = GrowthReference::entry($id, $dir);
        $gone = GrowthReference::unavailableWhy($id, $dir);
        if ($entry === null && $gone !== null) {
            // meant to be there: the extra folder cannot be read, or its entry for this id is broken
            return $refuse('"reference" "' . $id . '" cannot be used: ' . $gone);
        }
        if ($entry === null) {
            $known = array_keys($catalog['references']);
            sort($known);
            return $refuse('"reference" "' . $id . '" is not a growth reference this server has — '
                . ($known ? 'it has ' . implode(', ', $known) . '.' : 'it has none.')
                . ($catalog['problems'] ? ' Problems reading the references: ' . implode(' ', $catalog['problems']) : ''));
        }
        $lim = function ($k) use ($frag) { return isset($frag[$k]) && is_string($frag[$k]) ? $frag[$k] : null; };
        try {
            $table = GrowthReference::table($entry);
            $out = GrowthReference::limitProblem($entry, $table, [
                'soft' => [$lim('rangeSoftLo'), $lim('rangeSoftHi')],
                'hard' => [$lim('rangeHardLo'), $lim('rangeHardHi')],
            ]);
        } catch (\Throwable $e) {
            return $refuse('"reference" "' . $id . '" cannot be used: ' . $e->getMessage());
        }
        if ($out !== null) return $refuse('"reference" "' . $id . '": ' . $out);
        // A chart's or a table's "span" outside the reference, or a table of
        // reference values past its row cap over the span, leaves that part
        // off the page with the reason for staff (attachGrowthCurves keeps
        // it). The reference can change under the rule, and what the page
        // shows never decides what is checked, so the rule stands.
        if (isset($frag['rangeShow']) && is_array($frag['rangeShow'])) {
            $whys = GrowthCurve::showProblems($frag['rangeShow'], $entry);
            if ($whys) $frag['growthChartWhy'] = $whys;
        }
        // "plausible" is in the reference's unit, after "measureScale"
        $pt = self::rangeText($lim('rangePlausibleLo'), $lim('rangePlausibleHi'), (string) $entry['unit'], false);
        if ($pt !== null) $frag['rangePlausibleText'] = $pt;
        $byAge = $entry['axis'] === 'age';
        if ($byAge && isset($frag['rangeBy'])) {
            return $refuse('"' . $id . '" is read by age — give "age" instead of "by".');
        }
        if (!$byAge && !isset($frag['rangeBy'])) {
            return $refuse('"' . $id . '" is read by ' . $entry['axis'] . ' — give "by", the field holding the '
                . $entry['axis'] . ' in cm, instead of "age".');
        }
        // The index says which references WHO corrects for how the child was
        // measured ("position", GrowthReference::positionProblem).
        if (isset($frag['rangeMeasured']) && !isset($entry['position'])) {
            return $refuse('"' . $id . '" has no correction for how the child was measured, so "measured" does not apply. '
                . 'Where a value needs one, such as a BMI, correct the length or height in the calc that works the value out.');
        }
        $dd = $this->dataDictionary($pid);
        $opts = $this->temporalOptions($pid);
        $comma = false;
        $inputFields = [];
        // an input that holds one code of a few, and the rule's name for each
        $coded = ['rangeSex' => ['what' => '"sex"', 'codes' => ['rangeMale' => '"male"', 'rangeFemale' => '"female"']],
                  'rangeMeasured' => ['what' => '"measured"', 'codes' => ['rangeLying' => '"measured" "lying"', 'rangeStanding' => '"measured" "standing"']]];
        foreach (['rangeSex' => '"sex"', 'rangeAgeDob' => '"age" "dob"', 'rangeAgeAt' => '"age" "at"',
                  'rangeAgeDays' => '"age" "days"', 'rangeAgeMonths' => '"age" "months"', 'rangeBy' => '"by"',
                  'rangeMeasured' => '"measured" "field"'] as $k => $label) {
            if (!isset($frag[$k])) continue;
            $op = ModeRegistry::operandRef($frag[$k], $opts);
            if ($op === null) return $frag;   // refused already by AnnotationRules::checkRange
            $f = (string) $op[1];
            if (!$dd || !isset($dd[$f])) return $refuse($label . ' names "[' . $f . ']", which is not a field in this project.');
            if ($f === $name) return $refuse($label . ' names the measured field itself.');
            $meta = $dd[$f];
            $ftype = isset($meta['field_type']) ? $meta['field_type'] : '';
            if (isset($coded[$k])) {
                $what = $coded[$k]['what'];
                if (!in_array($ftype, ['radio', 'dropdown', 'yesno', 'truefalse', 'text', 'calc', 'sql'], true)) {
                    return $refuse($what . ' field "' . $f . '" is a ' . $ftype . ' field — it must hold one code (a radio, '
                        . 'a dropdown or a Text field).');
                }
                $codes = in_array($ftype, ['yesno', 'truefalse'], true) ? ['1', '0']
                    : (isset($choices[$f]) && in_array($ftype, ['radio', 'dropdown'], true) ? $choices[$f] : null);
                if ($codes !== null) {
                    // matched as the rule matches a value: letter case ignored
                    // unless caseSensitive (GrowthReference::sexOf, positionOf)
                    $fold = empty($frag['caseSensitive']);
                    $key = function ($v) use ($fold) { $v = (string) $v; return $fold ? Logic::foldAscii($v) : $v; };
                    foreach ($coded[$k]['codes'] as $ck => $word) {
                        $want = $key($frag[$ck]);
                        $hits = array_values(array_filter(array_map('strval', $codes), function ($c) use ($key, $want) { return $key($c) === $want; }));
                        if ($hits === []) {
                            return $refuse($word . ' is "' . $frag[$ck] . '", which is not a choice of ' . $what . ' field "'
                                . $f . '" (its codes are ' . implode(', ', $codes) . ').');
                        }
                        if (count($hits) > 1) {
                            return $refuse($word . ' is "' . $frag[$ck] . '", which matches the codes ' . implode(' and ', $hits)
                                . ' of ' . $what . ' field "' . $f . '" when letter case is ignored. Add "caseSensitive": true to tell them apart.');
                        }
                    }
                }
            } elseif ($k === 'rangeAgeDob' || $k === 'rangeAgeAt') {
                $tv = $ftype === 'text' ? TemporalValue::fromValidation(self::validationOf($meta)) : null;
                if ($tv === null) {
                    return $refuse($label . ' field "' . $f . '" is not a date field — it needs date, datetime or '
                        . 'datetime-with-seconds validation.');
                }
                $w = $k === 'rangeAgeDob' ? 'Dob' : 'At';
                $frag['range' . $w . 'Type'] = $tv['type'];
                $frag['range' . $w . 'Format'] = $tv['format'];
            } else {
                $validation = $ftype === 'text' ? self::validationOf($meta) : '';
                $re = ModeRegistry::eligibility('range')['textValidations'] ?? null;
                // The measured field may be a Text field without validation (its
                // own value is judged, "not a number" included); an input may not:
                // a typo there would only switch the check off, with no finding.
                if (!($ftype === 'calc' || ($ftype === 'text' && $validation !== '' && is_string($re) && preg_match('~' . $re . '~', $validation)))) {
                    return $refuse($label . ' field "' . $f . '" is not a number field — it needs integer or number '
                        . 'validation, or to be a calc.');
                }
                if (substr($validation, -strlen('_comma_decimal')) === '_comma_decimal') $comma = true;
                // Whole months on a table read by day: an infant of 1 month and
                // 29 days is judged as 1 month old, and an underweight one
                // passes (a z-score off by up to 2 in the first months).
                if ($k === 'rangeAgeMonths' && $entry['axisUnit'] === 'days' && in_array($validation, ['int', 'integer'], true)) {
                    return $refuse('"age" "months" field "' . $f . '" holds whole months, but "' . $id . '" is read by day, '
                        . 'so an infant of 1 month and 29 days would be judged as 1 month old. Give "dob" and "at", an age '
                        . 'in days, or a number field or calc that keeps the part month (datediff with "M", not rounded).');
                }
            }
            $inputFields[$f] = true;
        }
        if ($comma) $frag['rangeAxisComma'] = true;
        if (isset($frag['rangeReturn']) && is_array($frag['rangeReturn'])) {
            $marks = [];
            $why = $this->returnTargetsProblem($frag, $name, is_array($dd) ? $dd : [], $inputFields, $marks);
            if ($why !== null) return $refuse($why);
            $frag['rangeReturnComma'] = $marks;
        }
        return $frag;
    }

    /**
     * Why the fields a growth rule returns values to cannot take them, or null;
     * $marks gets value name => whether that field writes a decimal comma.
     *
     * A value is written into the page being edited, so each field must be a
     * Text field of the measured field's own form (and so of its event and
     * instance), and not the measured field, an input of the rule or the record
     * ID. What each value is comes from php/growth_outputs.json
     * (GrowthOutputs): a text ("range", "7.7 to 12.0 kg") needs a field without
     * validation; a number needs none or number validation that takes the
     * decimals it is written with (number_Ndp: exactly N): two for z,
     * "decimals" for a bound or a value at a z-score or percentile,
     * "percentileDecimals" for the percentile. A number written with 0
     * decimals may also go into an integer field, when the catalog allows it
     * (not z). A number goes in the field's own decimal mark; a field without
     * validation takes the measured field's.
     */
    private function returnTargetsProblem(array $frag, $name, array $dd, array $inputs, array &$marks)
    {
        $catalog = GrowthOutputs::problem();
        if ($catalog !== null) return '"return" cannot be checked: ' . $catalog . '.';
        $own = !empty($frag['decimalComma']);
        $decimals = isset($frag['rangeDecimals']) ? (int) $frag['rangeDecimals'] : 1;
        $pctDecimals = isset($frag['rangePercentileDecimals']) ? (int) $frag['rangePercentileDecimals']
            : GrowthReference::DEFAULT_PERCENTILE_DECIMALS;
        foreach ($frag['rangeReturn'] as $k => $t) {
            $k = (string) $k;
            $known = GrowthOutputs::parse($k);
            if (isset($known['error'])) return $known['error'];
            $spec = GrowthOutputs::spec($known['of']);
            $label = '"return" "' . $k . '" field "' . $t . '"';
            $why = self::returnTargetBasics($t, '"return" "' . $k . '"', $label, $name, 'the measured field', $dd, $inputs,
                function (array $m) use ($label) {
                    $ftype = isset($m['field_type']) ? $m['field_type'] : '';
                    return $ftype !== 'text' ? $label . ' is a ' . $ftype . ' field — a returned value goes into a Text field.' : null;
                });
            if ($why !== null) return $why;
            $m = $dd[$t];
            $v = strtolower(self::validationOf($m));
            if ($spec['written'] === 'text') {
                if ($v !== '') {
                    return $label . ' has "' . $v . '" validation, which would refuse a text such as "7.7 to 12.0 kg" — remove the validation.';
                }
                $marks[$k] = $own;
                continue;
            }
            if ($v === '') {
                $marks[$k] = $own;
                continue;
            }
            // The decimals the value is written with, and the key that sets them.
            $need = $spec['places'] === 'z' ? 2 : ($spec['places'] === 'percentileDecimals' ? $pctDecimals : $decimals);
            $placesKey = $spec['places'] === 'percentileDecimals' ? 'percentileDecimals' : 'decimals';
            $placesMax = $spec['places'] === 'percentileDecimals' ? GrowthReference::MAX_PERCENTILE_DECIMALS : GrowthReference::MAX_DECIMALS;
            // A value written without decimals ("decimals":0) is a whole number.
            if (($v === 'int' || $v === 'integer') && $spec['integer']) {
                if ($need > 0) {
                    return $label . ' has "' . $v . '" validation and this value is written with ' . $need . ' decimal place'
                        . ($need === 1 ? '' : 's') . ' — set "' . $placesKey . '" to 0, or give it number validation.';
                }
                $marks[$k] = false;
                continue;
            }
            if (!preg_match('/^(?:float|number)(?:_([1-9])dp)?(_comma_decimal)?$/', $v, $mm)) {
                return $label . ' has "' . $v . '" validation, which cannot hold a number written with decimals — give it number '
                    . 'validation, or none.';
            }
            // REDCap's number_Ndp takes exactly N decimal places, and a value
            // is written with exactly $need.
            $room = (isset($mm[1]) && $mm[1] !== '') ? (int) $mm[1] : null;
            if ($room !== null && $need !== $room) {
                return $label . ' takes exactly ' . $room . ' decimal place' . ($room === 1 ? '' : 's') . ' and this value is written with ' . $need
                    . ' — give it number validation without a place count'
                    . ($spec['places'] === 'z' ? ', or number_2dp'
                        : ($room <= $placesMax ? ', or set "' . $placesKey . '" to ' . $room : '')) . '.';
            }
            $marks[$k] = !empty($mm[2]);
        }
        return null;
    }

    /**
     * Why field $t cannot take a value a rule on $name returns, by the checks
     * every mode shares, or null. A value is written into the page being
     * edited, so $t must be a field of this project on $name's form (and so in
     * its event and instance), and not $name itself ($what names it in the
     * message), a field the rule reads ($inputs), or the record ID. $type, when
     * given, says why the field's type cannot hold the value (null when it
     * can); it runs before the form is compared. Last, REDCap must not fill $t
     * itself: @CALCTEXT, @CALCDATE and @SETVALUE write their own value, and
     * the two writers would overwrite each other; nor show a widget that keeps
     * its own text (@RICHTEXT, a BioPortal ontology search). $short names the
     * "return" entry and $label the entry with its field.
     */
    private static function returnTargetBasics($t, $short, $label, $name, $what, array $dd, array $inputs, ?callable $type = null)
    {
        if (!isset($dd[$t])) return $label . ' is not a field in this project.';
        if ($t === $name) return $short . ' is ' . $what . ' itself — write the value to a field of its own.';
        if (isset($inputs[$t])) return $label . ' is read by this rule — write the value to a field of its own.';
        $pk = $dd ? array_keys($dd)[0] : null;
        if ($t === $pk) return $label . ' is the record ID.';
        $m = $dd[$t];
        if ($type !== null) {
            $why = $type($m);
            if ($why !== null) return $why;
        }
        $home = isset($dd[$name]['form_name']) ? (string) $dd[$name]['form_name'] : null;
        $form = isset($m['form_name']) ? (string) $m['form_name'] : null;
        if ($home === null || $form !== $home) {
            return $label . ' is on the form "' . ($form === null ? '?' : $form) . '", not on "' . ($home === null ? '?' : $home)
                . '" with ' . $what . ' — a value is written into the page being edited, so the field must be on that form.';
        }
        $note = isset($m['field_annotation']) ? (string) $m['field_annotation'] : '';
        if (preg_match('/@(CALCTEXT|CALCDATE|SETVALUE|PREFILL)\b/i', $note, $tag)) {
            return $label . ' is filled by REDCap\'s @' . strtoupper($tag[1]) . ', which would overwrite the returned value '
                . '(and the other way round) — remove @' . strtoupper($tag[1]) . ', or write to another field.';
        }
        // Widgets that keep their own copy of the text: the page writes the
        // field under them, the widget goes on showing (and, for the rich
        // text editor, saving) its own.
        if (preg_match('/@RICHTEXT\b/i', $note)) {
            return $label . ' shows a rich text editor (@RICHTEXT), which keeps its own copy of the text and saves it over the '
                . 'returned value — remove @RICHTEXT, or write to another field.';
        }
        $enum = isset($m['select_choices_or_calculations']) ? ltrim((string) $m['select_choices_or_calculations']) : '';
        if (isset($m['field_type']) && $m['field_type'] === 'text' && stripos($enum, 'BIOPORTAL:') === 0) {
            return $label . ' searches an ontology (BioPortal), whose search box keeps its own text — write to a field without one.';
        }
        return null;
    }

    /**
     * @UVDELTA "field" hook. The field must hold numbers, as @UVRANGE's must
     * (modes.json eligibility.textValidations); a *_comma_decimal field is
     * marked so both runtimes read 17,5 as 17.5, and a calc so the browser
     * never blocks a value nobody typed. A "vs" shorthand is written out as
     * the reference it stands for (AnnotationRules::DELTA_SHORTHANDS), and the
     * rule's branch group follows it. A "vs" that names the field itself on
     * this entry is refused: it would compare the value with itself. The
     * limits travel as words (deltaText) and "vs" as a name (deltaVsLabel),
     * for the message and the scan report. $at is the tag's place in the
     * field's parseAllTags list, so the advice can tell the tags before it
     * apart (deltaGroupsTaken), and the answer is worked out once for the
     * tag (deltaRemembered).
     */
    private function annotateDeltaField(array $frag, $name, array $meta, $pid, $at = null)
    {
        return $this->deltaRemembered('field', $name, $at, function () use ($frag, $name, $meta, $pid, $at) {
            return $this->deltaFieldHook($frag, $name, $meta, $pid, $at);
        });
    }

    /** annotateDeltaField's answer, worked out. */
    private function deltaFieldHook(array $frag, $name, array $meta, $pid, $at)
    {
        $tag = AnnotationRules::TAG_DELTA;
        $ftype = isset($meta['field_type']) ? $meta['field_type'] : '';
        if ($ftype === 'text') {
            $validation = self::validationOf($meta);
            $re = ModeRegistry::eligibility('delta')['textValidations'] ?? null;
            if (!is_string($re) || !preg_match('~' . $re . '~', $validation)) {
                return ['error' => $tag . ' needs a number field — give this Text field integer or number validation, '
                    . 'or none (it has "' . $validation . '").', '_tag' => $tag];
            }
            if (substr($validation, -strlen('_comma_decimal')) === '_comma_decimal') $frag['decimalComma'] = true;
        } elseif ($ftype === 'calc') {
            $frag['deltaComputed'] = true;
        }
        $short = isset($frag['deltaVsName']) && isset(AnnotationRules::DELTA_SHORTHANDS[$frag['deltaVsName']])
            ? $frag['deltaVsName'] : null;
        if ($short !== null) {
            $frag['deltaVs'] = sprintf(AnnotationRules::DELTA_SHORTHANDS[$short], $name);
        } else {
            $op = ModeRegistry::operandRef($frag['deltaVs'], $this->temporalOptions($pid));
            if ($op !== null && $op[0] === 'ref' && $op[1] === $name) {
                $advice = $this->deltaSelfAdvice($op, $name, $meta, $pid, $frag, $at);
                return ['error' => '"vs" names this field itself — compare it with another entry'
                    . ($advice !== null ? $advice['text'] : '') . '.', '_tag' => $tag]
                    + self::deltaAdvisedGroup($advice !== null ? $advice['vs'] : null, $name, $frag);
            }
        }
        $frag['deltaGroup'] = AnnotationRules::deltaGroup($frag['deltaVs'], isset($frag['deltaDirection']) ? $frag['deltaDirection'] : 'both');
        $frag['deltaText'] = self::deltaText($frag, !empty($frag['decimalComma']));
        $labels = ['previous-event' => 'the previous event', 'previous-instance' => 'the previous instance',
                   'first-event' => 'the first event', 'first-instance' => 'the first instance'];
        $frag['deltaVsLabel'] = $short !== null ? $labels[$short] : (string) $frag['deltaVs'];
        return $frag;
    }

    /**
     * What to write instead of a plain "vs" that names the field itself, as
     * ['vs' => the shorthand or reference, 'text' => ", as ..." to append],
     * or null. A shorthand comes first, the first that the field's events
     * accept (deltaShorthandProblem, on a layout it could read), then a
     * written reference (deltaSuggestion), each checked as the dictionary
     * hook will check it, and none that repeats the branch group of another
     * @UVDELTA tag on the field (deltaGroupsTaken, with the tag's place $at);
     * either needs event and instance references on. When there is none
     * because of those groups, 'vs' is null and 'text' says why
     * (deltaHeldNote).
     */
    private function deltaSelfAdvice(array $op, $name, array $meta, $pid, array $frag, $at = null)
    {
        $form = isset($meta['form_name']) ? (string) $meta['form_name'] : '';
        if ($form === '' || $pid === null) return null;
        $shape = $this->temporalShape($pid);
        $needs = empty($this->temporalOptions($pid)['qualified']) ? self::DELTA_NEEDS_REFS : '';
        $direction = isset($frag['deltaDirection']) ? $frag['deltaDirection'] : 'both';
        $taken = $this->deltaGroupsTaken($frag, $name, isset($meta['field_annotation']) ? $meta['field_annotation'] : '', $pid, $at);
        foreach (array_keys(AnnotationRules::DELTA_SHORTHANDS) as $short) {
            $readable = true;
            if (self::deltaShorthandProblem($shape, $short, $form, $name, [], $direction, false, $readable) !== null || !$readable) continue;
            if (self::deltaHeld($taken, AnnotationRules::deltaGroup(sprintf(AnnotationRules::DELTA_SHORTHANDS[$short], $name), $direction))) continue;
            return ['vs' => $short, 'text' => ', as "vs":"' . $short . '"' . $needs];
        }
        $c = self::deltaSuggestion($shape, $op, $name, $form, $form, $name, false, $taken, $direction);
        if ($c !== null) return ['vs' => $c['ref'], 'text' => ', as "vs":"' . $c['ref'] . '"' . ($c['qualified'] ? $needs : '')];
        $note = self::deltaHeldNote($taken);
        return $note !== '' ? ['vs' => null, 'text' => $note] : null;
    }

    /**
     * The key a refused tag ($frag) of field $name carries for
     * deltaHeldGroup: the branch group of $vs, the shorthand or reference
     * its refusal suggests, with the tag's direction; nothing when it
     * suggests none. Error fragments keep only "error" and "_tag" in the
     * rules (AnnotationRules::groupMulti), so the key goes no further.
     */
    private static function deltaAdvisedGroup($vs, $name, array $frag)
    {
        if ($vs === null) return [];
        $ref = isset(AnnotationRules::DELTA_SHORTHANDS[$vs]) ? sprintf(AnnotationRules::DELTA_SHORTHANDS[$vs], $name) : $vs;
        return ['_deltaAdvised' => AnnotationRules::deltaGroup($ref, isset($frag['deltaDirection']) ? $frag['deltaDirection'] : 'both')];
    }

    /**
     * The branch groups (AnnotationRules::deltaGroup) that the field's other
     * @UVDELTA tags will hold where $frag's would meet them. A suggestion in
     * one of them would be refused once written back, or would validate
     * nothing at times: Branching allows one rule without a "when" per
     * group, refuses two whose conditions are the same (Branching::whenKeys:
     * keyword case, spacing, quote style, parentheses, the order of and/or
     * members and of a comparison's sides aside, and letter case unless
     * caseSensitive; reviews R8-4, R9-1), and at run time validates nothing
     * while two conditions of one group are true. So an unconditional
     * $frag, which becomes the else branch, meets only tags without a
     * "when"; a conditional one meets every tag with a "when", unless the
     * two conditions can never be true at once (Branching::whenExclusive,
     * review R10-1).
     *
     * A tag before $frag's own ($at, its place in the field's parseAllTags
     * list) that is refused with a suggestion holds the group of that
     * suggestion, which is what will be written in its place
     * (deltaHeldGroup); every other tag holds its own. A tag that failed to
     * parse holds the group its JSON names, read leniently
     * (deltaTagsAsWritten), since fixing the key it failed on brings it back
     * (review R9-7); one that cannot be read even so (an unknown key, or a
     * "vs", "direction" or "when" the parser would refuse) could hold any,
     * so the answer is every group (DELTA_ANY_GROUP, reviews R10-2, R11-1,
     * R12-2).
     * $frag's own tag is among them; its "vs" is never suggested back.
     *
     * Each hook asks this only once a suggestion is searched for, so an
     * accepted tag never pays for it (review R9-8).
     */
    private function deltaGroupsTaken(array $frag, $name, $annotation, $pid, $at = null)
    {
        $own = Branching::whenKeys($frag);
        $taken = [];
        foreach ($this->deltaTagsAsWritten((string) $annotation, $pid) as $i => $t) {
            if ($t === null) return [self::DELTA_ANY_GROUP => true];
            $keys = $t['keys'];
            if ($own === [] ? $keys !== [] : ($keys === [] || (!array_intersect($own, $keys) && Branching::whenExclusive($frag, $t['frag'])))) continue;
            $f = $t['frag'];
            $group = !$t['failed'] && $at !== null && $i < $at ? $this->deltaHeldGroup($f, $i, $name, $pid) : null;
            if ($group === null) {
                $vs = isset($f['deltaVsName'], AnnotationRules::DELTA_SHORTHANDS[$f['deltaVsName']])
                    ? sprintf(AnnotationRules::DELTA_SHORTHANDS[$f['deltaVsName']], $name) : $f['deltaVs'];
                $group = AnnotationRules::deltaGroup($vs, isset($f['deltaDirection']) ? $f['deltaDirection'] : 'both');
            }
            $taken[$group] = true;
        }
        return $taken;
    }

    /** A key of deltaGroupsTaken's answer: every group is held (review R10-2). */
    const DELTA_ANY_GROUP = '*';
    /** The key deltaHeld adds to $taken once a candidate is passed over for a held group. */
    const DELTA_PASSED = '#';
    /** Said where nothing is suggested because another tag cannot be read (review R10-2). */
    const DELTA_UNREAD = ' (nothing is suggested while another @UVDELTA tag on this field cannot be read; fix that tag first)';
    /**
     * Said where nothing is suggested and an entry was passed over for
     * another tag's group (review R10-1), with the rule deltaGroupsTaken
     * applies in the docs' words (Branching::whenExclusive, reviews R11-2,
     * R12-1): Logic::requiredValues counts only a field written without an
     * event or instance, and keys a checkbox by its box.
     */
    const DELTA_HELD = ' (an entry another @UVDELTA tag on this field compares with in the same direction is not suggested, '
        . 'unless just one of the two tags has a "when", or each "when" tests one field, written without an event or instance '
        . '(each checkbox box counts as its own field), for equality with a value, alone or as part of an "and", '
        . 'and no value equals both as conditions compare values)';

    /** deltaTagsAsWritten's answers, by annotation, for one build of the rules. */
    private $deltaTagsMemo = [];

    /**
     * The @UVDELTA tags of an annotation as written, by their place in its
     * parseAllTags list, read once for each build of the rules (review
     * R10-3): each as ['frag' => its fragment, 'failed' => whether it failed
     * to parse, 'keys' => Branching::whenKeys], where a tag that failed to
     * parse gives the keys its JSON names (AnnotationRules::deltaBranchAsWritten);
     * null for one that cannot be read even so. parseAllTags reads
     * each tag's values in order, so its n-th @UVDELTA entry is the n-th
     * value extractTagsDetailed reads.
     */
    private function deltaTagsAsWritten($annotation, $pid)
    {
        if (isset($this->deltaTagsMemo[$annotation])) return $this->deltaTagsMemo[$annotation];
        $frags = AnnotationRules::parseAllTags($annotation, $this->temporalOptions($pid));
        $reads = AnnotationRules::extractTagsDetailed($annotation, AnnotationRules::TAG_DELTA, ModeRegistry::bareValue('delta'));
        $out = [];
        $n = 0;
        foreach (is_array($frags) ? $frags : [] as $i => $f) {
            if (!is_array($f)) continue;
            $failed = isset($f['error']);
            if ($failed ? (!isset($f['_tag']) || $f['_tag'] !== AnnotationRules::TAG_DELTA) : (!isset($f['type']) || $f['type'] !== 'delta')) continue;
            $read = isset($reads[$n]) ? $reads[$n] : null;
            $n++;
            if ($failed) $f = $read !== null && $read['error'] === null ? AnnotationRules::deltaBranchAsWritten($read['value']) : null;
            if ($f === null) { $out[$i] = null; continue; }
            if (!isset($f['deltaVs']) || !is_string($f['deltaVs'])) continue;
            $out[$i] = ['frag' => $f, 'failed' => $failed, 'keys' => Branching::whenKeys($f)];
        }
        return $this->deltaTagsMemo[$annotation] = $out;
    }

    /**
     * The branch group tag $f (at place $i of field $name's parseAllTags
     * list) will hold: that of the reference its refusal suggests when the
     * field or the dictionary hook refuses it with one (the "_deltaAdvised"
     * key, deltaAdvisedGroup), else its own; null for a tag refused with no
     * suggestion, for which deltaGroupsTaken counts the group as written.
     * The hooks run as getAnnotationRules runs them, without the check of
     * the fields a "when" names (Logic::checkRefs): the "vs" holds its group
     * once that is fixed. Each refused tag asks this of every tag before it;
     * the hooks remember their answers (deltaRemembered), so the hook pass
     * and these questions search once for each tag.
     */
    private function deltaHeldGroup(array $f, $i, $name, $pid)
    {
        $dd = $this->dataDictionary($pid);
        $meta = is_array($dd) && isset($dd[$name]) && is_array($dd[$name]) ? $dd[$name] : [];
        $r = $this->annotateDeltaField($f, $name, $meta, $pid, $i);
        if (!isset($r['error'])) $r = $this->annotateDeltaDictionary($r, $name, null, null, $pid, $i);
        return isset($r['error']) ? (isset($r['_deltaAdvised']) ? $r['_deltaAdvised'] : null)
            : (isset($r['deltaGroup']) ? $r['deltaGroup'] : null);
    }

    /** The @UVDELTA hooks' answers, by hook, field and place, for one build of the rules. */
    private $deltaHeldMemo = [];

    /**
     * Hook $hook's answer for the tag at place $at of field $name, worked
     * out by $work the first time it is asked in a build of the rules
     * (getAnnotationRules starts each with none). The hook pass and
     * deltaHeldGroup ask the same hook of the same tag, given the same
     * fragment, so each refused tag's suggestion is searched once (review
     * R10-3). A call without a place is not remembered.
     */
    private function deltaRemembered($hook, $name, $at, \Closure $work)
    {
        if ($at === null) return $work();
        $key = $hook . '|' . $name . '|' . $at;
        if (!array_key_exists($key, $this->deltaHeldMemo)) $this->deltaHeldMemo[$key] = $work();
        return $this->deltaHeldMemo[$key];
    }

    /**
     * Whether branch group $group is held: in $taken (deltaGroupsTaken), or
     * $taken holds every group. $taken may be a function that returns the
     * groups: it is called the first time, and its answer replaces it
     * (review R9-8). A held group is noted in $taken (DELTA_PASSED), so a
     * refusal that suggests nothing can say why (deltaHeldNote).
     */
    private static function deltaHeld(&$taken, $group)
    {
        if ($taken instanceof \Closure) $taken = $taken();
        if (!isset($taken[self::DELTA_ANY_GROUP]) && !isset($taken[$group])) return false;
        $taken[self::DELTA_PASSED] = true;
        return true;
    }

    /**
     * What a refusal that suggests nothing adds when deltaHeld passed an
     * entry over: that another tag on the field cannot be read
     * (DELTA_UNREAD), or that another tag holds the group (DELTA_HELD). ''
     * when no entry got that far.
     */
    private static function deltaHeldNote($taken)
    {
        if (!is_array($taken) || !isset($taken[self::DELTA_PASSED])) return '';
        return isset($taken[self::DELTA_ANY_GROUP]) ? self::DELTA_UNREAD : self::DELTA_HELD;
    }

    /**
     * @UVDELTA "dictionary" hook. The "vs" field must exist and hold numbers
     * (a Text field with number validation or none, a calc or a slider); its
     * decimal comma travels as deltaVsComma. A reference that could not be
     * read on any save of some entry, or never finds an entry at all, is
     * refused (deltaDeadReference). So is one that always names
     * this same entry, since the change would always be 0: [event-name][weight],
     * [weight][current-instance], and the one event the form is on, by its
     * name or as [first-event-name] or [last-event-name] (deltaNamesItself).
     * A shorthand must name something in every entry it can:
     * deltaShorthandProblem. A refusal that suggests a reference carries its
     * branch group for the tags after this one (deltaAdvisedGroup); $at is
     * the tag's place in the field's parseAllTags list, and with it the
     * answer is worked out once for the tag (deltaRemembered).
     */
    private function annotateDeltaDictionary(array $frag, $name, $types, $choices, $pid = null, $at = null)
    {
        return $this->deltaRemembered('dictionary', $name, $at, function () use ($frag, $name, $pid, $at) {
            return $this->deltaDictionaryHook($frag, $name, $pid, $at);
        });
    }

    /** annotateDeltaDictionary's answer, worked out. */
    private function deltaDictionaryHook(array $frag, $name, $pid, $at)
    {
        $tag = AnnotationRules::TAG_DELTA;
        $refuse = function ($why, $advised = null) use ($tag, $name, $frag) {
            return ['error' => $why, '_tag' => $tag] + self::deltaAdvisedGroup($advised, $name, $frag);
        };
        if (!isset($frag['deltaVs']) || !is_string($frag['deltaVs'])) return $frag;
        $op = ModeRegistry::operandRef($frag['deltaVs'], $this->temporalOptions($pid));
        if ($op === null) return $frag;   // refused already by AnnotationRules::checkDelta
        $f = (string) $op[1];
        $dd = $this->dataDictionary($pid);
        if (!$dd || !isset($dd[$f])) return $refuse('"vs" names "[' . $f . ']", which is not a field in this project.');
        $meta = $dd[$f];
        $ftype = isset($meta['field_type']) ? $meta['field_type'] : '';
        $validation = $ftype === 'text' ? self::validationOf($meta) : '';
        $re = ModeRegistry::eligibility('delta')['textValidations'] ?? null;
        if (!in_array($ftype, ['calc', 'slider'], true) && !($ftype === 'text' && is_string($re) && preg_match('~' . $re . '~', $validation))) {
            return $refuse('"vs" field "' . $f . '" is not a number field — it needs integer or number validation, or none, '
                . 'or to be a calc or a slider.');
        }
        if (substr($validation, -strlen('_comma_decimal')) === '_comma_decimal') $frag['deltaVsComma'] = true;
        $short = isset($frag['deltaVsName']) ? $frag['deltaVsName'] : null;
        $form = isset($dd[$name]['form_name']) ? (string) $dd[$name]['form_name'] : '';
        $shape = $this->temporalShape($pid, $dd);
        $direction = isset($frag['deltaDirection']) ? $frag['deltaDirection'] : 'both';
        $annotation = isset($dd[$name]['field_annotation']) ? $dd[$name]['field_annotation'] : '';
        // worked out only once a suggestion is searched for (review R9-8)
        $taken = function () use ($frag, $name, $annotation, $pid, $at) { return $this->deltaGroupsTaken($frag, $name, $annotation, $pid, $at); };
        $advised = null;
        if ($short === null) {
            $vsForm = isset($meta['form_name']) ? (string) $meta['form_name'] : '';
            $refsOn = !empty($this->temporalOptions($pid)['qualified']);
            $dead = self::deltaDeadReference($shape, $op, $f, $form, $vsForm, $refsOn, $name, $taken, $direction, $advised);
            if ($dead !== null) return $refuse($dead, $advised);
            if ($op[0] === 'qref' && $f === $name && self::deltaNamesItself($shape, $op, $form)) {
                $c = self::deltaSuggestion($shape, $op, $f, $form, $vsForm, $name, false, $taken, $direction);
                return $refuse('"vs" names this field in this same entry, so the change would always be 0 — compare it with another entry'
                    . ($c !== null ? ', as "' . $c['ref'] . '"' . ($c['qualified'] && !$refsOn ? self::DELTA_NEEDS_REFS : '') : self::deltaHeldNote($taken)) . '.',
                    $c !== null ? $c['ref'] : null);
            }
            return $frag;
        }
        $readable = null;
        $why = self::deltaShorthandProblem($shape, $short, $form, $name, $taken, $direction, true, $readable, $advised);
        return $why === null ? $frag : $refuse($why, $advised);
    }

    /**
     * Whether a written-out "vs" reference to the field itself ($op, a qref)
     * names the entry being judged in every entry of $form. At each event
     * that holds the form, its event token must resolve to that same event
     * (none, [event-name], [first-event-name] where no earlier event of the
     * arm holds the form, or the one event's name), and its instance must be
     * this one: none or [current-instance]. (Any instance of a form that does
     * not repeat is refused before this, by deltaDeadReference.) A layout that
     * cannot be read answers false; the resolver reports it on each save.
     */
    private static function deltaNamesItself(ProjectShape $shape, array $op, $form)
    {
        $event = isset($op[3]) ? $op[3] : null;
        $instance = isset($op[4]) ? $op[4] : null;
        if ($instance !== null && $instance !== 'current-instance') return false;
        $hosts = [];
        foreach ($shape->events() as $id => $e) {
            if (!isset($e['forms']) || !is_array($e['forms'])) return false;
            if (in_array($form, $e['forms'], true)) $hosts[] = $id;
        }
        if (!$hosts) return false;
        foreach ($hosts as $h) {
            $r = $shape->eventId($event, $h, $form);
            if (!isset($r['state']) || $r['state'] !== 'ok' || (string) $r['event'] !== (string) $h) return false;
        }
        return true;
    }

    /**
     * What keeps a "vs" reference ($op, written plain or out) on a field of
     * $form from being read on some save, or null. Each event that holds
     * $form is asked what AddressResolver::resolve (or, with references off,
     * the plain read of this event) would answer there, in its order:
     *  - the reference lands on an event that does not hold the "vs" form
     *    ($vsForm): kind "missing", or "nowhere" when no event holds it;
     *  - it names an instance of a form held once there: "flat";
     *  - it names a relative instance ([current-instance], [previous-instance],
     *    [next-instance]) where this field's own form is held once: "own",
     *    since a relative instance counts from this field's instance;
     *  - it names no instance of a form that repeats there, and the row is not
     *    this field's own (its own repeating form, or a form of the same
     *    repeating event): "ambiguous";
     *  - and a relative event token finds no event from any of them, so the
     *    rule would never compare anything: "absent".
     * An event the token finds nothing from (the first visit has no previous
     * one) is not a problem by itself. A layout that cannot be read answers
     * null; the resolver reports it on each save. The answer is
     * ['kind' => ..., 'events' => the event ids concerned].
     */
    private static function deltaReferenceProblem(ProjectShape $shape, array $op, $form, $vsForm)
    {
        $token = $op[0] === 'qref' && isset($op[3]) ? (string) $op[3] : null;
        $sel = $op[0] === 'qref' && isset($op[4]) ? (string) $op[4] : null;
        $relative = in_array($token, ['previous-event-name', 'next-event-name', 'first-event-name', 'last-event-name'], true);
        $named = $token !== null && $token !== 'event-name' && !$relative;
        $add = function (array &$list, $id) { if (!in_array($id, $list, true)) $list[] = $id; };
        $missing = $flat = $own = $amb = [];
        $hosts = 0;
        $absent = 0;
        $held = false;
        foreach ($shape->events() as $id => $e) {
            if (!isset($e['forms']) || !is_array($e['forms'])) return null;
            if (in_array($vsForm, $e['forms'], true)) $held = true;
            if (!in_array($form, $e['forms'], true)) continue;
            $hosts++;
            $r = $shape->eventId($token, $id, $vsForm);
            if (isset($r['state']) && $r['state'] === 'absent') { $absent++; continue; }
            if (!isset($r['state']) || $r['state'] !== 'ok') continue;
            $b = $shape->bucket($r['event'], $vsForm);
            if (isset($b['state']) && $b['state'] === 'missing') { $add($missing, $named ? $r['event'] : $id); continue; }
            if (!isset($b['state']) || $b['state'] !== 'ok' || !array_key_exists('bucket', $b)) continue;
            $src = $shape->bucket($id, $form);
            if (!isset($src['state']) || $src['state'] !== 'ok' || !array_key_exists('bucket', $src)) return null;
            if ($sel === null) {
                $ownRow = (string) $r['event'] === (string) $id && $src['bucket'] === $b['bucket'];
                if ($b['bucket'] !== null && !$ownRow) $add($amb, $r['event']);
            } elseif ($b['bucket'] === null) {
                $add($flat, $r['event']);
            } elseif (in_array($sel, ['current-instance', 'previous-instance', 'next-instance'], true) && $src['bucket'] === null) {
                $add($own, $id);
            }
        }
        if ($hosts === 0) return null;
        if (!$held) return ['kind' => 'nowhere', 'events' => []];
        if ($missing) return ['kind' => 'missing', 'events' => $missing];
        if ($flat) return ['kind' => 'flat', 'events' => $flat];
        if ($own) return ['kind' => 'own', 'events' => $own];
        if ($amb) return ['kind' => 'ambiguous', 'events' => $amb];
        if ($relative && $absent === $hosts) return ['kind' => 'absent', 'events' => []];
        return null;
    }

    /**
     * Why a "vs" reference ($op) on field $name of $form could not be read on
     * some save of some entry (deltaReferenceProblem), as a refusal, or null.
     * The refusal quotes the reference as it was written and ends with one
     * that works (deltaSuggestion, outside the branch groups in $taken), or
     * says what the search tried, and why it passed one over
     * (deltaHeldNote); $advised is set to the reference it suggests.
     * $refsOn says whether that reference can be used as the settings
     * stand.
     */
    private static function deltaDeadReference(ProjectShape $shape, array $op, $f, $form, $vsForm, $refsOn, $name, $taken = [], $direction = 'both', &$advised = null)
    {
        $advised = null;
        $p = self::deltaReferenceProblem($shape, $op, $form, $vsForm);
        if ($p === null) return null;
        $token = $op[0] === 'qref' && isset($op[3]) ? (string) $op[3] : null;
        $sel = $op[0] === 'qref' && isset($op[4]) ? (string) $op[4] : null;
        $written = ($token !== null ? '[' . $token . ']' : '') . '[' . $f . ']' . ($sel !== null ? '[' . $sel . ']' : '');
        $many = count($shape->events()) > 1;
        $names = [];
        foreach ($p['events'] as $id) {
            $e = $shape->event($id);
            $names[] = isset($e['name']) && $e['name'] !== '' ? (string) $e['name'] : (string) $id;
        }
        $at = $many && $names ? ' at "' . implode('", "', $names) . '"' : '';
        $plain = $token === null || $token === 'event-name';
        switch ($p['kind']) {
            case 'nowhere':
                return '"vs" names "' . $written . '", but "' . $f . '" is on the form "' . $vsForm . '", which is not designated at any event.';
            case 'missing':
                $why = '"vs" names "' . $written . '", but "' . $f . '" is on the form "' . $vsForm . '", which is not designated' . $at
                    . ($plain ? ' where this field is entered' : '');
                break;
            case 'flat':
                $why = '"vs" names "' . $written . '", but its form "' . $vsForm . '" does not repeat' . $at
                    . ', and an instance can be named only where the form repeats';
                break;
            case 'own':
                $why = '"vs" names "' . $written . '", but this field\'s form "' . $form . '" does not repeat' . $at
                    . ', and [current-instance], [previous-instance] and [next-instance] count from this field\'s own instance';
                break;
            case 'ambiguous':
                $why = '"vs" names "' . $written . '" with no instance, but its form "' . $vsForm . '" repeats' . $at
                    . ', so which instance it means is not said';
                break;
            default:
                $why = '"vs" names "' . $written . '", but from no event where this field is entered does "[' . $token
                    . ']" find an event holding "' . $vsForm . '", so nothing would ever be compared';
        }
        $tried = '';
        $c = self::deltaSuggestion($shape, $op, $f, $form, $vsForm, $name, in_array($p['kind'], ['flat', 'own', 'ambiguous'], true), $taken, $direction, $tried);
        if ($c !== null) {
            $advised = $c['ref'];
            return $why . ' — write it as "' . $c['ref'] . '"' . ($c['qualified'] && !$refsOn ? self::DELTA_NEEDS_REFS : '') . '.';
        }
        $note = self::deltaHeldNote($taken);
        if ($note === self::DELTA_UNREAD) return $why . $note . '.';
        return $why . ' — and none of the references to "[' . $f . ']" this check tried (' . $tried . ') works'
            . ($many ? ' from every event where this field is entered' : '') . $note . '.';
    }

    /** Appended to a suggested reference that needs a setting turned on. */
    const DELTA_NEEDS_REFS = ' (this needs event and instance references, a project setting)';

    /**
     * A reference to $f that deltaReferenceProblem accepts at every event of
     * $form, as ['ref' => text, 'qualified' => whether it names an event or
     * an instance], or null; then $tried says, in words, what was tried. The
     * events, in turn:
     *  - the event as written ($keepEvent), when only the instance is wrong;
     *  - on a field compared with its own form, the previous and first
     *    events, then the first event that holds the form, named outright;
     *    with another form, up to five events named outright first, then the
     *    first, previous and last events;
     *  - then this event (a plain reference).
     * The next event is never offered, nor, on the field's own form, the last
     * event or a later one named outright, and the event as written goes by
     * the same rule (review R9-4): each is a later visit from every event but
     * one, blank at first entry, where "direction" would read backwards
     * against "an earlier value" (review R8-7). Each event goes with the
     * instance as written, then none, then [last-instance] and
     * [previous-instance], and for a reference to $name itself
     * [first-instance] last (review R9-6). Each candidate is checked by
     * deltaCandidate, which also keeps a reference to $name itself off this
     * same entry and off an instance that does not fit where its event
     * lands. A token that lands on the event it is read from, from every
     * event of $form, means this event and is left to the plain reference
     * (deltaLandsOwn); the event as written is tried even so.
     *
     * An event is named outright, the one as written included, only when
     * every event of $form is in its arm: from another arm it holds a value
     * only for a record entered in that arm too, and for every other record
     * the check would stop without saying so. One event means no event to
     * name: only this event is tried, with each instance. A $form designated
     * at no event gets nothing, since no event could check what is offered.
     * No candidate whose branch group is held is offered (deltaHeld, review
     * R8-4); $taken comes back as deltaHeld leaves it.
     *
     * Each candidate asks every event of $form, and a relative event token
     * walks the arm from each, so the search costs about the square of the
     * number of events: half a second at 600 events. Rules are built once per
     * request, so this runs for each refused reference on every data entry
     * page, survey page and save that reads the rules.
     */
    private static function deltaSuggestion(ProjectShape $shape, array $op, $f, $form, $vsForm, $name, $keepEvent, &$taken = [], $direction = 'both', &$tried = null)
    {
        $token = $op[0] === 'qref' && isset($op[3]) ? (string) $op[3] : null;
        $sel = $op[0] === 'qref' && isset($op[4]) ? (string) $op[4] : null;
        $tried = '';
        $arms = [];
        $holders = [];
        $armOf = [];    // event name => arm
        foreach ($shape->events() as $id => $e) {
            if (!isset($e['forms']) || !is_array($e['forms'])) return null;
            $arm = isset($e['arm']) ? (string) $e['arm'] : '';
            if (isset($e['name']) && $e['name'] !== '') $armOf[(string) $e['name']] = $arm;
            if (in_array($form, $e['forms'], true)) $arms[$arm] = true;
            if (in_array($vsForm, $e['forms'], true) && isset($e['name']) && $e['name'] !== '') $holders[] = [$arm, (string) $e['name']];
        }
        if (!$arms) return null;    // $form at no event: no event can check a candidate
        $inArm = $token === null || $token === 'event-name' || in_array($token, ['previous-event-name', 'next-event-name', 'first-event-name', 'last-event-name'], true)
            || (count($arms) === 1 && isset($armOf[$token]) && isset($arms[$armOf[$token]]));
        // on its own form a later event is a later visit: of the events named outright only
        // the arm's first holder is offered, and the event as written goes by the same rule
        $first = null;
        foreach ($holders as $h) if ($first === null && isset($arms[$h[0]])) $first = $h[1];
        $later = $token === 'next-event-name'
            || ($vsForm === $form && !in_array($token, [null, 'event-name', 'previous-event-name', 'first-event-name', $first], true));
        $kept = $keepEvent && $inArm && !$later;
        $tokens = $kept ? [$token] : [];
        $named = [];
        $many = count($shape->events()) > 1;
        $relatives = $vsForm === $form ? ['previous-event-name', 'first-event-name'] : ['first-event-name', 'previous-event-name', 'last-event-name'];
        if ($many) {
            if (count($arms) === 1) foreach ($holders as $h) if (isset($arms[$h[0]]) && count($named) < ($vsForm === $form ? 1 : 5)) $named[] = $h[1];
            $tokens = array_merge($tokens, $vsForm === $form ? array_merge($relatives, $named) : array_merge($named, $relatives), [null]);
        } else {
            $tokens[] = null;
        }
        // for the field itself [first-instance] comes last: it fits where neither [last-instance]
        // nor [previous-instance] does, as at a first visit that alone repeats (review R9-6)
        $try = [null, 'last-instance', 'previous-instance'];
        if ($f === $name) $try[] = 'first-instance';
        $seen = [serialize([$token, $sel]) => true];
        $instances = [];
        foreach ($tokens as $t) {
            // a token that lands on the event it is read from, from every event of $form,
            // means this event: the plain reference, tried last, says so in fewer words
            if ($t !== null && !($kept && $t === $token) && self::deltaLandsOwn($shape, $t, $form, $vsForm)) continue;
            $sels = [$sel];
            foreach ($try as $s) if (!in_array($s, $sels, true)) $sels[] = $s;
            foreach ($sels as $s) {
                $instances[$s === null ? '' : $s] = true;
                $key = serialize([$t, $s]);
                if (isset($seen[$key])) continue;
                $seen[$key] = true;
                $ref = self::deltaCandidate($shape, $t, $f, $s, $form, $vsForm, $name, $taken, $direction);
                if ($ref !== null) return ['ref' => $ref, 'qualified' => $t !== null || $s !== null];
            }
        }
        $tried = self::deltaTriedText($many, $kept ? $token : false, $relatives, $named, $sel, $instances);
        return null;
    }

    /**
     * Event token $t (or none), field $f and instance $s (or none) written
     * as a reference, when it can be suggested to field $name of $form, else
     * null. deltaReferenceProblem must accept it at every event of $form. A
     * reference to $name itself must not be the plain one or name this same
     * entry (deltaNamesItself), and its instance must fit where the event
     * lands (deltaSelfInstanceFits). Its branch group, with $direction, must
     * not be held (deltaHeld), which is asked only of a candidate that gets
     * that far (review R9-8).
     */
    private static function deltaCandidate(ProjectShape $shape, $t, $f, $s, $form, $vsForm, $name, &$taken, $direction)
    {
        $cop = $t === null && $s === null ? ['ref', $f, null] : ['qref', $f, null, $t, $s];
        if (self::deltaReferenceProblem($shape, $cop, $form, $vsForm) !== null) return null;
        if ($f === $name && ($cop[0] === 'ref' || self::deltaNamesItself($shape, $cop, $form)
                || !self::deltaSelfInstanceFits($shape, $t, $s, $form))) return null;
        $ref = ($t !== null ? '[' . $t . ']' : '') . '[' . $f . ']' . ($s !== null ? '[' . $s . ']' : '');
        return self::deltaHeld($taken, AnnotationRules::deltaGroup($ref, $direction)) ? null : $ref;
    }

    /**
     * Whether event token $t, read for $vsForm, lands from every event of
     * $form on that same event, and so says what the plain reference says:
     * no token and [event-name] always do. A token that finds no event from
     * one of them does not: there it reads nothing, where the plain
     * reference reads this event (review R9-2).
     */
    private static function deltaLandsOwn(ProjectShape $shape, $t, $form, $vsForm)
    {
        if ($t === null || $t === 'event-name') return true;
        foreach ($shape->events() as $id => $e) {
            if (!isset($e['forms']) || !is_array($e['forms']) || !in_array($form, $e['forms'], true)) continue;
            $r = $shape->eventId($t, $id, $vsForm);
            if (!isset($r['state']) || $r['state'] !== 'ok' || (string) $r['event'] !== (string) $id) return false;
        }
        return true;
    }

    /**
     * Whether instance $s of a reference to the field itself fits each event
     * that token $t lands on from an event of $form, where the form repeats
     * there. [next-instance] fits nowhere: at this entry's own event it is a
     * later entry of the same visit, and at another event it counts from this
     * entry's instance number (review R9-5). At this entry's own event
     * [last-instance] is the newest entry, perhaps this one or a later one
     * (review R8-2), and so is a numbered instance after the first. At
     * another event [previous-instance] and [current-instance] count from this
     * entry's instance number (review R8-1, R9-5). [first-instance] and
     * instance 1 fit anywhere: at this entry's own event they are an earlier
     * entry, or this one at instance 1. Where the form does not repeat,
     * deltaReferenceProblem decides; a layout it cannot read is left to the
     * resolver.
     */
    private static function deltaSelfInstanceFits(ProjectShape $shape, $t, $s, $form)
    {
        if ($s === 'next-instance') return false;
        $numbered = is_string($s) && ctype_digit($s) && (int) $s > 1;
        if (!$numbered && !in_array($s, ['last-instance', 'previous-instance', 'current-instance'], true)) return true;
        foreach ($shape->events() as $id => $e) {
            if (!isset($e['forms']) || !is_array($e['forms']) || !in_array($form, $e['forms'], true)) continue;
            $r = $shape->eventId($t, $id, $form);
            if (!isset($r['state']) || $r['state'] !== 'ok') continue;
            $b = $shape->bucket($r['event'], $form);
            if (!isset($b['state']) || $b['state'] !== 'ok' || $b['bucket'] === null) continue;
            $own = (string) $r['event'] === (string) $id;
            if (($s === 'last-instance' || $numbered) ? $own : !$own) return false;
        }
        return true;
    }

    /**
     * What deltaSuggestion tried, in words, for the refusal that says none of
     * it works: the events (when the project has more than one) and the
     * instances. $kept is the event token kept as written, false when none
     * was; $relatives the relative event tokens tried; $instances the
     * instance selectors met ('' for none).
     */
    private static function deltaTriedText($many, $kept, array $relatives, array $named, $sel, array $instances)
    {
        $join = function (array $parts, $last) {
            $tail = array_pop($parts);
            return $parts ? implode(', ', $parts) . $last . $tail : $tail;
        };
        $events = [];
        if ($many) {
            if ($kept !== false) $events[] = $kept === null || $kept === 'event-name' ? 'this event' : 'the event as written';
            $rel = array_map(function ($t) { return substr($t, 0, strpos($t, '-')); }, $relatives);
            $events[] = 'the ' . $join(in_array('previous', $rel, true) ? array_merge(['previous'], array_values(array_diff($rel, ['previous']))) : $rel, ' or ') . ' event';
            if ($named) $events[] = (count($named) === 1 ? 'the event ' : 'the events ') . $join(array_map(function ($n) { return '"' . $n . '"'; }, $named), ' and ');
            if (!in_array('this event', $events, true)) $events[] = 'this event';
        }
        $words = [];
        if ($sel !== null) $words[] = 'the instance as written';
        if (isset($instances[''])) $words[] = $words ? 'none' : 'no instance';
        if (isset($instances['last-instance']) && $sel !== 'last-instance') $words[] = 'the last';
        if (isset($instances['previous-instance']) && $sel !== 'previous-instance') $words[] = 'the previous';
        if (isset($instances['first-instance']) && $sel !== 'first-instance') $words[] = 'the first';
        $last = end($words);
        if ($last === 'the last' || $last === 'the previous' || $last === 'the first') $words[count($words) - 1] .= ' one';
        return ($events ? $join($events, ', or ') . ', ' : '') . 'with ' . $join($words, ' or ');
    }

    /**
     * Why a "vs" shorthand on a field of $form cannot name an entry to compare
     * with, or null. Each entry resolves its own shorthand (ProjectShape at
     * run time), so this asks of every event that holds the form, arm by arm:
     *  - an event shorthand needs, for at least one event, an earlier event of
     *    the same arm that holds the form, and no event it names may hold the
     *    form more than once (the form repeats there, or the whole event
     *    does): which instance it means would not be said;
     *  - an instance shorthand needs the form to repeat at every event that
     *    holds it, on its own or with the whole event: where it does not,
     *    there is no other instance.
     * A layout that cannot be read answers null, and sets $readable false;
     * the resolver then reports what it cannot resolve, on each save. The
     * refusal of a shorthand that names a repeating event ends with a
     * written reference that works there ($example), and sets $advised to
     * it: the shorthand's own event token with [last-instance], else with
     * [first-instance], each checked by deltaCandidate outside the branch
     * groups in $taken. Any other event, named outright or relative, would
     * mean another entry than the shorthand did in some project (review
     * R8-5, R9-3), so when neither instance works there is no example, and
     * the refusal says why when one was passed over (deltaHeldNote).
     */
    private static function deltaShorthandProblem(ProjectShape $shape, $short, $form, $name, $taken = [], $direction = 'both', $example = true, &$readable = null, &$advised = null)
    {
        $readable = false;
        $advised = null;
        $hosts = [];
        foreach ($shape->events() as $id => $event) {
            if (!isset($event['forms']) || !is_array($event['forms'])) return null;
            if (in_array($form, $event['forms'], true)) $hosts[] = $id;
        }
        if (!$hosts) return null;
        $label = function ($id) use ($shape) {
            $e = $shape->event($id);
            return isset($e['name']) && $e['name'] !== '' ? (string) $e['name'] : (string) $id;
        };
        $list = function (array $names) {
            $last = array_pop($names);
            return ($names ? '"' . implode('", "', $names) . '" and ' : '') . '"' . $last . '"';
        };
        if ($short === 'previous-instance' || $short === 'first-instance') {
            $flat = [];
            foreach ($hosts as $id) {
                $b = $shape->bucket($id, $form);
                if ($b['state'] !== 'ok') return null;
                if ($b['bucket'] === null) $flat[] = $label($id);
            }
            $readable = true;
            if (!$flat) return null;
            return '"vs": "' . $short . '" needs a form that repeats — "' . $form . '" does not repeat '
                . (count($hosts) === 1 ? 'in this project' : 'at ' . $list($flat)) . '.';
        }
        $token = $short === 'previous-event' ? 'previous-event-name' : 'first-event-name';
        $pairs = 0;
        $repeating = [];
        foreach ($hosts as $id) {
            $t = $shape->eventId($token, $id, $form);
            if ($t['state'] === 'absent') continue;
            if ($t['state'] !== 'ok') return null;
            if ((string) $t['event'] === (string) $id) continue;   // the first event itself: nothing earlier
            $pairs++;
            $b = $shape->bucket($t['event'], $form);
            if ($b['state'] !== 'ok') return null;
            if ($b['bucket'] !== null) $repeating[(string) $t['event']] = $label($t['event']);
        }
        $readable = true;
        if ($pairs === 0) {
            return '"vs": "' . $short . '" needs the form on two or more events of one arm — "' . $form . '" is on '
                . (count($hosts) === 1 ? 'one event' : 'no two events of the same arm') . ' in this project.';
        }
        if ($repeating) {
            $c = null;
            if ($example) foreach (['last-instance', 'first-instance'] as $s) {
                $c = self::deltaCandidate($shape, $token, $name, $s, $form, $form, $name, $taken, $direction);
                if ($c !== null) break;
            }
            $advised = $c;
            return '"vs": "' . $short . '" names ' . $list(array_values($repeating)) . ', where "' . $form . '" repeats, and does not say which '
                . 'instance to compare with — name the event and the instance' . ($c !== null ? ', as "vs":"' . $c . '"' : self::deltaHeldNote($taken)) . '.';
        }
        return null;
    }

    /**
     * An @UVDELTA rule's limits in words, for the message and the scan
     * report: "a change of at most 5 kg and 10%", "a rise of at most 10%",
     * "no fall". A comma field shows 2,5.
     */
    private static function deltaText(array $frag, $comma)
    {
        $f = function ($n) use ($comma) { return $comma ? str_replace('.', ',', (string) $n) : (string) $n; };
        $dir = isset($frag['deltaDirection']) ? $frag['deltaDirection'] : 'both';
        $word = $dir === 'up' ? 'rise' : ($dir === 'down' ? 'fall' : 'change');
        $max = isset($frag['deltaMax']) && is_string($frag['deltaMax']) ? $frag['deltaMax'] : null;
        $pct = isset($frag['deltaMaxPct']) && is_string($frag['deltaMaxPct']) ? $frag['deltaMaxPct'] : null;
        // "max": 0 allows no move at all: a percent says nothing more
        // ("maxPct": 0 is refused, AnnotationRules::checkDelta)
        if ($max !== null && Logic::numCompare($max, '0') === 0) return 'no ' . $word;
        $parts = [];
        if ($max !== null) $parts[] = $f($max) . (isset($frag['deltaUnit']) && $frag['deltaUnit'] !== '' ? ' ' . $frag['deltaUnit'] : '');
        if ($pct !== null) $parts[] = $f($pct) . '%';
        return 'a ' . $word . ' of at most ' . implode(' and ', $parts);
    }

    /**
     * One tier of an @UVRANGE rule in words: "12 to 17.5 g/dL", "at least 12",
     * "at most 17.5"; null for a tier with no limits. A comma field shows 17,5.
     */
    private static function rangeText($lo, $hi, $unit, $comma)
    {
        $f = function ($n) use ($comma) { return $comma ? str_replace('.', ',', (string) $n) : (string) $n; };
        $u = $unit !== '' ? ' ' . $unit : '';
        if ($lo !== null && $hi !== null) return $f($lo) . ' to ' . $f($hi) . $u;
        if ($lo !== null) return 'at least ' . $f($lo) . $u;
        if ($hi !== null) return 'at most ' . $f($hi) . $u;
        return null;
    }

    /**
     * @UVWINDOW "dictionary" hook: the "from" field must exist, must be a date
     * field of the same kind (a date window counts from a date, a datetime
     * window from a datetime), and must not be the field itself unless it is
     * read in another event or instance. Its type and format travel on the
     * rule the same way the field's own do.
     */
    private function annotateWindowDictionary(array $frag, $name, $types, $choices, $pid = null)
    {
        if (!isset($frag['windowFrom'])) return $frag;
        $op = ModeRegistry::operandRef($frag['windowFrom'], $this->temporalOptions($pid));
        if ($op === null) return $frag;   // refused already by AnnotationRules::checkWindow
        $from = (string) $op[1];
        $refuse = function ($why) { return ['error' => $why, '_tag' => AnnotationRules::TAG_WINDOW]; };
        $dd = $this->dataDictionary($pid);
        if (!$dd || !isset($dd[$from])) {
            return $refuse('"from" names "[' . $from . ']", which is not a field in this project.');
        }
        // [event-name][f] and [f][current-instance] are this same entry too. Another
        // event or instance is not, except where it happens to be the current one;
        // TemporalRules marks that case and the window then does not apply.
        if ($from === $name && ($op[0] === 'ref'
                || (in_array($op[3], [null, 'event-name'], true) && in_array($op[4], [null, 'current-instance'], true)))) {
            return $refuse('"from" names this field itself — a window needs another date to count from.');
        }
        $meta = $dd[$from];
        $tv = (isset($meta['field_type']) && $meta['field_type'] === 'text')
            ? TemporalValue::fromValidation(self::validationOf($meta)) : null;
        if ($tv === null) {
            return $refuse('"from" field "' . $from . '" is not a date field — it needs date, datetime or '
                . 'datetime-with-seconds validation.');
        }
        $own = isset($frag['dateType']) ? $frag['dateType'] : null;
        if (TemporalValue::family($tv['type']) !== TemporalValue::family($own)) {
            return $refuse('"from" field "' . $from . '" holds ' . ($tv['type'] === 'date' ? 'dates' : 'dates with a time')
                . ' and this field holds ' . ($own === 'date' ? 'dates' : 'dates with a time')
                . ' — a window counts a date from a date, or a datetime from a datetime.');
        }
        // A plain [field], and [field][N] or [event-name][field], is read in the
        // same event. Where no event collects both forms, the "from" date is
        // blank on every entry of this field.
        $sameEvent = $op[0] === 'ref' || ($op[0] === 'qref' && in_array($op[3], [null, 'event-name'], true));
        if ($sameEvent && isset($dd[$name]['form_name'], $meta['form_name'])
                && $dd[$name]['form_name'] !== $meta['form_name']) {
            $sets = $this->formEventSets($pid);
            $hostForm = $dd[$name]['form_name'];
            $fromForm = $meta['form_name'];
            if ($sets !== null && isset($sets[$hostForm], $sets[$fromForm])
                    && !array_intersect_key($sets[$hostForm], $sets[$fromForm])) {
                return $refuse('"from" field "' . $from . '" is on the form "' . $fromForm . '", which no event of this '
                    . 'field collects, so it would always be blank here — name its event, as [event_name][' . $from
                    . '] (this needs event and instance references, a project setting).');
            }
        }
        $frag['fromType'] = $tv['type'];
        $frag['fromFormat'] = $tv['format'];
        return $frag;
    }

    /**
     * Data dictionary for the project (cached per request), or null. Prefers an
     * explicitly passed $pid (the hook's project_id) over $this->getProjectId(),
     * which is unreliable in import/API/cron save contexts — without this, the
     * dictionary silently fails to load there and every @UVALIDATE rule is dropped
     * from the server-side audit.
     */
    private function dataDictionary($pid = null)
    {
        // KEYED BY PID, and only successes are kept.
        //
        // This was one slot, tested before $pid was even read, and it stored the
        // FAILURE as eagerly as the answer. Two consequences, both bad:
        //
        //   - a single transient read failure disabled every annotation rule,
        //     every field-name check and every host resolution for the rest of
        //     the request, and no later call could recover it because no later
        //     call asked again;
        //   - the docblock above promises that an explicitly passed $pid is
        //     preferred, precisely because getProjectId() is unreliable in
        //     import/API/cron contexts - but after the first call the argument
        //     was never looked at again, so one call without a project context
        //     poisoned every subsequent call that DID pass the right pid.
        //
        // The scan reports its own failure honestly; redcap_save_record shares
        // this helper and would drop the rule set in silence, which is the class
        // of failure the module exists to prevent.
        if (!$pid) $pid = $this->getProjectId();
        if (!$pid) return null;
        if (isset($this->ddCache[$pid])) return $this->ddCache[$pid];
        try {
            $dd = \REDCap::getDataDictionary($pid, 'array');
            if (is_array($dd) && $dd) {
                $this->ddCache[$pid] = $dd;
                return $dd;
            }
        } catch (\Throwable $e) {
            // outside project context (or dictionary unavailable): no annotation
            // rules and no field-name checking, never a fatal error
        }
        return null;   // NOT cached: a read that failed is not an answer
    }

    /** Field names of the project, or null when the dictionary is unavailable. */
    private function projectFieldNames($pid = null)
    {
        $dd = $this->dataDictionary($pid);
        return $dd ? array_keys($dd) : null;
    }

    /**
     * Field name => true for every field REDCap flags as an Identifier, or null
     * when the dictionary is unavailable. Used to REFUSE the @UVUNIQUE survey
     * opt-in on identifying fields: a survey-side used/free reply is an
     * unauthenticated existence oracle, and on an identifier that means anyone
     * holding the survey link could test whether a specific person is in the
     * study. REDCap already knows which fields those are — so the module does
     * not rely on the designer reading a warning (security scan 15 Jul 2026,
     * no-auth-ajax advisory).
     */
    private function projectIdentifierFields($pid = null)
    {
        $dd = $this->dataDictionary($pid);
        if (!$dd) return null;
        $out = [];
        foreach ($dd as $name => $meta) {
            $flag = isset($meta['identifier']) ? strtolower(trim((string) $meta['identifier'])) : '';
            if ($flag === 'y' || $flag === 'yes' || $flag === '1' || $flag === 'true') $out[$name] = true;
        }
        return $out;
    }

    /** Field name => field type map, or null when the dictionary is unavailable. */
    private function projectFieldTypes($pid = null)
    {
        $dd = $this->dataDictionary($pid);
        if (!$dd) return null;
        $types = [];
        foreach ($dd as $name => $meta) {
            $types[$name] = isset($meta['field_type']) ? $meta['field_type'] : '';
        }
        return $types;
    }

    /** The trimmed validation name of one dictionary row ('' for none). */
    private static function validationOf(array $meta)
    {
        return isset($meta['text_validation_type_or_show_slider_number'])
            ? trim((string) $meta['text_validation_type_or_show_slider_number']) : '';
    }

    /**
     * Values typed on a page, rewritten into the form REDCap stores. A date or
     * datetime input holds the value the way the field displays it (31-12-2024
     * on a D-M-Y field), while getData returns Y-M-D, so an exact comparison of
     * the two never matches. A value that is not a complete date in the field's
     * format stays as typed, and so does every value when the dictionary cannot
     * be read.
     */
    private function storedFormOf($pid, array $values)
    {
        $dd = $this->dataDictionary($pid);
        if (!$dd) return $values;
        foreach ($values as $f => $v) {
            if (!isset($dd[$f]) || !is_array($dd[$f])) continue;
            $tv = TemporalValue::fromValidation(self::validationOf($dd[$f]));
            if ($tv === null) continue;
            $p = TemporalValue::parse(trim((string) $v), $tv['type'], $tv['format']);
            if ($p['state'] === 'ok') $values[$f] = TemporalValue::format($p['value'], $tv['type'], 'ymd');
        }
        return $values;
    }

    /**
     * The inverse of storedFormOf: stored values (field => value) rewritten
     * the way each field of project $pid shows them on a page, so a date
     * copied by @UVEXISTS "return" lands in a D-M-Y field as 31-12-2024. A
     * value that is not a complete stored date stays as it is, and so does
     * every value when the dictionary cannot be read.
     */
    private function displayFormOf($pid, array $values)
    {
        $dd = $this->dataDictionary($pid);
        if (!$dd) return $values;
        foreach ($values as $f => $v) {
            if (!isset($dd[$f]) || !is_array($dd[$f])) continue;
            $tv = TemporalValue::fromValidation(self::validationOf($dd[$f]));
            if ($tv === null) continue;
            $p = TemporalValue::parse(trim((string) $v), $tv['type'], 'ymd');
            if ($p['state'] === 'ok') $values[$f] = TemporalValue::format($p['value'], $tv['type'], $tv['format']);
        }
        return $values;
    }

    /**
     * Choice field => [choice codes] map, or null when the dictionary is
     * unavailable. Covers the multiple-choice family (checkbox, radio,
     * dropdown) — Logic::checkRefs consults it for checkbox "when" references
     * only, and @UVCHOICES eligibility/code checks read the radio/dropdown
     * rows. Calc/sql rows are excluded: their
     * select_choices_or_calculations holds an equation/query, not choices.
     */
    private function projectFieldChoices($pid = null)
    {
        $dd = $this->dataDictionary($pid);
        if (!$dd) return null;
        $choices = [];
        foreach ($dd as $name => $meta) {
            $ftype = isset($meta['field_type']) ? $meta['field_type'] : '';
            if (!in_array($ftype, ['checkbox', 'radio', 'dropdown'], true)) continue;
            $raw = isset($meta['select_choices_or_calculations']) ? $meta['select_choices_or_calculations'] : '';
            $codes = Logic::parseChoiceCodes($raw);
            if ($codes) $choices[$name] = $codes;
        }
        return $choices;
    }

    /**
     * The set of field names on one instrument (field => true), or null when the
     * instrument or dictionary is unknown — null means "do not filter", the
     * conservative choice for import/API contexts where the hook's instrument
     * argument may be absent or not match a form name.
     */
    /**
     * The fields on the page of a multi-page survey that REDCap is rendering,
     * as field => true, or null when the whole instrument is on one page or
     * the page cannot be told (REQ-4).
     *
     * A survey set to show one section per page renders only the current
     * page's fields, so a "when" over a field answered on an earlier page
     * read a blank in the browser. REDCap sets $_GET['__page__'] to the page
     * it draws before redcap_survey_page_top runs. The pages follow the
     * dictionary the way REDCap's getPageFields() splits them: each field with
     * a section header starts a new page, except the first field, and the
     * record id field is on none.
     */
    private function surveyPageFields($pid, $instrument)
    {
        $page = (isset($_GET['__page__']) && is_scalar($_GET['__page__'])) ? (string) $_GET['__page__'] : '';
        if (!$instrument || !preg_match('/^[1-9][0-9]{0,4}$/D', $page)) return null;
        if (!$this->surveyBySection($pid, $instrument)) return null;
        $dd = $this->dataDictionary($pid);
        if (!$dd) return null;
        $pk = (string) array_key_first($dd);
        $pages = [];
        $n = 1;
        $seen = 0;
        foreach ($dd as $name => $meta) {
            if (!isset($meta['form_name']) || $meta['form_name'] !== $instrument || (string) $name === $pk) continue;
            if ($seen > 0 && isset($meta['section_header']) && (string) $meta['section_header'] !== '') $n++;
            $pages[$n][(string) $name] = true;
            $seen++;
        }
        return (count($pages) > 1 && isset($pages[(int) $page])) ? $pages[(int) $page] : null;
    }

    /** Whether $instrument is a survey set to show one section per page. */
    private function surveyBySection($pid, $instrument)
    {
        $proj = $this->projectObject($pid);
        if ($proj === null || !isset($proj->forms[$instrument]['survey_id'])) return false;
        $sid = $proj->forms[$instrument]['survey_id'];
        return isset($proj->surveys[$sid]) && is_array($proj->surveys[$sid])
            && !empty($proj->surveys[$sid]['question_by_section']);
    }

    private function fieldsOnInstrument($pid, $instrument)
    {
        if (!$instrument) return null;
        $dd = $this->dataDictionary($pid);
        if (!$dd) return null;
        $set = [];
        foreach ($dd as $name => $meta) {
            if (isset($meta['form_name']) && $meta['form_name'] === $instrument) $set[$name] = true;
        }
        return $set ?: null;
    }

    /**
     * Keep only the rules — and, within each, the fields — that live on the
     * instrument being rendered, so the browser installs a validator (and its
     * MutationObserver) ONLY for fields actually on the page (PER-003, the 1.5.1
     * perf issue). Config-error rules are kept unchanged so their notice still
     * surfaces. An unknown instrument or dictionary means "do not filter" — the
     * conservative choice for contexts where the form is not identifiable, matching
     * fieldsOnInstrument()'s null contract elsewhere in the audit.
     */
    private function rulesOnInstrument(array $rules, $pid, $instrument, $survey = false)
    {
        $onForm = $this->fieldsOnInstrument($pid, $instrument);
        if ($onForm === null) {
            // A survey page carries no configuration error it cannot place:
            // the notice is muted there, and the rule names other forms (LIVE-2).
            if (!$survey) return $rules;
            return array_values(array_filter($rules, function ($r) { return empty($r['configError']); }));
        }
        // A survey shown one section per page renders only this page's fields.
        $onPage = $survey ? $this->surveyPageFields($pid, $instrument) : null;
        $out = [];
        foreach ($rules as $r) {
            if (!empty($r['configError'])) {
                // Staff see every configuration error, on any form. A survey
                // page keeps only those of its own fields, as "not available"
                // under the field (ModeRegistry::clientShape): it showed none of
                // the others, and its source carried them all (LIVE-2, CHO-6).
                if ($survey) {
                    $own = [];
                    foreach ((isset($r['fields']) && is_array($r['fields'])) ? $r['fields'] : [] as $f) {
                        if (isset($onForm[$f]) && ($onPage === null || isset($onPage[$f]))) $own[] = $f;
                    }
                    if (!$own) continue;
                    $r['fields'] = $own;
                }
                $out[] = $r;
                continue;
            }
            if (empty($r['fields']) || !is_array($r['fields'])) continue;
            $onFields = [];
            foreach ($r['fields'] as $f) {
                if (isset($onForm[$f])) $onFields[] = $f;
            }
            if (!$onFields) continue;           // no field of this rule is on this form
            $r['fields'] = $onFields;           // inject only the on-form fields
            $out[] = $r;
        }
        return $out;
    }

    /**
     * Refusal wording for the @UVUNIQUE survey opt-in on an Identifier field.
     * Shared by both configuration channels so the message cannot drift.
     */
    const SURVEY_ON_IDENTIFIER =
        'the survey uniqueness check ("surveys") cannot be enabled on a field REDCap marks as an '
        . 'Identifier: a survey answer of "already used" would let anyone holding the survey link test '
        . 'whether a specific person is in this study. Drop "surveys" (staff still get the live check, '
        . 'and survey submissions are still covered by the post-save audit and the Validation scan), or '
        . 'un-flag the field as an Identifier if it truly is not one.';

    /** Whether $field is flagged as an Identifier ($ids may be null = unknown). */
    private static function isIdentifier($ids, $field)
    {
        return is_array($ids) && isset($ids[$field]);
    }

    /**
     * The first of $fields that $ids flags as an Identifier, or null. Used to
     * refuse the @UVUNIQUE survey opt-in when EITHER the primary field OR any
     * composite "with" field is an Identifier (H-01): a survey "already used"
     * answer whose key includes an identifying value is the same unauthenticated
     * existence-oracle risk the single-field refusal closes.
     */
    private static function firstIdentifier($ids, array $fields)
    {
        foreach ($fields as $f) {
            if (is_string($f) && $f !== '' && self::isIdentifier($ids, $f)) return $f;
        }
        return null;
    }

    /**
     * Dictionary checks for a unique rule's composite "with" fields: each must
     * exist, hold one scalar value, and not be the unique field itself.
     * Returns a list of error strings, [] when sound. Shared by the annotation
     * and dialog channels ($selfField is null for a dialog rule covering
     * several fields — the self-reference check then runs per covered field
     * in the caller).
     */
    private static function checkUniqueWith(array $with, $selfField, $types)
    {
        $errors = [];
        $scalar = ['text', 'notes', 'dropdown', 'radio', 'yesno', 'truefalse', 'sql', 'slider', 'calc'];
        foreach ($with as $w) {
            if (!is_string($w) || $w === '') continue; // shape errors already caught by checkUnique
            if ($selfField !== null && $w === $selfField) {
                $errors[] = '"with" must not name the unique field itself ("' . $w . '").';
                continue;
            }
            if (is_array($types)) {
                if (!isset($types[$w])) {
                    $errors[] = '"with" field "' . $w . '" is not in this project — check the spelling.';
                } elseif (!in_array($types[$w], $scalar, true)) {
                    $errors[] = '"with" field "' . $w . '" is a ' . $types[$w]
                        . ' field — composite keys need one scalar value per field.';
                }
            }
        }
        return $errors;
    }

    /**
     * Dictionary checks for a unique rule's "also" fields: each must exist,
     * hold one value, not be the unique field itself, and store its value the
     * way $selfField does - the same kind of date or none, and text, or
     * numbers with the same decimal mark. A value is looked for as it is
     * stored (Logic::lookupKey), and a number never equals text there, so a
     * pair that stores values differently could never match, and "007" in a
     * number field would match "7" in one direction and not in the other.
     * Returns a list of error strings, [] when sound. $selfField is null for a
     * dialog rule covering several fields; the caller then checks each.
     * $dd is the data dictionary; without it only names and types are checked.
     */
    private static function checkUniqueAlso(array $also, $selfField, $types, $dd)
    {
        $errors = [];
        foreach ($also as $a) {
            if (!is_string($a) || $a === '') continue; // shape errors already caught by checkUnique
            if ($selfField !== null && $a === $selfField) {
                $errors[] = '"also" must not name the unique field itself ("' . $a . '") — that field is always searched.';
                continue;
            }
            if (is_array($types)) {
                if (!isset($types[$a])) {
                    $errors[] = '"also" field "' . $a . '" is not in this project — check the spelling.';
                    continue;
                }
                if (!in_array($types[$a], self::EXISTS_SCALAR_TYPES, true)) {
                    $errors[] = '"also" field "' . $a . '" is a ' . $types[$a]
                        . ' field — the value is looked for in fields that hold one value.';
                    continue;
                }
            }
            if ($selfField === null || !is_array($dd) || !isset($dd[$selfField], $dd[$a])) continue;
            $kindSelf = self::storedKindOf(self::validationOf($dd[$selfField]));
            $kindAlso = self::storedKindOf(self::validationOf($dd[$a]));
            if ($kindSelf !== $kindAlso) {
                $errors[] = '"' . $selfField . '" holds ' . $kindSelf . ' and "also" field "' . $a . '" holds ' . $kindAlso
                    . ' — values are compared as REDCap stores them, so both must be the same kind.';
                continue;
            }
            $markSelf = self::numberMarkOf($dd[$selfField]);
            $markAlso = self::numberMarkOf($dd[$a]);
            if ($markSelf !== $markAlso) {
                $errors[] = '"' . $selfField . '" holds ' . (self::numberMarkWords($markSelf) ?? 'text')
                    . ' and "also" field "' . $a . '" holds ' . (self::numberMarkWords($markAlso) ?? 'text')
                    . ' — both must hold text, or both numbers with the same decimal mark '
                    . '(validate both fields the same way).';
            }
        }
        return $errors;
    }

    /** Fields claimed by more than one live (non-config-error) rule. */
    private static function duplicateFields(array $rules)
    {
        // Count per (field, MODE): a check rule and a constraint rule may share
        // a field (they compose — both audit it); only two rules of the SAME
        // mode on one field are a genuine duplicate (post-Branching this should
        // not occur, but the guard stays as a safety net).
        $counts = [];
        foreach ($rules as $r) {
            if (!empty($r['configError'])) continue;
            if (empty($r['fields']) || !is_array($r['fields'])) continue;
            $mode = ModeRegistry::modeOfType(isset($r['type']) ? $r['type'] : '');
            $seen = [];
            foreach ($r['fields'] as $f) {
                if (isset($seen[$f])) continue; // a field twice in ONE rule is not a cross-rule dupe
                $seen[$f] = true;
                $key = $f . "\x1F" . $mode;
                $counts[$key] = isset($counts[$key]) ? $counts[$key] + 1 : 1;
            }
        }
        $dupes = [];
        foreach ($counts as $key => $c) {
            if ($c > 1) $dupes[] = substr($key, 0, strrpos($key, "\x1F"));
        }
        return array_values(array_unique($dupes));
    }

    // -- project scan (retrospective validation report) ----------------------

    /**
     * Install the durable scan's tables when an administrator asks for them.
     *
     * WHY IT IS TIED TO SAVING THE SETTING, and not to anything else. The
     * schema is ten tables; creating them on every installation that merely has
     * this module enabled would put ten tables into databases whose owners
     * never asked for the feature. Creating them lazily when somebody OPENS a
     * page would be worse - ScanService says so in as many words: a migration
     * that runs because someone opened a page is a migration nobody chose.
     *
     * Ticking the installation-wide switch and pressing Save IS the choice, so
     * that is where it happens. The statements are all CREATE TABLE IF NOT
     * EXISTS, so saving the settings again is a no-op, and a migration that
     * fails leaves the feature disabled with a health check that says which
     * tables are missing.
     *
     * FOUND BY THE PILOT, NOT BY THE SUITE. Every test built its tables
     * directly, so the whole suite was green over a module that had no way to
     * create them at all - the same shape as v1.4.0's production-inert
     * @UVUNIQUE, and the reason the plan asks for a real-server pilot before
     * this is enabled anywhere.
     *
     * @param ?int $project_id null when the SYSTEM configuration was saved
     */
    public function redcap_module_save_configuration($project_id = null)
    {
        if ($project_id !== null) {
            // Project settings install nothing, but this is the one moment the
            // module is certain the rule list has just been edited, so it is
            // where a rule row is given the identity it will keep.
            $this->mintRuleIds($project_id);
            return;
        }
        // BESIDE the scan's schema, not inside it. uv_rate_bucket backs the
        // survey throttle, which runs whether or not the scan was ever asked
        // for; installScanSchema() below returns early unless the scan flag is
        // set, and gating the throttle's storage on the scan's flag is what
        // left the module's only unauthenticated endpoint unthrottled by
        // default. See Schema::ensureRateBucket().
        Scan\Schema::ensureRateBucket($this);
        $this->installScanSchema();
    }

    /**
     * Give every settings rule row a persistent id, once, and never again.
     *
     * WHY A ROW NEEDS AN ID. ScanPlanner::identify() prefers a stored id and
     * always has - its comment says "a persistent id stored on the row is the
     * right answer, because it survives editing the rule" - but nothing ever
     * minted one, so every project ran the content-hash fallback. That fallback
     * cannot survive an edit, and it is worse than it looks: revision()
     * deliberately excludes the field list, so two rules of the same type and
     * options on DIFFERENT fields hash the same and are separated only by their
     * position. Dragging one row above the other swapped their identities and
     * re-attributed every finding already stored against them.
     *
     * NEVER REGENERATED. An id that changes is worse than no id, because a
     * changed id silently orphans findings instead of merely failing to match
     * them. A row whose stored value is already a valid id is left exactly as
     * it is; only blanks and malformed values are filled.
     *
     * NEVER FATAL. This runs inside a framework hook during a settings save. A
     * failure here degrades to the old content-hash naming, which is what
     * shipped for every release before this one; failing the administrator's
     * save over it would be a much worse outcome than the fallback.
     */
    private function mintRuleIds($pid)
    {
        try {
            if (!is_callable([$this, 'setProjectSetting'])) return;
            $subs = $this->getSubSettings('rules', $pid);
            if (!is_array($subs) || !$subs) return;

            $col = [];
            $minted = 0;
            foreach ($subs as $s) {
                $cur = (is_array($s) && isset($s['rule-uid']) && is_string($s['rule-uid']))
                    ? $s['rule-uid'] : '';
                if (preg_match('/^[0-9a-f]{16}\z/', $cur)) { $col[] = $cur; continue; }
                $col[] = bin2hex(random_bytes(8));
                $minted++;
            }
            if ($minted === 0) return;

            // Written as the whole parallel column, because that is how the
            // framework stores a repeatable sub-setting: a partial write would
            // shift every row's id by one, which is the exact failure mode the
            // id exists to prevent.
            $this->setProjectSetting('rule-uid', $col, $pid);
            $this->log('scan-rule-ids-minted', ['rows' => count($col), 'minted' => $minted]);
        } catch (\Throwable $e) {
            // Class only: the message comes from the framework's error path and
            // can carry statement text.
            try { $this->log('scan-rule-ids-mint-failed', ['error' => get_class($e)]); }
            catch (\Throwable $ignored) { }
        }
    }

    /**
     * The same install, on the other administrator action that can request it.
     *
     * A module enabled while its switch is already on - a reinstall, or a
     * version upgrade - never passes through save_configuration, so the schema
     * would stay missing until somebody happened to re-save a setting they had
     * not changed.
     */
    public function redcap_module_system_enable($version = null)
    {
        // See the note in redcap_module_save_configuration(): the throttle's
        // table is not the scan's, and is installed either way.
        Scan\Schema::ensureRateBucket($this);
        $this->installScanSchema();
    }

    /**
     * A new version of the module was installed. A schema change it carries
     * (version 3 adds finding.as_of) is applied now, not at the next settings
     * save: until then health() reports the old version and the durable scan
     * stays disabled.
     */
    public function redcap_module_system_change_version($version = null, $old_version = null)
    {
        Scan\Schema::ensureRateBucket($this);
        $this->installScanSchema();
    }

    /**
     * Migrate, but only when the installation has asked for the feature.
     *
     * Returns nothing and throws nothing: this runs inside a framework hook
     * during a settings save, and an exception here would fail the SAVE - so an
     * administrator ticking a box would be told their settings could not be
     * stored, which is both wrong and unactionable. The health check on the
     * scan page is where a failure is reported, and it names the tables.
     */
    private function installScanSchema()
    {
        try {
            $on = $this->getSystemSetting(Scan\ScanService::SYS_FLAG);
            if (!($on === true || $on === 1 || $on === '1' || $on === 'true')) return;
            $r = Scan\Schema::migrate($this);
            // Recorded either way. A migration is the one thing here that
            // changes the database, and "it was attempted and this happened" is
            // what an administrator needs when the page later says the tables
            // are not ready.
            $this->log('scan-schema-migrate', [
                'ok' => empty($r['ok']) ? 0 : 1,
                'from' => isset($r['from']) ? (string) $r['from'] : '?',
                'to' => isset($r['to']) ? (string) $r['to'] : '?',
                'applied' => isset($r['applied']) ? (int) $r['applied'] : 0,
                'why' => isset($r['why']) ? (string) $r['why'] : '',
            ]);
            if (empty($r['ok'])) return;

            // AND PROVISION THE WORKER SLOTS, because creating the table does
            // not create the rows.
            //
            // Leasing a slot is an UPDATE against precreated rows - deliberately,
            // so that two workers racing are serialised by InnoDB and exactly one
            // sees a row changed. The consequence is that the COUNT OF ROWS IS
            // THE LIMIT, and a table with no rows is a limit of zero: every
            // worker is refused, forever, and told the server is busy.
            //
            // The first live pilot hit exactly that. The scan planned, froze a
            // 39-record manifest, and then could not do a single batch, because
            // provision() had no caller anywhere outside its own tests - the
            // same shape as the migration it now sits beside.
            //
            // Raising the limit adds rows here on the next save. LOWERING it
            // takes effect at once, because acquire() takes no slot numbered
            // above the limit (review R4-4), and the free rows above it are
            // removed here; a row still leased is removed by the reap cron once
            // its holder lets go (W1-N1). A leased row is never deleted under
            // its worker.
            $limitKey = 'scan-system-max-concurrent-projects';
            // Through ScanPolicy so an unset or malformed setting lands on the
            // documented default rather than on zero, which would provision
            // nothing and reproduce the very fault this fixes.
            $policy = Scan\ScanPolicy::resolve(
                [$limitKey => $this->getSystemSetting($limitKey)], []);
            $slots = new Scan\WorkerSlots(new Scan\ModuleDb($this));
            $added = $slots->provision($policy['maxProjects']);
            $removed = $slots->shrinkTo($policy['maxProjects']);
            $census = $slots->census();
            $this->log('scan-slots-provision', [
                'limit' => (int) $policy['maxProjects'],
                'added' => (int) $added,
                'removed' => (int) $removed,
                'total' => (int) $census['total'],
            ]);
        } catch (\Throwable $e) {
            // Swallowed on purpose - see the docblock - but NEVER SILENTLY.
            //
            // THE LEADING BACKSLASH IS THE WHOLE POINT. This file declares
            // `namespace INSPIRE\UniversalValidator`, so an unqualified
            // `catch (Throwable)` names INSPIRE\UniversalValidator\Throwable -
            // a class that does not exist. PHP does not warn about that; it
            // simply never matches. This catch was inert from the day it was
            // written, so anything thrown after the migration returned - the
            // log, the policy read, the slot provisioning - escaped it and took
            // the administrator's settings save down with it, which is the
            // exact outcome the docblock above promises cannot happen.
            //
            // And a catch that finally starts catching must not trade a loud
            // failure for an invisible one: without this line a slot-
            // provisioning failure would vanish, and the operator would meet it
            // later as the 1.9.5 pilot symptom - "the server is busy" over an
            // empty slot pool - with nothing anywhere to explain it. The CLASS
            // only: the message is written by the framework's error path and
            // can carry statement text.
            try { $this->log('scan-schema-install-failed', ['error' => get_class($e)]); }
            catch (\Throwable $ignored) { }
        }
    }

    // -- scheduled maintenance ---------------------------------------------

    /**
     * Release the slot held by a run that stopped making progress.
     *
     * php/Scan/ScanRetention.php has always held this logic and NOTHING EVER
     * CALLED IT: config.json declared no crons and this class declared no cron
     * method, so the only code paths into ScanRetention were its own tests
     * (H-2). The consequence is not cosmetic. A project has one scan slot; a run
     * whose browser closed or whose worker died keeps holding it, and every
     * later scan on that project is told the server is busy - forever, because
     * the reaper that exists to break exactly that deadlock never ran.
     *
     * A cron in this framework runs ONCE GLOBALLY, not per project, and
     * expireAbandoned() is a single project-independent UPDATE, so there is
     * nothing to iterate. Fifteen minutes is chosen against the lease, not
     * against the retention windows: a slot is the scarce thing.
     *
     * NEVER THROWS. A cron method that throws is reported to administrators on
     * every tick and has no user who can act on it, so the same posture as
     * installScanSchema applies - do nothing, loudly, in the module log.
     */
    public function uvScanReapCron($cronInfo = [])
    {
        return $this->scanMaintenance('reap', function (Scan\ScanRetention $ret, array $policy) {
            $n = $ret->expireAbandoned($policy['staleHours']);
            return ['runs_expired' => (int) $n, 'stale_hours' => (int) $policy['staleHours']];
        });
    }

    /**
     * Clear stored value previews whose retention window has passed.
     *
     * The column, not the row: the finding stays true and stops being a copy of
     * the project. Daily, because a value's window is measured in days.
     *
     * PURGING WHOLE RUNS IS DELIBERATELY NOT WIRED HERE, and that is not an
     * oversight. ScanRetention::purgeRuns() used to delete findings with
     * `DELETE FROM uv_finding WHERE generation_id = ?` while every run in every
     * project was written with generation_id = 1, so one project's purge deleted
     * every project's findings. 2.0.0 scopes those deletes by project and
     * generation, but they are still unpaged: measured, an unpaged DELETE of
     * 500,000 findings is one 160-second statement holding row locks throughout.
     * Run retention stays manual until the remediation plan's wave 8 pages it.
     * The value sweep below is the same kind of unpaged statement (an UPDATE),
     * and is also on wave 8's list.
     */
    public function uvScanExpireValuesCron($cronInfo = [])
    {
        return $this->scanMaintenance('expire-values', function (Scan\ScanRetention $ret, array $policy) {
            $n = $ret->expireValues();
            return ['values_cleared' => (int) $n];
        });
    }

    /**
     * The shared body of both crons: refuse cheaply unless the feature is on and
     * its tables are actually there, then do the work and log what happened.
     *
     * Checked in that order for the same reason ScanService::available() uses
     * it: an installation that never turned the scan on should not have its
     * schema inspected on a timer.
     *
     * @return string the one-line summary REDCap shows beside the cron
     */
    private function scanMaintenance($what, callable $work)
    {
        try {
            $on = $this->getSystemSetting(Scan\ScanService::SYS_FLAG);
            if (!($on === true || $on === 1 || $on === '1' || $on === 'true')) {
                return 'the durable validation scan is not enabled here; nothing to do';
            }
            if (!is_callable([$this, 'query'])) {
                return 'this framework build exposes no query(); nothing to do';
            }
            $health = Scan\Schema::health($this);
            if (empty($health['ok'])) {
                // Reported, never repaired on a timer. A migration nobody chose
                // is the thing installScanSchema is careful not to do either.
                $this->log('scan-cron-skipped', ['what' => (string) $what,
                    'why' => isset($health['why']) ? (string) $health['why'] : 'schema not ready']);
                return 'the scan tables are not ready; nothing was done';
            }
            $sys = [];
            foreach (['scan-system-stale-run-hours', 'scan-system-max-concurrent-projects'] as $k) {
                $sys[$k] = $this->getSystemSetting($k);
            }
            $policy = Scan\ScanPolicy::resolve($sys, []);
            $ret = new Scan\ScanRetention(new Scan\ModuleDb($this));
            $out = $work($ret, $policy);
            $this->log('scan-cron', array_merge(['what' => (string) $what], $out));
            $bits = [];
            foreach ($out as $k => $v) $bits[] = $k . '=' . $v;
            return $what . ': ' . implode(', ', $bits);
        } catch (\Throwable $e) {
            // Same posture as installScanSchema: a throw out of a cron is mailed
            // to administrators every tick and nobody can act on it.
            try {
                $this->log('scan-cron-failed', ['what' => (string) $what,
                    'error' => get_class($e) . ': ' . $e->getMessage()]);
            } catch (\Throwable $ignored) {
            }
            return $what . ': failed, logged to the module log';
        }
    }

    /**
     * Show the "Validation scan" project link only to users who can already
     * see the whole design (design rights). The page re-checks; this only
     * governs the sidebar link.
     */
    public function redcap_module_link_check_display($project_id, $link)
    {
        try {
            $user = $this->getUser();
            if ($user && is_callable([$user, 'hasDesignRights']) && $user->hasDesignRights()) return $link;
        } catch (\Throwable $e) {
        }
        return null;
    }

    /** Longest value the report will carry. A report is not a second copy of the project. */
    const REPORT_VALUE_MAX = 120;
    /** What reportValue() shows in place of an identifier field's value. */
    const REPORT_REDACTED = '[identifier withheld]';

    // The Validation scan lives in php/ScanRuns.php (QUA-2).

    /** Per-session window for a signed-in caller of the live lookups (unique-check, exists-check). */
    const THROTTLE_SIGNED_IN = 60;

    /** Per-session window. Cheap, and keyed on something the caller controls. */
    const THROTTLE_SESSION = 30;

    /**
     * Per-project windows, which are keyed on something the caller does not.
     *
     * The sessionless cap is the older number and keeps its meaning: a
     * cookieless enumerator gets 600 a minute for the whole project, so a
     * six-digit space takes 1,000,000 / 600 minutes, about 28 hours. The
     * sessioned cap has to sit above what a busy public survey really spends -
     * tier 1 allows each session 30 a minute, so 600 would be twenty people
     * typing at once - while still being a bound rather than none. Only a
     * session with history spends it (SESSION_HISTORY).
     */
    const THROTTLE_PROJECT_ANON = 600;
    const THROTTLE_PROJECT_SESSIONED = 6000;
    /**
     * Seconds a session must have been checking before its checks count in the
     * sessioned bucket (UNQ-8). A fresh session costs nothing to make: a
     * caller who drops the cookie on every request got the 6,000 cap and
     * never met tier 1. Its first minute is charged to the 600 bucket.
     */
    const SESSION_HISTORY = 60;
    /**
     * Whole-field reads an unauthenticated @UVEXISTS caller may cause per
     * project per minute (surveyFullReadAllowed). Over it, a lookup that needs
     * one answers unknown, which a survey page shows as nothing.
     */
    const THROTTLE_SURVEY_FULL_READS = 60;
    /**
     * Rate-bucket tiers, interleaved in one bucket column: minute * RATE_TIERS
     * + tier. 0 = survey callers with no session, 1 = with a session, 2 =
     * whole-field reads for survey lookups; lookups in another project, counted
     * under that project's id: 3 = staff, 4 = survey callers, 5 = post-save
     * audits and scans (crossRateLimited). Raising the count moves every new
     * bucket number above every old one, so an upgrade never mixes the two.
     */
    const RATE_TIERS = 6;
    /**
     * Lookups in one project that survey callers of every project together may
     * make per minute (tier 4). Not a setting, for the reason
     * surveyRateLimited gives: a mistyped limit would switch off a control.
     */
    const THROTTLE_CROSS_SURVEY = 120;

    // The live lookups and their throttles live in php/LiveLookups.php (QUA-2).

    /**
     * A REDCap filterLogic that narrows a collision lookup to candidate matches,
     * so the live endpoint does not export the whole project on every call (F4).
     * Only values made of a safe character set (letters, digits, and the ID/date
     * punctuation . _ : / - and space) are inlined — anything that could break the
     * logic literal (a quote, a bracket, an operator) returns null and the caller
     * falls back to the full scan. Blank composite components are left
     * unconstrained (the exact PHP comparison still requires them blank). The
     * primary field is never blank (guarded in findCollision), so at least it is
     * always constrained. Returns null when nothing can be safely constrained.
     *
     * A clause compares as the rule does where REDCap can: a number in a
     * field that holds them ($marks 'point') unquoted, so REDCap compares it
     * by value; letters under a rule that ignores case ($fold) through
     * lower(). It can still miss a value Logic::lookupKey calls equal (a
     * saved value with spaces around it, a comma-decimal number written
     * another way), so a narrowed read settles only a hit: findCollision
     * confirms every miss with a full read. Whatever else a clause lets
     * through, the PHP comparison drops.
     *
     * $also are the other fields the primary value is looked for in
     * (@UVUNIQUE "also"): the value's clause becomes an OR over the primary
     * field and each of them, ANDed with the composite clauses as before.
     */
    private static function collisionFilterLogic(array $need, array $target, $fold = false, array $marks = [], array $also = [])
    {
        reset($need);
        $primary = current($need);
        $clauses = [];
        foreach ($need as $f) {
            $tv = isset($target[$f]) ? $target[$f] : '';
            if ($tv === '') continue;                                       // don't constrain a blank component
            if (!preg_match('/^[A-Za-z0-9 ._:\/-]+$/', $tv)) return null;    // unsafe to inline -> full scan
            $slots = $f === $primary ? array_merge([$f], $also) : [$f];
            $any = [];
            foreach ($slots as $sf) $any[] = self::collisionClause($sf, $tv, $fold, isset($marks[$sf]) ? $marks[$sf] : null);
            $clauses[] = count($any) > 1 ? '(' . implode(' or ', $any) . ')' : $any[0];
        }
        return $clauses ? implode(' and ', $clauses) : null;
    }

    /** One collisionFilterLogic clause: field $f holds $tv, compared as the rule compares. */
    private static function collisionClause($f, $tv, $fold, $mark)
    {
        $n = $mark === 'point' ? Logic::numberKey($tv, 'point') : null;
        if ($n !== null) return '[' . $f . '] = ' . $n;
        if ($fold && preg_match('/[A-Za-z]/', $tv)) return 'lower([' . $f . "]) = '" . Logic::foldAscii($tv) . "'";
        return '[' . $f . "] = '" . $tv . "'";
    }

    /**
     * Scan every OTHER record for the candidate value(s). Values compare by
     * Logic::lookupKey, as @UVEXISTS compares them: trimmed, letter case
     * ignored unless $fold is false (the rule's caseSensitive), and numbers by
     * value in a field that holds them (numberMarkOf), on stored values
     * (dropdown/radio codes, canonical Y-M-D dates) on both sides. A blank
     * primary value never collides; composite "with" components match
     * blank-to-blank. Each
     * other record is compared as its MERGED contexts (base-event row + each
     * repeat instance), so a composite key that spans an event-level field and a
     * repeating-instrument field is matched exactly the way the Validation scan
     * matches it — the per-save audit and the scan can no longer disagree (H-03).
     * Scopes: project (default), event (same event only), dag (records in the
     * same Data Access Group). Under dag the group of the record being checked
     * is read before anything else (recordDagOf); a record not saved yet is
     * created in the acting user's group, or in none. Records in no group are
     * one group of their own (UNQ-1): they are compared with each other and
     * with nothing else, as README says and as @UVEXISTS does, in the live
     * check, the audit and the scan alike.
     * Returns null (no other record holds the value), ['record' => id,
     * 'dag' => nameOrNull, 'field' => the field it was found in], or false
     * when the lookup could not be settled: a read failed, the group could
     * not be told, or $mayFullRead refused the read.
     *
     * $also (@UVUNIQUE "also") are other fields the value is looked for in:
     * a value saved in the primary field OR in any of them, in another record,
     * collides. The composite "with" fields still have to match in the same
     * entry as the field the value was found in, and the record being checked
     * is still left out entirely: its own fields are compared by @UVASSERT.
     *
     * $mayFullRead (a survey's read budget) is asked before every read of the
     * whole field: one that confirms a narrowed miss, and one made because the
     * value could not be narrowed at all. A narrowed miss is never final: the
     * filter cannot see a saved value with spaces around it, which the
     * comparison here trims. A saved Missing Data Code in the tagged field,
     * or in an "also" field, is no duplicate (the scan skips them too).
     */
    private function findCollision($pid, $field, array $with, $scope, array $values, $excludeRecord, $event_id, $groupId = null, $narrow = false, $fold = true, ?callable $mayFullRead = null, array $also = [])
    {
        $need = array_merge([$field], $with);
        $also = array_values(array_diff(array_unique($also), $need));
        $marks = $this->lookupMarks($pid, array_merge($need, $also));
        $codes = $this->missingDataCodes($pid);
        $target = [];
        $keys = [];
        foreach ($need as $f) {
            $target[$f] = isset($values[$f]) ? Logic::lookupTrim($values[$f]) : '';
            $keys[$f] = $target[$f] === '' ? '' : Logic::lookupKey($target[$f], $fold, $marks[$f]);
        }
        if ($target[$field] === '') return null;

        // The group to stay in under "dag": '' is the group of records in no
        // group, and a group whose name cannot be read is not that (UNQ-1).
        $currentDag = null;
        if ($scope === 'dag') {
            $rd = $this->recordDagOf($pid, $excludeRecord);
            if ($rd === false) return false;
            if ($rd['found']) {
                $currentDag = (string) $rd['dag'];
            } elseif ($groupId !== null && $groupId !== '') {
                $currentDag = ScanPageView::dagNameOf($groupId);
                if ($currentDag === null) return false;
            } else {
                $currentDag = '';
            }
        }

        $params = [
            'project_id'    => $pid,
            'return_format' => 'array',
            'fields'        => array_merge($need, $also),
            'exportDataAccessGroups' => true,
        ];
        if ($scope === 'event' && $event_id) $params['events'] = [$event_id];

        // The records that hold the value, from the data table (UNQ-7,
        // PRF-2): getData then builds those records only, and a miss needs no
        // export at all. [false] when the table cannot be read, and the
        // export below answers instead.
        $fromTable = function () use ($pid, $field, $also, $target, $keys, $marks, $fold, $currentDag, $excludeRecord, $codes, $params, $scope, $event_id) {
            $cand = $this->collisionCandidates($pid, $target[$field], array_merge([$field], $also), $marks, $fold,
                                               $excludeRecord, ($scope === 'event' && $event_id) ? $event_id : null);
            if ($cand === null) return [false];
            if (!$cand) return [true, null];
            $data = \REDCap::getData($params + ['records' => $cand]);
            if (!is_array($data)) return [true, false];
            return [true, self::collisionIn($data, $target, $keys, $marks, $fold, $currentDag, $excludeRecord, $codes, $also)];
        };
        // Signed-in callers and the save audit start there. A survey keeps
        // its order: the narrowed read below settles a hit without spending
        // the read budget, and the budget pays for the table read of a miss.
        if ($mayFullRead === null) {
            $t = $fromTable();
            if ($t[0]) return $t[1];
        }

        // Live-endpoint amplification guard (F4): the no-auth path would otherwise
        // export the WHOLE project on every call. Narrow the read to candidate
        // matches with a filterLogic, so REDCap returns only the few records that
        // could collide. Best-effort — a value that cannot be safely inlined, or a
        // build that does not honor filterLogic, falls back to the full read. The
        // exact comparison below stays authoritative and the post-save audit (which
        // never narrows) is the correctness backstop, so this only ever saves work:
        // it can never turn a real duplicate into a missed save.
        // F4-DAG-01: dag scope did not narrow, because the group of the current
        // record was read from its node in the result, which a value-filtered
        // read leaves out. The group is now read on its own above, so dag scope
        // narrows like the others.
        //
        // Only a hit settles the lookup here; a miss falls through to the full
        // read (collisionFilterLogic says why).
        if ($narrow) {
            $fl = self::collisionFilterLogic($need, $target, $fold, $marks, $also);
            if ($fl !== null) {
                try {
                    $n = \REDCap::getData($params + ['filterLogic' => $fl]);
                    if (is_array($n)) {
                        $hit = self::collisionIn($n, $target, $keys, $marks, $fold, $currentDag, $excludeRecord, $codes, $also);
                        if ($hit !== null) return $hit;
                    }
                } catch (\Throwable $e) {
                    // filterLogic unsupported/malformed here — fall back below.
                }
            }
        }
        if ($mayFullRead !== null) {
            if (!call_user_func($mayFullRead)) return false;
            $t = $fromTable();
            if ($t[0]) return $t[1];
        }
        $shared = $this->sharedCollisionRead($params);
        if ($shared !== null) {
            if ($shared === false) return false;
            return $this->collisionInShared($shared, $target, $keys, $marks, $fold, $currentDag, $excludeRecord, $codes, $also);
        }
        $data = \REDCap::getData($params);
        if (!is_array($data)) return false;
        return self::collisionIn($data, $target, $keys, $marks, $fold, $currentDag, $excludeRecord, $codes, $also);
    }

    /**
     * The records other than $excludeRecord with a row of $fields whose value
     * has the lookup key of $value under that field's number mark, read from
     * the data table (Scan\FieldValues); or null when it cannot be read. A
     * superset of the records collisionIn can match: a context takes its
     * values from these rows only.
     */
    private function collisionCandidates($pid, $value, array $fields, array $marks, $fold, $excludeRecord, $eventId)
    {
        $keyOf = [];
        foreach ($fields as $f) $keyOf[$f] = Logic::lookupKey($value, $fold, isset($marks[$f]) ? $marks[$f] : null);
        $recs = $this->fieldValues()->recordsWhere($pid, $fields, $eventId,
            function ($f, $v) use ($keyOf, $value, $fold, $marks) {
                if (!isset($keyOf[$f])) return false;
                if ($v === $value) return true;
                $k = $keyOf[$f];
                // A text key is the trimmed, folded value: a shorter stored
                // value cannot reach it.
                if ($k[0] === '=' && strlen($v) < strlen($k) - 1) return false;
                return Logic::lookupKey($v, $fold, isset($marks[$f]) ? $marks[$f] : null) === $k;
            });
        if ($recs === null || $excludeRecord === null || $excludeRecord === '') return $recs;
        $skip = (string) $excludeRecord;
        return array_values(array_filter($recs, function ($r) use ($skip) { return $r !== $skip; }));
    }

    /**
     * The records of project $pid with a row of $field whose value an
     * @UVEXISTS lookup of $value matches (existsRowMatch: trimmed, letter case
     * ignored when $fold, numbers by value under $mark, a Missing Data Code
     * never), in event $event (null: any), less $skip; read from the data
     * table (Scan\FieldValues), which REDCap does not confine to the user's
     * Data Access Group. Null when the table cannot be read.
     */
    private function existsCandidates($pid, $field, $value, $fold, $mark, array $codes, $event, $skip = null)
    {
        $key = Logic::lookupKey($value, $fold, $mark);
        $recs = $this->fieldValues()->recordsWhere($pid, [$field], $event,
            function ($f, $v) use ($value, $key, $fold, $mark, $codes) {
                if ($v === $value) return !($codes && self::isMissingCode($v, $codes));
                // A text key is the trimmed, folded value: a shorter stored
                // value cannot reach it.
                if ($key[0] === '=' && strlen($v) < strlen($key) - 1) return false;
                $t = Logic::lookupTrim($v);
                if ($codes && self::isMissingCode($t, $codes)) return false;
                return Logic::lookupKey($t, $fold, $mark) === $key;
            });
        if ($recs === null || $skip === null || $skip === '') return $recs;
        $skip = (string) $skip;
        return array_values(array_filter($recs, function ($r) use ($skip) { return $r !== $skip; }));
    }

    /**
     * A field lookup (findExisting) answered from the records the data table
     * shows holding the value (existsCandidates), or null when the table
     * cannot be read and the caller reads as before (EXS-4, EXS-1).
     *
     * Only those records are exported, with the fields the rule tests, and
     * judged as the full read judged them, so the answer does not change.
     * No record holding the value is "not found": the table is not confined
     * to the caller's Data Access Group, so the miss needs no evidence of what
     * the read could see (existsMiss). A record the table names but getData
     * does not hand back is in another group (REDCap confined the read), or
     * went in between. For a rule that tests only the value, the table row is
     * the whole test, so the value is found there; the record is never named
     * to the caller (its group is not known here). A rule with "match",
     * "where" or "return" cannot be judged on a record it cannot read: the
     * answer is unknown. Under "scope":"dag" such a record is in another
     * group than the one the rule looks in, so it is left out.
     */
    private function existsFromTable($pid, array $spec, array $params, $scope, $dag, $prefer, $value, array $opts)
    {
        $in = $spec['in'];
        $skip = isset($spec['skip']) ? $spec['skip'] : null;
        $cands = $this->existsCandidates($pid, $in, $value, $spec['fold'], isset($spec['marks'][$in]) ? $spec['marks'][$in] : null,
            $spec['codes'], $spec['event'], $skip);
        if ($cands === null) return null;
        $notFound = ['state' => 'not-found', 'record' => null, 'dag' => null];
        if (!$cands) return $notFound;
        $data = \REDCap::getData($params + ['records' => $cands]);
        if (!is_array($data)) return ['state' => 'unknown', 'record' => null, 'dag' => null];
        $filtered = false;
        if ($spec['returns']) {
            $hits = self::existsHitsIn($data, $spec, $scope, $dag, $filtered);
            if ($hits) {
                $best = self::bestExistsHit($hits, $prefer);
                return ['state' => 'found', 'record' => $best['record'], 'dag' => $best['dag']]
                    + self::existsReturnOutcome($hits, $spec['returns'], $prefer);
            }
        } else {
            $hit = self::existsMatchIn($data, $spec, $scope, $dag, $prefer, $filtered);
            if ($hit !== null) return ['state' => 'found'] + $hit;
        }
        $seen = [];
        foreach ($data as $rec => $node) if (is_array($node)) $seen[(string) $rec] = true;
        $unseen = array_values(array_filter($cands, function ($r) use ($seen) { return !isset($seen[$r]); }));
        if ($unseen && $scope !== 'dag') {
            if (count($spec['target']) === 1 && $spec['where'] === null && !$spec['returns']) {
                return ['state' => 'found', 'record' => $unseen[0], 'dag' => false];
            }
            return ['state' => 'unknown', 'record' => null, 'dag' => null,
                    'why' => isset($opts['callerDag']) && $opts['callerDag'] !== null
                        ? 'a value saved in another Data Access Group may not be visible from yours'
                        : 'the saved values could not be read just now'];
        }
        return self::existsFilteredMiss($notFound, $filtered);
    }

    /** @var Scan\FieldValues|null */
    private $fieldValuesReader = null;

    private function fieldValues()
    {
        if ($this->fieldValuesReader === null) $this->fieldValuesReader = new Scan\FieldValues($this);
        return $this->fieldValuesReader;
    }

    /**
     * The save audit's shared read for $params (PRF-1): the event filter it
     * belongs to, or false when that read failed. Null outside the audit, or
     * for fields the audit did not plan, and the caller reads on its own.
     *
     * In the save audit (beginUniqueAudit) every unique rule of the save
     * shares one read per event filter, of the union of their fields, and a
     * rule compares only the records that hold its value (collisionInShared).
     * Before, every unique field of a save exported the whole project again
     * and built every record's contexts.
     */
    private function sharedCollisionRead(array $params)
    {
        $plan = $this->uniqueAuditPlan;
        if ($plan === null || (int) $plan['pid'] !== (int) $params['project_id']) return null;
        $ek = isset($params['events']) ? implode(',', $params['events']) : '*';
        $union = isset($plan['fields'][$ek]) ? $plan['fields'][$ek] : [];
        if (array_diff($params['fields'], $union)) return null;
        if (!array_key_exists($ek, $this->uniqueAuditReads)) {
            $data = \REDCap::getData(['fields' => $union] + $params);
            if (!is_array($data)) {
                $this->uniqueAuditReads[$ek] = false;
            } else {
                $recs = [];
                foreach ($data as $rec => $node) {
                    if (is_array($node)) $recs[] = [(string) $rec, $node];
                }
                $this->uniqueAuditReads[$ek] = ['records' => $recs];
            }
        }
        return $this->uniqueAuditReads[$ek] === false ? false : $ek;
    }

    /**
     * collisionIn over the shared read $ek: the same answer, the same first
     * match in record order. One pass over each searched field's values finds
     * the records that hold the value's key, and collisionIn compares only
     * those, so no per-record context is built for the others.
     */
    private function collisionInShared($ek, array $target, array $keys, array $marks, $fold, $currentDag, $excludeRecord, array $codes = [], array $also = [])
    {
        reset($target);
        $primary = key($target);
        $records = $this->uniqueAuditReads[$ek]['records'];
        $hits = [];
        foreach (array_merge([$primary], $also) as $f) {
            $mark = isset($marks[$f]) ? $marks[$f] : null;
            $key = Logic::lookupKey($target[$primary], $fold, $mark);
            foreach ($records as $ord => $r) {
                if (isset($hits[$ord])) continue;
                foreach (self::contextValuesOf($r[1], $f) as $v) {
                    if ($v === null || is_array($v)) continue;
                    $rv = Logic::lookupTrim($v);
                    if ($rv !== '' && Logic::lookupKey($rv, $fold, $mark) === $key) { $hits[$ord] = true; break; }
                }
            }
        }
        if (!$hits) return null;
        ksort($hits);
        $subset = [];
        foreach (array_keys($hits) as $o) $subset[$records[$o][0]] = $records[$o][1];
        return self::collisionIn($subset, $target, $keys, $marks, $fold, $currentDag, $excludeRecord, $codes, $also);
    }

    /**
     * The value of $f in each context recordContexts($node) gives, in the
     * same order, without building the contexts: a repeat instance holds its
     * own non-blank value, or else its event's.
     */
    private static function contextValuesOf(array $node, $f)
    {
        $out = [];
        foreach ($node as $k => $row) {
            if ($k === 'repeat_instances' || !is_array($row)) continue;
            $out[] = (isset($row[$f]) && $row[$f] !== '') ? $row[$f] : null;
        }
        if (isset($node['repeat_instances']) && is_array($node['repeat_instances'])) {
            foreach ($node['repeat_instances'] as $evt => $byInstr) {
                if (!is_array($byInstr)) continue;
                $base = (isset($node[$evt]) && is_array($node[$evt]) && isset($node[$evt][$f]) && $node[$evt][$f] !== '') ? $node[$evt][$f] : null;
                foreach ($byInstr as $byInst) {
                    if (!is_array($byInst)) continue;
                    foreach ($byInst as $row) {
                        if (!is_array($row)) continue;
                        $out[] = (isset($row[$f]) && $row[$f] !== '') ? $row[$f] : $base;
                    }
                }
            }
        }
        return $out;
    }

    /** @var array|null pid and event filter => fields the save's unique rules read (sharedCollisionRead) */
    private $uniqueAuditPlan = null;
    /** @var array event filter => the shared read of this save's audit, prepared, or false when it failed */
    private $uniqueAuditReads = [];


    /**
     * Start sharing the full reads of the unique rules among $rules for one
     * save of a record in $pid on $event_id. endUniqueAudit() drops them.
     */
    private function beginUniqueAudit(array $rules, $pid, $event_id)
    {
        $fields = [];
        foreach ($rules as $r) {
            if (!empty($r['configError']) || ModeRegistry::modeOfType(isset($r['type']) ? $r['type'] : '') !== 'unique') continue;
            foreach (self::uniqueVariants($r) as $v) {
                $scope = isset($v['uniqueScope']) ? $v['uniqueScope'] : (isset($r['uniqueScope']) ? $r['uniqueScope'] : 'project');
                $ek = ($scope === 'event' && $event_id) ? (string) $event_id : '*';
                $with = (isset($v['uniqueWith']) && is_array($v['uniqueWith'])) ? $v['uniqueWith'] : [];
                $also = (isset($v['uniqueAlso']) && is_array($v['uniqueAlso'])) ? $v['uniqueAlso'] : [];
                foreach (array_merge($r['fields'], $with, $also) as $f) $fields[$ek][(string) $f] = true;
            }
        }
        $this->uniqueAuditReads = [];
        $this->uniqueAuditPlan = $fields ? ['pid' => (int) $pid, 'fields' => array_map('array_keys', $fields)] : null;
    }

    private function endUniqueAudit()
    {
        $this->uniqueAuditPlan = null;
        $this->uniqueAuditReads = [];
    }

    /**
     * findCollision's comparison over one exported data set: the colliding
     * ['record','dag','field'], or null. The primary value is looked for in
     * the primary field and then in each of $also, keyed with that field's
     * own number mark; the composite components must match in the same
     * merged context either way. $currentDag is the group to stay in ('' for
     * records in no group), or null when the scope is not "dag".
     */
    private static function collisionIn(array $data, array $target, array $keys, array $marks, $fold, $currentDag, $excludeRecord, array $codes = [], array $also = [])
    {
        reset($target);
        $primary = key($target);
        $slots = [$primary => $keys[$primary]];
        foreach ($also as $a) {
            $slots[$a] = Logic::lookupKey($target[$primary], $fold, isset($marks[$a]) ? $marks[$a] : null);
        }

        foreach ($data as $rec => $node) {
            if ($excludeRecord !== null && $excludeRecord !== '' && (string) $rec === (string) $excludeRecord) continue;
            if (!is_array($node)) continue;
            $dag = self::dagOfRecordNode($node);
            if ($currentDag !== null && (string) $dag !== $currentDag) continue;
            // Compare against MERGED contexts (base event row + each repeat
            // instance), not raw rows, so a composite spanning an event field and a
            // repeat-instrument field is detected — the same view the scan uses (H-03).
            foreach (self::recordContexts($node) as $ctx) {
                $row = $ctx['values'];
                $match = true;
                foreach ($target as $f => $tv) {
                    if ($f === $primary) continue;
                    $rv = (isset($row[$f]) && !is_array($row[$f])) ? Logic::lookupTrim($row[$f]) : '';
                    // A blank component's key is '', which no lookupKey equals, so it
                    // matches only a blank stored value (the line below).
                    if ($rv === $tv) continue;
                    if (Logic::lookupKey($rv, $fold, $marks[$f]) !== $keys[$f]) { $match = false; break; }
                }
                if (!$match) continue;
                foreach ($slots as $f => $key) {
                    $rv = (isset($row[$f]) && !is_array($row[$f])) ? Logic::lookupTrim($row[$f]) : '';
                    if ($rv === '' || ($codes && self::isMissingCode($rv, $codes))) continue;
                    if ($rv === $target[$primary] || Logic::lookupKey($rv, $fold, isset($marks[$f]) ? $marks[$f] : null) === $key) {
                        return ['record' => (string) $rec, 'dag' => $dag, 'field' => (string) $f];
                    }
                }
            }
        }
        return null;
    }

    /** Every data row of one record node: plain event rows + repeat instances. */
    private static function rowNodes(array $recordNode)
    {
        $rows = [];
        foreach ($recordNode as $k => $node) {
            if ($k === 'repeat_instances') {
                if (!is_array($node)) continue;
                foreach ($node as $byInstr) {
                    if (!is_array($byInstr)) continue;
                    foreach ($byInstr as $byInst) {
                        if (!is_array($byInst)) continue;
                        foreach ($byInst as $row) {
                            if (is_array($row)) $rows[] = $row;
                        }
                    }
                }
            } elseif (is_array($node)) {
                $rows[] = $node;
            }
        }
        return $rows;
    }

    /** The exported DAG unique name of a record node, or null. */
    private static function dagOfRecordNode(array $recordNode)
    {
        foreach (self::rowNodes($recordNode) as $row) {
            if (isset($row['redcap_data_access_group']) && !is_array($row['redcap_data_access_group'])
                && $row['redcap_data_access_group'] !== '') {
                return (string) $row['redcap_data_access_group'];
            }
        }
        return null;
    }

    // dagNameOf() USED TO LIVE HERE, as a byte-for-byte copy of the six lines
    // inside ScanPageView::scanScope(). Two copies of one lookup is two chances
    // for one of them to drift, and the axis defect this release fixes is
    // precisely that shape one layer up. It is now
    // ScanPageView::dagNameOf(), which the page, this file's live unique-check
    // endpoint and scanProject() all call.

    // -- server-side value read --------------------------------------------

    /**
     * Read the configured fields for one record, scoped to the event and repeat
     * instance that were actually saved. Handles both the classic
     * [record][event][field] layout and the
     * [record]['repeat_instances'][event][instrument|''][instance][field] layout
     * of repeating instruments/events, so the audit checks the saved value rather
     * than a stale value from a different instance (UV-004).
     *
     * Event scoping is strict: when the hook supplied an event ID, only that
     * event's node is read — a value from another event must never be validated
     * (or logged) as this event's value (COR-001). The whole-record scan runs
     * ONLY when no event ID was supplied at all.
     *
     * Returns a map of field => string value (only fields that had a value).
     */
    private function readValues($project_id, $record, array $fields, $event_id, $instrument, $repeat_instance, $keepArrays = false, ?array &$resolution = null)
    {
        // THREE-STATE RESOLUTION (1.6.0). $resolution, when the caller passes
        // it, reports for every requested field exactly one of:
        //   'ok'        - located in a node this context may read (value may be
        //                 empty; a saved blank IS a value and folds as '')
        //   'missing'   - the read succeeded but the field was not present in
        //                 any node scoped to this record/event/instance. That
        //                 covers a field collected only on ANOTHER event.
        //   'ambiguous' - the field lives in a repeating instrument OTHER than
        //                 the one being rendered/saved, so which instance pairs
        //                 with this one is undefined (see below).
        //   'unreadable'- the read itself failed or came back malformed.
        //
        // Callers must treat anything other than 'ok' as "no answer" and refuse
        // to validate on it. Before 1.6.0 all four collapsed to "absent from the
        // map", which Logic::operandValue then rendered as '' — so a failed
        // read, an off-event field and a cross-repeat reference were all
        // indistinguishable from a genuine blank, and the module confidently
        // validated against a value it had never read (H-01/H-04/M-01).
        $resolution = [];
        if (!$fields) return [];
        // Default 'ok'. In REDCap a blank field is simply ABSENT from getData
        // output, so absence must NOT by itself mean unresolvable - that would
        // defer every legitimately empty reference. Only a positively
        // established problem downgrades a field.
        foreach ($fields as $f) $resolution[$f] = 'ok';

        // Ask for the RECORD ID field alongside the requested ones. REDCap omits
        // a blank field from getData output, so a record whose every REQUESTED
        // field is blank comes back with no node at all — indistinguishable, from
        // the outside, from a read that failed. The record id is stored for every
        // existing record and is never blank, so requesting it turns "did this
        // read work" into a positive fact instead of an inference (H-06).
        // It also keeps the repeat_instances buckets in the result, which is what
        // resolveOne() needs to tell a genuine blank from a value on another
        // repeating instrument — without them an all-blank read would resolve
        // 'ok' where it should be 'ambiguous'.
        // $fields is deliberately NOT widened: it keys $resolution and the value
        // map, and the caller asked about its own fields only.
        $pk = $this->recordIdFieldOf($project_id);   // '' when unknown; the guard below still holds
        $readFields = $fields;
        if ($pk !== '' && !in_array($pk, $readFields, true)) $readFields[] = $pk;

        $params = [
            'project_id'    => $project_id,
            'return_format' => 'array',
            'records'       => [$record],
            'fields'        => $readFields,
        ];
        if ($event_id) $params['events'] = [$event_id];
        // A throw is deliberately NOT caught here: redcap_save_record's outer
        // handler turns it into a visible audit-error log entry, and
        // foldRuleConditions marks every field 'unreadable' in its own catch.
        $data = \REDCap::getData($params);
        // A non-array result, or a record node that is not an array, is a failed
        // read — NOT an empty record. Both used to return [] indistinguishably.
        if (!is_array($data)) {
            foreach ($fields as $f) $resolution[$f] = 'unreadable';
            return [];
        }
        // The record is not in the result. That is TWO different situations, and
        // collapsing them switched working rules off (H-06): an EMPTY result means
        // REDCap holds nothing for this record — every requested field is simply
        // blank, which is an answer — whereas a result carrying OTHER records but
        // not the one asked for is anomalous and must not be read as blank.
        //
        // This is the same principle the per-field default above rests on, applied
        // one level up: absence is not, by itself, a failed read. Getting it wrong
        // here deferred every rule on a form whose referenced fields were all still
        // empty — telling the user "reading its saved value failed" when nothing had
        // failed, and silently demoting a blockSave:"hard" rule to advisory on
        // exactly the pass where the field is first filled in.
        if (!isset($data[$record])) {
            if (!$data) return [];      // nothing stored for this record: all blank, all 'ok'
            foreach ($fields as $f) $resolution[$f] = 'unreadable';
            return [];
        }
        if (!is_array($data[$record])) {
            foreach ($fields as $f) $resolution[$f] = 'unreadable';   // malformed node
            return [];
        }
        $rec = $data[$record];

        // field => the instrument that owns it, so a reference is resolved
        // through ITS OWN form rather than through whichever form happens to be
        // rendering. Unknown dictionary => no map; every repeat bucket other
        // than the rendered one is then treated as ambiguous, which is the
        // conservative direction.
        $formOf = [];
        $dd = $this->dataDictionary($project_id);
        if ($dd) {
            foreach ($fields as $f) {
                if (isset($dd[$f]['form_name'])) $formOf[$f] = $dd[$f]['form_name'];
            }
        }

        // Which forms this event actually collects. A reference to a field on a
        // form NOT designated for this event can never be read here, so it is
        // 'missing' (M-01) - that is a positive fact from the project's
        // instrument-event mapping, unlike mere absence from the data, which is
        // just a blank. NULL when the mapping cannot be established (classic
        // projects, or an API that is unavailable): we then never claim
        // 'missing', which fails open to pre-1.6.0 behaviour rather than
        // deferring rules wrongly.
        $eventForms = $this->formsForEvent($project_id, $event_id);
        if ($eventForms !== null) {
            foreach ($fields as $f) {
                if (!isset($formOf[$f])) continue;
                if (!isset($eventForms[$formOf[$f]])) $resolution[$f] = 'missing';
            }
        }

        $inst = (int) ($repeat_instance ?: 1);
        $byEvent = null;
        if (isset($rec['repeat_instances']) && is_array($rec['repeat_instances'])) {
            $ri = $rec['repeat_instances'];
            if ($event_id && isset($ri[$event_id])) $byEvent = $ri[$event_id];
            elseif (!$event_id && count($ri)) $byEvent = reset($ri);
            if (!is_array($byEvent)) $byEvent = null;
        }
        $repeating = $this->repeatingFormsForEvent($project_id, $event_id, array_values($formOf));

        $out = [];
        foreach ($fields as $f) {
            if ($resolution[$f] === 'missing') continue;   // not collected here at all
            $r = self::resolveOne($f, $rec, $byEvent, $formOf, $repeating, $event_id, $instrument, $inst);
            $resolution[$f] = $r['state'];
            if ($r['state'] !== 'ok') continue;
            $val = $r['value'];
            if ($val !== null && $val !== '') {
                if (is_array($val)) {
                    // Checkbox fields arrive as code => '0'/'1' maps. Kept only
                    // when the caller asked for them ("when" refs); validated
                    // fields are Text/Notes, so an array can never reach the
                    // per-field audit loop.
                    if ($keepArrays) $out[$f] = $val;
                } else {
                    $out[$f] = is_string($val) ? $val : (string) $val;
                }
            }
        }
        return $out;
    }

    /**
     * THE resolver. One field, one context => ['state' => ok|ambiguous, 'value' => mixed].
     *
     * This is deliberately the ONLY place that decides where a referenced value
     * lives. Before it existed, the form hooks, the save audit and the
     * Validation scan each worked it out separately and disagreed: the scan
     * reported a hard violation for data the save path called unconfigurable,
     * and neither noticed a value on a different repeating instrument when that
     * value happened to be blank.
     *
     * Ownership comes from METADATA, never from whether a value happens to be
     * present. REDCap omits blank fields from getData output entirely, so
     * "the field's key is in this repeat row" answers "does it have a value",
     * not "does it live here" — reading ownership off that made a blank field
     * on another repeating instrument look like a resolved blank and produced a
     * false violation (H-02). Ownership is taken from the data dictionary
     * (which form owns the field) plus whether that form repeats in this event;
     * the repeat BUCKET's existence is the fallback signal when the repeat
     * metadata API is unavailable, because a bucket exists as soon as the form
     * has any instance, whatever the field values are.
     *
     * $byEvent is the repeat_instances node for this event: keys are instrument
     * names, plus "" for a repeating EVENT, where every form shares the
     * instance and nothing is ambiguous.
     */
    private static function resolveOne($f, array $rec, $byEvent, array $formOf, $repeating, $event_id, $instrument, $inst)
    {
        $own = isset($formOf[$f]) ? $formOf[$f] : null;

        // Repeating EVENT bucket: shared by every form in the event.
        if (is_array($byEvent) && isset($byEvent[''][$inst]) && is_array($byEvent[''][$inst])
            && array_key_exists($f, $byEvent[''][$inst])) {
            return ['state' => 'ok', 'value' => $byEvent[''][$inst][$f]];
        }

        // Does the field's own form repeat here? Metadata first; bucket
        // presence as the fallback (a bucket exists per FORM, independent of
        // whether any particular field in it has a value).
        // The two signals are OR-ed, not ranked. Metadata sees a repeating form
        // that has no instances yet, which bucket presence cannot; an existing
        // bucket is direct evidence of repeating even if the metadata call is
        // stale, unavailable, or answers for the wrong event. Either one saying
        // "repeats" is enough to refuse the pairing, which is the safe
        // direction: the cost of a false "repeats" is a deferred rule with a
        // stated reason, the cost of a false "does not" is a wrong verdict.
        $ownRepeats = null;
        if ($own !== null) {
            $byMeta   = is_array($repeating) ? isset($repeating[$own]) : null;
            $byBucket = is_array($byEvent) ? array_key_exists($own, $byEvent) : null;
            if ($byMeta === true || $byBucket === true) $ownRepeats = true;
            elseif ($byMeta === false || $byBucket === false) $ownRepeats = false;
        }
        // A repeating EVENT makes every form in it instance-scoped and aligned.
        $eventRepeats = is_array($byEvent) && array_key_exists('', $byEvent);

        if ($own !== null && $ownRepeats === true && !$eventRepeats) {
            if ($own !== $instrument) {
                // Instance N of one repeating form has no defined counterpart in
                // another. REDCap itself needs [instrument][instance] smart
                // variables to cross that boundary, so refuse rather than guess
                // — whether or not the field happens to hold a value.
                return ['state' => 'ambiguous', 'value' => null];
            }
            if (is_array($byEvent) && isset($byEvent[$own][$inst]) && is_array($byEvent[$own][$inst])
                && array_key_exists($f, $byEvent[$own][$inst])) {
                return ['state' => 'ok', 'value' => $byEvent[$own][$inst][$f]];
            }
            return ['state' => 'ok', 'value' => null];   // this instance, genuinely blank
        }

        // Non-repeating owner (or owner unknown): the event's base row.
        if ($event_id && isset($rec[$event_id]) && is_array($rec[$event_id])
            && array_key_exists($f, $rec[$event_id])) {
            return ['state' => 'ok', 'value' => $rec[$event_id][$f]];
        }

        // No event context at all (some import/API paths): scan the record's
        // non-repeating nodes. With an event id present a miss means "no value
        // on this event" — reading another event's value here logged the wrong
        // event's data (COR-001).
        if (!$event_id) {
            foreach ($rec as $k => $node) {
                if ($k === 'repeat_instances') continue;
                if (is_array($node) && array_key_exists($f, $node)) {
                    return ['state' => 'ok', 'value' => $node[$f]];
                }
            }
        }

        // Owner unknown AND the record has repeat buckets we did not match:
        // we cannot tell a blank from a value on another instrument, so refuse.
        if ($own === null && is_array($byEvent) && $byEvent) {
            foreach ($byEvent as $ik => $_) {
                if ($ik !== '' && $ik !== $instrument) return ['state' => 'ambiguous', 'value' => null];
            }
        }

        return ['state' => 'ok', 'value' => null];       // genuinely blank
    }

    /**
     * The set (form_name => true) of instruments that REPEAT in $event_id, or
     * NULL when that cannot be established. NULL makes resolveOne() fall back
     * to repeat-bucket presence, which is weaker but still ownership-based.
     */
    /**
     * TWO caches, because the two sources below do not answer the same question.
     * Source 1 reads the whole event's map and is independent of $forms, so its
     * answer may be served to any caller. Source 2 probes ONE FORM AT A TIME and
     * its answer describes only the forms it was given — caching that under the
     * bare (pid|event) key silently reports every form the first caller did not
     * ask about as non-repeating. That is reachable in the scan today:
     * hostContextsFor() asks about a single host form and runs BEFORE
     * contextResolution() asks about the whole read set, so on any build without
     * getRepeatingFormsEvents the one-form answer was served to the all-forms
     * call and a genuinely repeating form read as a resolved blank — H-02 again,
     * by way of the cache. Neither source caches a NULL: a failed or unavailable
     * read must not harden into a permanent verdict (H-06), and the old code's
     * "write null, then refuse to serve it" achieved the same thing by a longer
     * route.
     */
    private $repeatFormsCache = [];   // pid|event         => set, from the whole-event map
    private $repeatProbeCache = [];   // pid|event|forms    => set, from the per-form probe
    private function repeatingFormsForEvent($project_id, $event_id, array $forms = [])
    {
        $key = $project_id . '|' . $event_id;
        // Source 1's answer — INCLUDING its null — is cached, because it is a
        // property of the event and not of $forms: null here means "this build
        // does not expose the whole-event map", which cannot change within a
        // request. Caching it is what stops the map being re-queried once per
        // context on such a build; it does not harden a verdict, because a null
        // still falls through to source 2 and then to the caller's own
        // bucket-presence fallback exactly as before.
        if (array_key_exists($key, $this->repeatFormsCache)) {
            $out = $this->repeatFormsCache[$key];
            if ($out !== null) return $out;
        } else {
            $out = $this->repeatingFormsFromMap($project_id, $event_id);
            $this->repeatFormsCache[$key] = $out;
            if ($out !== null) return $out;
        }
        // Second source. getRepeatingFormsEvents is not exposed on every build;
        // isRepeatingForm answers the same question one form at a time. Without
        // one of them the only signal left is whether a repeat BUCKET exists,
        // which cannot see a repeating form that has no instances yet - exactly
        // the case where a blank reference looks like a resolved blank (H-02).
        if (!$forms) return null;
        $probe = [];
        foreach ($forms as $form) if (is_string($form) && $form !== '') $probe[$form] = true;
        if (!$probe) return null;
        $probe = array_keys($probe);
        // Canonical order so ['a','b'] and ['b','a'] share one entry. The probe
        // result does not depend on the order: any NULL answer abandons the whole
        // set, whichever form produced it. \x1F separates, because a form name
        // may legitimately contain any other punctuation - the same reason the
        // unique group key avoids a printable delimiter (L-01).
        sort($probe);
        $pkey = $key . '|' . implode("\x1F", $probe);
        if (array_key_exists($pkey, $this->repeatProbeCache)) return $this->repeatProbeCache[$pkey];
        $out = null;
        try {
            if (is_callable(['\REDCap', 'isRepeatingForm'])) {
                $set = [];
                $any = false;
                foreach ($probe as $form) {
                    $r = \REDCap::isRepeatingForm($event_id, (string) $form);
                    if ($r === null) { $any = false; break; }
                    $any = true;
                    if ($r) $set[$form] = true;
                }
                if ($any) $out = $set;
            }
        } catch (\Throwable $e) {
            $out = null;
        }
        if ($out !== null) $this->repeatProbeCache[$pkey] = $out;
        return $out;
    }

    /** Source 1 alone: the whole-event repeating-form map, or null. */
    private function repeatingFormsFromMap($project_id, $event_id)
    {
        $out = null;
        $map = $this->repeatingMap($project_id);
        if (is_array($map)) {
            // [event_id => [form_name => custom_label|null]] and, for a
            // repeating EVENT, [event_id => ['' => ...]] or a bare list.
            $node = null;
            if ($event_id && array_key_exists($event_id, $map)) $node = $map[$event_id];
            elseif (!$event_id && count($map)) $node = reset($map);
            if (is_array($node)) {
                $set = [];
                foreach ($node as $form => $_) if (is_string($form) && $form !== '') $set[$form] = true;
                $out = $set;    // may legitimately be empty: nothing repeats here
            }
        }
        return $out;
    }

    /**
     * The set (form_name => true) of instruments designated for $event_id, or
     * NULL when that cannot be established — a classic (non-longitudinal)
     * project, or a REDCap build that does not expose the mapping.
     *
     * NULL means "do not claim a field is off-event", which fails OPEN to
     * pre-1.6.0 behaviour. That is the right direction: wrongly claiming a
     * field is off-event would defer a rule that works today, whereas failing
     * to claim it leaves exactly the M-01 gap the docs now describe.
     *
     * Cached per (pid, event) for the request — the save audit and every scan
     * context ask repeatedly.
     */
    private $eventFormsCache = [];
    private function formsForEvent($project_id, $event_id)
    {
        if (!$event_id) return null;                 // classic / no event context
        $key = $project_id . '|' . $event_id;
        if (array_key_exists($key, $this->eventFormsCache)) return $this->eventFormsCache[$key];
        $out = null;
        try {
            // REDCap keys the mapping by unique_event_name ("event_1_arm_1"),
            // NOT by the numeric event_id the hooks hand us, so the numeric id
            // must be translated first or nothing ever matches and this whole
            // check silently becomes dead code.
            $unique = null;
            if (is_callable(['\REDCap', 'getEventNames'])) {
                $u = \REDCap::getEventNames(true, false, $event_id);
                if (is_string($u) && $u !== '') $unique = $u;
                elseif (is_array($u) && isset($u[$event_id]) && is_string($u[$event_id])) $unique = $u[$event_id];
            }
            if (is_callable(['\REDCap', 'getInstrumentEventMappings'])) {
                $map = \REDCap::getInstrumentEventMappings($project_id);
                if (is_array($map)) {
                    // Rows may be flat, or nested one level per arm. Accept both,
                    // and match on EITHER key so a build that exposes event_id
                    // directly also works.
                    $rows = [];
                    foreach ($map as $entry) {
                        if (!is_array($entry)) continue;
                        if (isset($entry['form']) || isset($entry['form_name'])) $rows[] = $entry;
                        else foreach ($entry as $sub) if (is_array($sub)) $rows[] = $sub;
                    }
                    $sawThisEvent = false;
                    $acc = [];
                    foreach ($rows as $row) {
                        $fm = isset($row['form']) ? $row['form']
                            : (isset($row['form_name']) ? $row['form_name'] : null);
                        if ($fm === null) continue;
                        $match = false;
                        if (isset($row['event_id']) && (string) $row['event_id'] === (string) $event_id) $match = true;
                        if (!$match && $unique !== null && isset($row['unique_event_name'])
                            && (string) $row['unique_event_name'] === (string) $unique) $match = true;
                        if ($match) { $sawThisEvent = true; $acc[$fm] = true; }
                    }
                    // Only trust a mapping that actually mentions THIS event. A
                    // mapping we could not locate ourselves in stays NULL, which
                    // fails open rather than declaring every field off-event.
                    if ($sawThisEvent && $acc) $out = $acc;
                }
            }
        } catch (\Throwable $e) {
            $out = null;
        }
        $this->eventFormsCache[$key] = $out;
        return $out;
    }

    /**
     * The set (form_name => true) of instruments designated to AT LEAST ONE
     * event, or NULL when that cannot be established.
     *
     * formsForEvent() answers the per-event question and needs a numeric event
     * id; this answers "is this instrument collected anywhere at all", which is
     * what decides whether a rule on it can ever run. Reading the mapping ONCE
     * rather than once per event matters on a project with thirty events, where
     * the per-event route would rebuild the same row list thirty times for an
     * answer that does not depend on the event.
     *
     * NULL, not an empty set, when the mapping is unusable or names nothing: an
     * empty result is exactly what a classic project returns, and treating that
     * as "no instrument is collected" would declare every rule in the project
     * dead. Only a mapping that names something is evidence.
     */
    private $mappedFormsCache = [];
    private function mappedInstruments($project_id)
    {
        if (array_key_exists($project_id, $this->mappedFormsCache)) {
            return $this->mappedFormsCache[$project_id];
        }
        $out = null;
        try {
            if (is_callable(['\REDCap', 'getInstrumentEventMappings'])) {
                $map = \REDCap::getInstrumentEventMappings($project_id);
                if (is_array($map)) {
                    // Rows may be flat, or nested one level per arm - the same
                    // two shapes formsForEvent() accepts, for the same reason.
                    $acc = [];
                    foreach ($map as $entry) {
                        if (!is_array($entry)) continue;
                        $rows = (isset($entry['form']) || isset($entry['form_name']))
                              ? [$entry] : $entry;
                        foreach ($rows as $row) {
                            if (!is_array($row)) continue;
                            $fm = isset($row['form']) ? $row['form']
                                : (isset($row['form_name']) ? $row['form_name'] : null);
                            if (is_string($fm) && $fm !== '') $acc[$fm] = true;
                        }
                    }
                    if ($acc) $out = $acc;
                }
            }
        } catch (\Throwable $e) {
            $out = null;
        }
        $this->mappedFormsCache[$project_id] = $out;
        return $out;
    }

    /**
     * form_name => [event => true] from the instrument-event mapping, or NULL
     * when it cannot be read, names nothing (a classic project), or keys its
     * rows in more than one way (event id on some, unique name on others), so
     * that two forms cannot be compared reliably.
     */
    private $formEventsCache = [];
    private function formEventSets($project_id)
    {
        $ck = (string) $project_id;
        if (array_key_exists($ck, $this->formEventsCache)) return $this->formEventsCache[$ck];
        $out = null;
        try {
            if (is_callable(['\REDCap', 'getInstrumentEventMappings'])) {
                $map = \REDCap::getInstrumentEventMappings($project_id);
                if (is_array($map)) {
                    // the same two row shapes formsForEvent() accepts
                    $acc = [];
                    $kinds = [];
                    foreach ($map as $entry) {
                        if (!is_array($entry)) continue;
                        $rows = (isset($entry['form']) || isset($entry['form_name'])) ? [$entry] : $entry;
                        foreach ($rows as $row) {
                            if (!is_array($row)) continue;
                            $fm = isset($row['form']) ? $row['form']
                                : (isset($row['form_name']) ? $row['form_name'] : null);
                            if (!is_string($fm) || $fm === '') continue;
                            if (isset($row['unique_event_name'])) { $ev = 'n:' . $row['unique_event_name']; $kinds['n'] = true; }
                            elseif (isset($row['event_id'])) { $ev = 'i:' . $row['event_id']; $kinds['i'] = true; }
                            else continue;
                            $acc[$fm][$ev] = true;
                        }
                    }
                    if ($acc && count($kinds) === 1) $out = $acc;
                }
            }
        } catch (\Throwable $e) {
            $out = null;
        }
        $this->formEventsCache[$ck] = $out;
        return $out;
    }

    /**
     * The project's events as the @UVUNIQUE "also" placement check needs them:
     * list of ['forms' => form => true, or null for every form,
     *          'repeat' => form => true for the forms that repeat there, or
     *                      null when that cannot be read,
     *          'repeatEvent' => whether the event itself repeats].
     * NULL when the events cannot be established, so nothing is claimed.
     */
    private $eventLayoutCache = [];
    private function eventLayout($project_id)
    {
        $ck = (string) $project_id;
        if (array_key_exists($ck, $this->eventLayoutCache)) return $this->eventLayoutCache[$ck];
        $rep = null;
        $names = null;
        $longitudinal = null;
        try {
            $r = $this->repeatingMap($project_id);
            if (is_array($r)) $rep = $r;
            if (is_callable(['\REDCap', 'getEventNames'])) {
                $n = \REDCap::getEventNames(true, false);
                if (is_array($n)) $names = $n;
            }
            if (is_callable(['\REDCap', 'isLongitudinal'])) $longitudinal = (bool) \REDCap::isLongitudinal();
        } catch (\Throwable $e) {
            $rep = null;
        }
        $repeatOf = function ($eventId) use ($rep) {
            if ($rep === null || $eventId === null) return [null, false];
            if (!isset($rep[$eventId])) return [[], false];
            $node = $rep[$eventId];
            if (!is_array($node)) return [[], true];
            $set = [];
            foreach ($node as $form => $_) {
                // '' or a bare list: the event itself repeats.
                if (!is_string($form) || $form === '') return [[], true];
                $set[$form] = true;
            }
            return [$set, false];
        };
        $out = null;
        $byForm = $this->formEventSets($project_id);
        if ($byForm !== null) {
            $events = [];
            foreach ($byForm as $form => $evs) {
                foreach ($evs as $ev => $_) $events[$ev][$form] = true;
            }
            $out = [];
            foreach ($events as $ev => $forms) {
                $id = null;
                if (strpos($ev, 'i:') === 0) $id = substr($ev, 2);
                elseif ($names !== null) {
                    $hit = array_search(substr($ev, 2), $names, true);
                    if ($hit !== false) $id = (string) $hit;
                }
                list($repeat, $repeatEvent) = $repeatOf($id);
                $out[] = ['forms' => $forms, 'repeat' => $repeat, 'repeatEvent' => $repeatEvent];
            }
        } elseif ($longitudinal === false && $rep !== null && count($rep) <= 1) {
            // A classic project: one event holding every form.
            list($repeat, $repeatEvent) = $repeatOf($rep ? (string) key($rep) : null);
            if (!$rep) $repeat = [];
            $out = [['forms' => null, 'repeat' => $repeat, 'repeatEvent' => $repeatEvent]];
        }
        $this->eventLayoutCache[$ck] = $out;
        return $out;
    }

    /**
     * Why a @UVUNIQUE "with" field can never be read beside the tagged field,
     * or null (UNQ-2). The composite key is read in the context the tagged
     * field is saved in, so some event must hold both fields' instruments,
     * and in that event the "with" field must not sit on a repeating
     * instrument of its own: no instance of it belongs to the tagged field's
     * entry. A rule placed so is refused when it is read; before, the live
     * check and the audit compared the part as a blank and the scan refused
     * the rule at run time. $layout is eventLayout(); null claims nothing.
     */
    private static function withPlacementProblem(array $with, $field, $dd, $layout)
    {
        if (!is_array($layout) || !is_array($dd)) return null;
        $formOf = function ($f) use ($dd) {
            return isset($dd[$f]['form_name']) ? (string) $dd[$f]['form_name'] : null;
        };
        $ft = $formOf($field);
        if ($ft === null) return null;
        foreach ($with as $w) {
            $fw = $formOf($w);
            if ($fw === null || $fw === $ft) continue;
            $held = false;
            $paired = false;
            foreach ($layout as $ev) {
                if ($ev['forms'] !== null && (!isset($ev['forms'][$ft]) || !isset($ev['forms'][$fw]))) continue;
                $held = true;
                // In an event that repeats as a whole, its forms share an
                // instance. Otherwise a repeating form of the "with" field has
                // no instance that belongs to the tagged field's entry.
                if ($ev['repeatEvent'] || $ev['repeat'] === null || !isset($ev['repeat'][$fw])) { $paired = true; break; }
            }
            if (!$held) {
                return '"with" field "' . $w . '" is collected in no event with "' . $field . '", so the composite '
                    . 'key could never be read beside it and the rule would never be checked. Keep both fields '
                    . 'in the same event.';
            }
            if (!$paired) {
                return '"with" field "' . $w . '" is on the repeating instrument "' . $fw . '" in every event that holds "'
                    . $field . '", and no instance of it belongs to the entry of "' . $field . '", so the rule would '
                    . 'never be checked. Put both fields on the same instrument, or use a "with" field that does not repeat.';
            }
        }
        return null;
    }

    /**
     * Why a @UVUNIQUE "also" field can never hold a value the rule finds, or
     * null.
     *
     * findCollision reads another record context by context
     * (recordContexts): an event's base row, or one repeat instance merged
     * over it. A value in "also" field A is found only beside the rule's
     * "with" values in one context, and with "scope":"event" only in the
     * event the checked field is saved in. So some event must hold A, every
     * "with" field and, under event scope, the checked field; and in that
     * event A and the "with" fields may sit on at most one repeating
     * instrument, because two repeating instruments never share a context.
     * $layout is eventLayout(); null claims nothing.
     */
    private static function alsoPlacementProblem(array $also, $field, array $with, $scope, $dd, $layout)
    {
        if (!is_array($layout) || !is_array($dd)) return null;
        $formOf = function ($f) use ($dd) {
            return isset($dd[$f]['form_name']) ? (string) $dd[$f]['form_name'] : null;
        };
        foreach ($also as $a) {
            $fa = $formOf($a);
            if ($fa === null) continue;
            $need = [$a => $fa];
            foreach ($with as $w) if ($formOf($w) !== null) $need[$w] = $formOf($w);
            if ($scope === 'event' && $formOf($field) !== null) $need[$field] = $formOf($field);
            $held = false;
            $clash = null;      // a "with" field on another repeating instrument, in every event that holds them all
            foreach ($layout as $ev) {
                foreach ($need as $form) {
                    if ($ev['forms'] !== null && !isset($ev['forms'][$form])) continue 2;
                }
                $held = true;
                $hit = null;
                if (!$ev['repeatEvent'] && $ev['repeat'] !== null && isset($ev['repeat'][$fa])) {
                    foreach ($with as $w) {
                        $fw = $formOf($w);
                        if ($fw !== null && $fw !== $fa && isset($ev['repeat'][$fw])) { $hit = $w; break; }
                    }
                }
                $clash = $hit;
                if ($hit === null) break;
            }
            if (!$held) {
                $others = array_keys($need);
                array_shift($others);
                if (!$others) {
                    return '"also" field "' . $a . '" is on instrument "' . $fa . '", which no event collects, '
                        . 'so it would never be searched.';
                }
                $where = [];
                if ($with) $where[] = 'beside the "with" values';
                if ($scope === 'event') $where[] = 'in the event "' . $field . '" is saved in';
                return '"also" field "' . $a . '" is in no event together with "' . implode('", "', $others)
                    . '" — the value is looked for only ' . implode(' and ', $where)
                    . ', so "' . $a . '" would never be searched.';
            }
            if ($clash !== null) {
                return '"also" field "' . $a . '" and "with" field "' . $clash
                    . '" are on different repeating instruments, so no record holds them side by side and "'
                    . $a . '" would never be searched.';
            }
        }
        return null;
    }

    /**
     * Human wording for a non-'ok' resolution state, used both in the visible
     * config-error notice and in the scan's "unconfigurable" list so a designer
     * is told WHY a rule stopped checking instead of silently getting no
     * verdict (the module's M-05 "nothing fails silently" rule).
     */
    private static function resolutionProblem($state, $field)
    {
        switch ($state) {
            case 'ambiguous':
                return 'references "[' . $field . ']", which is on a different repeating instrument — '
                     . 'there is no defined pairing between instances of two repeating forms, so this '
                     . 'value is not checked. Put both fields on the same instrument, or reference a '
                     . 'field that does not repeat.';
            case 'missing':
                return 'references "[' . $field . ']", which is not collected in this event — the value '
                     . 'cannot be read here, so this rule is not checked. Keep both fields in the same event.';
            case 'unreadable':
                return 'references "[' . $field . ']", but reading its saved value failed — the rule is '
                     . 'not checked rather than checked against a blank.';
        }
        return 'references "[' . $field . ']", which could not be resolved.';
    }
}
