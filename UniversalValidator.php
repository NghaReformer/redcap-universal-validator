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
require_once __DIR__ . '/php/TemporalIntegration.php';
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
require_once __DIR__ . '/php/Scan/SqlScanStore.php';
require_once __DIR__ . '/php/Scan/WorkerSlots.php';
require_once __DIR__ . '/php/Scan/ScanRetention.php';
require_once __DIR__ . '/php/Scan/RecordManifestSource.php';
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
    /**
     * Per-request data dictionary cache, keyed BY PROJECT ID.
     *
     * Keyed, because one process legitimately touches more than one project;
     * and holding successes only, because a failed read is not an answer to
     * cache. See dataDictionary().
     */
    private $ddCache = [];

    /** Per-request HMAC key cache: false = unresolved, null = unavailable. */
    private $hmacKey = false;

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
        try {
            // $project_id comes straight from the hook and is reliable in every
            // save context (form, survey, API, import, cron); $this->getProjectId()
            // is NOT (it can be null on import/API), so thread it explicitly into
            // EVERY settings/dictionary read (SEC-002).
            $rules = $this->getRules($project_id);
            if (!$rules) return;
            try {
                $this->auditTemporalRules($rules, $project_id, $record, $instrument, $event_id, $repeat_instance, $logMode);
            } catch (\Throwable $e) {
                foreach ($rules as $i=>$rule) if (TemporalRules::extended($rule)) {
                    $this->logUnconfigurable($i,$rule['fields'],'Extended audit incomplete: evaluation unavailable; run the validation scan.',$instrument,$event_id,$repeat_instance);
                }
            }
            $rules = array_filter($rules, function ($rule) { return !TemporalRules::extended($rule); });
            if (!$rules) return;

            // A field claimed by more than one live rule has no well-defined
            // verdict — the client refuses to attach a validator there, and the
            // server must not pick one arbitrarily. Mirror the client: skip.
            $dupes = [];
            foreach (self::duplicateFields($rules) as $f) $dupes[$f] = true;

            // Scope to the saved instrument when it is known (null = no filter).
            $onForm = $this->fieldsOnInstrument($project_id, $instrument);

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
                try {
                    $whole = \REDCap::getData([
                        'project_id' => $project_id, 'return_format' => 'array',
                        'records' => [$record], 'fields' => array_keys($readSet),
                    ]);
                    if (is_array($whole) && isset($whole[$record]) && is_array($whole[$record])) {
                        $hostContexts = [];
                        foreach (self::recordContexts($whole[$record]) as $hctx) {
                            // Same event only: a save cannot speak for another event.
                            if ($event_id && (string) $hctx['event_id'] !== (string) $event_id) continue;
                            $hostContexts[] = $hctx;
                        }
                    }
                } catch (\Throwable $e) {
                    $this->logAuditError($logMode, $project_id, $record, $instrument, $e, 'dependent contexts');
                }
            }

            foreach ($rules as $ruleIndex => $rule) {
                if (!empty($rule['configError'])) continue; // misconfigured -> client/dialog shows the error
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
        } catch (\Throwable $e) {
            // Never let an audit failure abort the save or vanish without a trace.
            $this->logAuditError($logMode, $project_id, $record, $instrument, $e, 'audit');
        }
    }

    /**
     * Validate one rule's fields against the values read for this save, and
     * log the findings. Thin wrapper: the verdicts come from ruleFindings(),
     * the ONE dispatch shared with the project scan page — the hook and the
     * scan can never disagree about what a violation is.
     */
    private function auditRule(array $rule, $ruleIndex, array $values, array $dupes, $onForm, $logMode, $project_id, $record, $instrument, $event_id, $repeat_instance, $whenAst = null, array $resolution = [], array $meta = [])
    {
        $f = $this->ruleFindings($rule, $ruleIndex, $values, $dupes, $onForm, $project_id, $record, $event_id, $whenAst, $resolution, $meta);
        foreach ($f['unconfigurable'] as $u) {
            $this->logUnconfigurable($ruleIndex, $u['fields'], $u['why'], $instrument, $event_id, $repeat_instance);
        }
        foreach ($f['invalid'] as $v) {
            $this->logInvalid($logMode, $project_id, $record, $v['field'], $v['value'], $v['algo'], $v['type'], $instrument, $event_id, $repeat_instance, $v['reason']);
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
     *    'unconfigurable'  => [ ['fields' => [...], 'why' => string], ... ]]
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
        // guessed algorithm.
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
            $active = [];
            $else = null;
            foreach ($rule['branches'] as $bi => $b) {
                if (!isset($b['when']) || !is_string($b['when']) || $b['when'] === '') {
                    $else = $bi;
                    continue;
                }
                $ast = isset($asts[$bi]) ? $asts[$bi] : false;
                if (!is_array($ast)) {
                    $out['unconfigurable'][] = ['fields' => $rule['fields'], 'why' => 'a branch "when" condition cannot be evaluated — field skipped'];
                    return $out;
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
                        $out['unconfigurable'][] = ['fields' => $rule['fields'],
                            'why' => 'a branch "when" condition ' . self::resolutionProblem($state, $ref[0])
                                   . ' No branch can be chosen, so the field is not checked here.'];
                        return $out;
                    }
                }
                if (Logic::evaluate($ast, $values, Logic::BLANK_INERT, !empty($b['caseSensitive']))) $active[] = $bi;
            }
            if (count($active) > 1) {
                $out['unconfigurable'][] = ['fields' => $rule['fields'],
                    'why' => 'more than one "when" condition is true for this field (branch conflict) — field skipped: "'
                    . $rule['branches'][$active[0]]['when'] . '" | "' . $rule['branches'][$active[1]]['when'] . '"'];
                return $out;
            }
            if (count($active) === 1) $pick = $active[0];
            elseif ($else !== null) $pick = $else;
            else return $out; // no branch applies to this context — the field is inert

            $branch = $rule['branches'][$pick];
            unset($branch['when']);
            $flat = array_merge([
                'type'   => isset($rule['type']) ? $rule['type'] : 'single',
                'fields' => $rule['fields'],
            ], $branch);
            return $this->ruleFindings($flat, $ruleIndex, $values, $dupes, $onForm, $project_id, $record, $event_id, null, $resolution, $meta);
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
            if (!Logic::evaluate($whenAst, $values, Logic::BLANK_INERT, !empty($rule['caseSensitive']))) return $out;
        }

        // The mode's own verdict (php/modes.json "evaluator").
        $evaluator = ModeRegistry::evaluator($mode);
        return $this->$evaluator($rule, $type, $values, $dupes, $onForm, $project_id, $record, $event_id, $resolution, $meta);
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
        $scope = isset($rule['uniqueScope']) ? $rule['uniqueScope'] : 'project';
        foreach ($rule['fields'] as $field) {
            if (isset($dupes[$field])) continue;
            if ($onForm !== null && !isset($onForm[$field])) continue;
            $value = isset($values[$field]) ? $values[$field] : null;
            if ($value === null || is_array($value) || trim((string) $value) === '') continue;
            if (isset($rule['uniqueRecordResults'])) {
                if (!array_key_exists($field,$rule['uniqueRecordResults']) || $rule['uniqueRecordResults'][$field] === null) {
                    $out['unconfigurable'][]=['fields'=>[$field],'why'=>'Record uniqueness could not be resolved.'];
                } elseif ($rule['uniqueRecordResults'][$field] === false) {
                    $out['invalid'][]=['field'=>$field,'value'=>$value,'algo'=>'unique','type'=>'unique','reason'=>'duplicate-value'];
                }
                continue;
            }
            $cand = [$field => trim((string) $value)];
            foreach ($with as $w) {
                $cand[$w] = (isset($values[$w]) && !is_array($values[$w])) ? trim((string) $values[$w]) : '';
            }
            if ($this->findCollision($project_id, $field, $with, $scope, $cand, $record, $event_id) !== null) {
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
        $locals = (isset($rule['existsLocal']) && is_array($rule['existsLocal'])) ? $rule['existsLocal'] : [];
        foreach ($rule['fields'] as $field) {
            if (isset($dupes[$field])) continue;
            if ($onForm !== null && !isset($onForm[$field])) continue;
            $value = isset($values[$field]) ? $values[$field] : null;
            if ($value === null || is_array($value) || trim((string) $value) === '') continue;
            $lv = [];
            foreach ($locals as $lf) {
                $state = isset($resolution[$lf]) ? $resolution[$lf] : 'ok';
                if ($state !== 'ok') {
                    $out['unconfigurable'][] = ['fields' => [$field],
                        'why' => 'the "match" field ' . self::resolutionProblem($state, $lf) . ' — field not checked'];
                    continue 2;
                }
                $v = isset($values[$lf]) ? $values[$lf] : '';
                if (is_array($v) || trim((string) $v) === '') continue 2;   // nothing to narrow by yet
                $lv[$lf] = trim((string) $v);
            }
            $r = $this->existsLookup($project_id, $rule, trim((string) $value), $lv, $event_id, $record, $meta);
            if ($r['state'] === 'not-found') {
                $out['invalid'][] = ['field' => $field, 'value' => $value, 'algo' => 'exists', 'type' => 'exists', 'reason' => 'not-found'];
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
     */
    private function crossScanProblem($pid, array $rule)
    {
        foreach (self::existsParts($rule) as $p) {
            if (empty($p['existsPid'])) continue;
            $b = (int) $p['existsPid'];
            $c = $this->crossGate($pid, $p);
            if ($c === null) return 'project ' . $b . ' does not answer this lookup from this project';
            $why = $this->crossCaller($b, $p, $c, $this->currentUsername() === null, $confine, $callerDag);
            if ($why !== null) return $why . ' (project ' . $b . ')';
            if ($confine !== null || $callerDag !== null) {
                return 'your account is in a Data Access Group of project ' . $b . ', so the one read the scan makes '
                    . 'there could miss values saved in the other groups';
            }
        }
        return null;
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
    /** @var array pid => the Data Access Groups this request's read of the project showed, or false */
    private $groupVisibility = [];

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
        if (!empty($meta['existsIndex'])) return $this->existsIndexLookup($pid, $rule, $value, $locals, $eventId, $dag);
        $opts = [];
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
     * unknown). Exact comparison of trimmed stored values, case-sensitive: a
     * date is compared in the Y-M-D form REDCap stores.
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
     *                it is unknown (groupReadSeesOthers).
     *   mayFullRead  a callable asked before the confirming full read; false
     *                answers unknown (the unauthenticated read budget).
     */
    private function findExisting($pid, array $rule, $value, array $locals, $eventId, $dag, $narrow = false, array $opts = [])
    {
        $unknown = ['state' => 'unknown', 'record' => null, 'dag' => null];
        $value = trim((string) $value);
        $spec = $this->existsSpec($pid, $rule, $value, $locals, $eventId);
        if ($spec === null || $value === '') return $unknown;
        $scope = isset($rule['existsScope']) ? $rule['existsScope'] : 'project';
        if ($scope === 'dag' && $dag === false) return $unknown;
        try {
            if ($spec['in'] === 'record') {
                $pk = $this->recordIdFieldOf($pid);
                if ($pk === null) return $unknown;
                $data = \REDCap::getData(['project_id' => $pid, 'return_format' => 'array', 'records' => [$value],
                                          'fields' => [$pk], 'exportDataAccessGroups' => true]);
                if (!is_array($data)) return $unknown;
                foreach ($data as $rec => $node) {
                    if ((string) $rec !== $value || !is_array($node)) continue;
                    $rdag = self::dagOfRecordNode($node);
                    if ($scope === 'dag' && $rdag !== $dag) continue;
                    return ['state' => 'found', 'record' => (string) $rec, 'dag' => $rdag];
                }
                return $this->existsMiss($pid, $scope, $opts, $dag);
            }
            $params = ['project_id' => $pid, 'return_format' => 'array', 'fields' => array_keys($spec['target']),
                       'exportDataAccessGroups' => true];
            if ($spec['event'] !== null) $params['events'] = [$spec['event']];
            if ($narrow) {
                $fl = self::collisionFilterLogic(array_keys($spec['target']), $spec['target']);
                if ($fl !== null) {
                    try {
                        $n = \REDCap::getData($params + ['filterLogic' => $fl]);
                        $hit = is_array($n) ? self::existsMatchIn($n, $spec, $scope, $dag) : null;
                        if ($hit !== null) return ['state' => 'found'] + $hit;
                    } catch (\Throwable $e) {
                        // a filter this build cannot run: the full read below decides
                    }
                }
            }
            if ($narrow && isset($opts['mayFullRead']) && is_callable($opts['mayFullRead']) && !call_user_func($opts['mayFullRead'])) {
                return $unknown + ['why' => 'too many lookups in the last minute'];
            }
            $data = \REDCap::getData($params);
            if (!is_array($data)) return $unknown;
            $hit = self::existsMatchIn($data, $spec, $scope, $dag);
            return $hit !== null ? ['state' => 'found'] + $hit : $this->existsMiss($pid, $scope, $opts, $dag);
        } catch (\Throwable $e) {
            return $unknown;
        }
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
        if ($scope === 'dag') {
            if (!$foreign || $this->readShows($pid, function ($g) use ($dag) { return $g === $dag; })) return $notFound;
            return ['state' => 'unknown', 'record' => null, 'dag' => null,
                    'why' => 'the other project showed no record of your Data Access Group there to this lookup'];
        }
        if ($callerDag === null && !$foreign) return $notFound;
        $seen = $this->readShows($pid, function ($g) use ($callerDag, $foreign) {
            return ($foreign && $callerDag === null) || $g !== $callerDag;
        });
        if ($seen) return $notFound;
        return ['state' => 'unknown', 'record' => null, 'dag' => null,
                'why' => $callerDag === null ? 'the other project showed no records to this lookup'
                       : 'a value saved in another Data Access Group may not be visible from yours'];
    }

    /**
     * Whether a read made by this request shows a record whose Data Access
     * Group (null = none) passes $want. One read of the record-ID field per
     * project, kept for the request. A failed read answers false: there is
     * then no evidence of what the lookup saw.
     */
    private function readShows($pid, callable $want)
    {
        if (!array_key_exists($pid, $this->groupVisibility)) {
            $seen = false;
            try {
                $pk = $this->recordIdFieldOf($pid);
                if ($pk !== null) {
                    $data = \REDCap::getData(['project_id' => $pid, 'return_format' => 'array', 'fields' => [$pk],
                                              'exportDataAccessGroups' => true]);
                    if (is_array($data)) {
                        $seen = [];
                        foreach ($data as $node) {
                            if (!is_array($node)) continue;
                            $g = self::dagOfRecordNode($node);
                            $seen[$g === null ? '' : 'g' . $g] = $g;
                        }
                    }
                }
            } catch (\Throwable $e) {
                $seen = false;
            }
            $this->groupVisibility[$pid] = $seen;
        }
        $seen = $this->groupVisibility[$pid];
        if ($seen === false) return false;
        foreach ($seen as $g) if ($want($g)) return true;
        return false;
    }

    /**
     * What one lookup compares: 'in', the 'target' field => value map (the
     * searched field plus the "match" targets), and the one event id to search
     * ('event', null = every event). Null when the event cannot be resolved or
     * a "match" value is blank.
     */
    private function existsSpec($pid, array $rule, $value, array $locals, $eventId)
    {
        $in = isset($rule['existsIn']) ? (string) $rule['existsIn'] : '';
        if ($in === '') return null;
        $target = [];
        if ($in !== 'record') $target[$in] = (string) $value;
        foreach ((isset($rule['existsMatch']) && is_array($rule['existsMatch'])) ? $rule['existsMatch'] : [] as $t => $l) {
            $lv = isset($locals[$l]) ? trim((string) $locals[$l]) : '';
            if ($lv === '') return null;
            $target[(string) $t] = $lv;
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
        return ['in' => $in, 'target' => $target, 'event' => $event];
    }

    /** The first entry of an exported data set that holds every target value, as ['record','dag'], or null. */
    private static function existsMatchIn(array $data, array $spec, $scope, $dag)
    {
        foreach ($data as $rec => $node) {
            if (!is_array($node)) continue;
            $rdag = self::dagOfRecordNode($node);
            if ($scope === 'dag' && $rdag !== $dag) continue;
            foreach (self::recordContexts($node) as $ctx) {
                if ($spec['event'] !== null && (string) $ctx['event_id'] !== (string) $spec['event']) continue;
                $row = $ctx['values'];
                $ok = true;
                foreach ($spec['target'] as $f => $tv) {
                    $rv = (isset($row[$f]) && !is_array($row[$f])) ? trim((string) $row[$f]) : '';
                    if ($rv !== $tv) { $ok = false; break; }
                }
                if ($ok) return ['record' => (string) $rec, 'dag' => $rdag];
            }
        }
        return null;
    }

    /**
     * The scan's answer: the searched fields of the whole project are read
     * ONCE per rule and request into a hash set, and each record is answered
     * from it. An index that cannot be read, or that would not fit in memory,
     * answers unknown for every record of the request.
     */
    private function existsIndexLookup($pid, array $rule, $value, array $locals, $eventId, $dag)
    {
        $unknown = ['state' => 'unknown', 'record' => null, 'dag' => null];
        $spec = $this->existsSpec($pid, $rule, $value, $locals, $eventId);
        if ($spec === null) return $unknown;
        $scope = isset($rule['existsScope']) ? $rule['existsScope'] : 'project';
        if ($scope === 'dag' && $dag === false) return $unknown;
        $fields = array_keys($spec['target']);
        $key = json_encode([(int) $pid, $spec['in'], $fields, isset($rule['existsEvent']) ? $rule['existsEvent'] : null]);
        $foreign = !empty($rule['existsPid']);
        if (!array_key_exists($key, $this->existsIndexes)) {
            // Another project's index costs one read there per request: it is
            // counted against that project's budget and leaves one line in its
            // module log.
            if ($foreign && $this->crossRateLimited($pid, false)) {
                $this->existsIndexes[$key] = false;
                $this->logCrossIndexRead($pid, $fields, 'throttled');
            } else {
                $this->existsIndexes[$key] = $this->buildExistsIndex($pid, $spec['in'], $fields,
                    !empty($rule['existsEvent']) ? $spec['event'] : null);
                if ($foreign) $this->logCrossIndexRead($pid, $fields, $this->existsIndexes[$key] === false ? 'failed' : 'read');
            }
        }
        $idx = $this->existsIndexes[$key];
        if ($idx === false) return $unknown;
        $k = implode("\x1f", array_values($spec['target']));
        if ($spec['in'] === 'record') $k = (string) $value;
        foreach (isset($idx[$k]) ? $idx[$k] : [] as $hit) {
            if ($scope === 'dag' && $hit[1] !== $dag) continue;
            if ($scope === 'event' && (string) $hit[2] !== (string) $spec['event']) continue;
            return ['state' => 'found', 'record' => $hit[0], 'dag' => $hit[1]];
        }
        if ($foreign && !$this->readShows($pid, function ($g) { return true; })) {
            return $unknown + ['why' => 'the other project showed no records to this scan'];
        }
        return ['state' => 'not-found', 'record' => null, 'dag' => null];
    }

    /**
     * key => [[record, dag, event_id], ...] for one searched field set, or false.
     * The key joins the target values in rule order with \x1f, which no stored
     * REDCap value contains; a record-ID index is keyed by the record ID.
     * One hit is kept per (DAG, event) under a key - all a lookup ever asks -
     * so a value repeated in many records costs one entry, not a list that
     * every later record has to be compared against.
     */
    private function buildExistsIndex($pid, $in, array $fields, $event)
    {
        try {
            $limit = self::memoryLimitBytes();
            if ($in === 'record') {
                $pk = $this->recordIdFieldOf($pid);
                if ($pk === null) return false;
                $data = \REDCap::getData(['project_id' => $pid, 'return_format' => 'array', 'fields' => [$pk],
                                          'exportDataAccessGroups' => true]);
                if (!is_array($data)) return false;
                $idx = [];
                foreach ($data as $rec => $node) {
                    if (is_array($node)) $idx[(string) $rec] = [[(string) $rec, self::dagOfRecordNode($node), null]];
                }
                return $idx;
            }
            $params = ['project_id' => $pid, 'return_format' => 'array', 'fields' => $fields, 'exportDataAccessGroups' => true];
            if ($event !== null) $params['events'] = [$event];
            $data = \REDCap::getData($params);
            if (!is_array($data)) return false;
            $idx = [];
            foreach ($data as $rec => $node) {
                if (!is_array($node)) continue;
                $rdag = self::dagOfRecordNode($node);
                foreach (self::recordContexts($node) as $ctx) {
                    if ($event !== null && (string) $ctx['event_id'] !== (string) $event) continue;
                    $parts = [];
                    foreach ($fields as $f) {
                        $v = (isset($ctx['values'][$f]) && !is_array($ctx['values'][$f])) ? trim((string) $ctx['values'][$f]) : '';
                        if ($v === '') continue 2;   // an entry with a blank searched field holds nothing to find
                        $parts[] = $v;
                    }
                    $k = implode("\x1f", $parts);
                    $slot = ($rdag === null ? '' : 'g' . $rdag) . "\x1e" . (string) $ctx['event_id'];
                    if (!isset($idx[$k][$slot])) $idx[$k][$slot] = [(string) $rec, $rdag, (string) $ctx['event_id']];
                }
                if ($limit > 0 && memory_get_usage(true) >= (int) ($limit * 0.6)) return false;
            }
            return $idx;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /** The record-ID field of a project: REDCap's first dictionary field. */
    private function recordIdFieldOf($pid)
    {
        $dd = $this->dataDictionary($pid);
        if (is_array($dd) && $dd) {
            reset($dd);
            return (string) key($dd);
        }
        return null;
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
        . 'list this project, with every field this lookup searches, under "Projects that may look up values here" '
        . 'in its module settings.';

    /** @var array "a|b" => consent row or null, per request */
    private $crossConsents = [];
    /** @var array pid => \Project or null, per request */
    private $projectObjects = [];
    /** @var array pid => userRightsIn() answer, per request */
    private $rightsIn = [];

    /** Whether an administrator allowed lookups in other projects on this server. */
    private function crossProjectOn()
    {
        try {
            return in_array($this->getSystemSetting('exists-system-cross-project'), [true, 1, '1', 'true'], true);
        } catch (\Throwable $e) {
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
            // A deleted project keeps its module settings; it is not a project to search.
            if (is_callable([$this, 'getProjectStatus']) && $this->getProjectStatus((int) $pid) === null) return false;
            return true;
        } catch (\Throwable $e) {
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
     * answers count only with the "answer" mode.
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
        return ['project' => (int) $p, 'mode' => $mode, 'targets' => $targets, 'surveys' => $surveys && $mode === 'answer'];
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
     * searches, or null (one answer for every reason).
     */
    private function crossGate($pid, array $rule)
    {
        $c = $this->crossConsent($pid, isset($rule['existsPid']) ? (int) $rule['existsPid'] : 0);
        if ($c === null) return null;
        foreach (self::crossFields($rule) as $f) {
            if (!isset($c['targets'][$f])) return null;
        }
        return $c;
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
            $out = null;
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
            }
        }
        return $this->projectObjects[$pid];
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
            }
        } catch (\Throwable $e) {
        }
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
        $touch = [];
        foreach (self::crossFields($rule) as $f) {
            if ($f === 'record') {
                $pk = $this->recordIdFieldOf($b);
                if ($pk === null) return 'record';
                $f = $pk;
            }
            $touch[] = $f;
        }
        if ($ids === null) return $touch ? $touch[0] : 'record';
        return self::firstIdentifier($ids, $touch);
    }

    /**
     * The first field the rule searches whose form, in the other project, the
     * user may not open; null when they may open them all. A record-ID lookup
     * needs no form. Fields the dictionary cannot place count as closed.
     */
    private function crossUnreadable($b, array $rule, $forms)
    {
        $dd = $this->dataDictionary($b);
        foreach (self::crossFields($rule) as $f) {
            if ($f === 'record') continue;
            $form = (is_array($dd) && isset($dd[$f]['form_name'])) ? (string) $dd[$f]['form_name'] : '';
            if ($form === '' || !self::mayReadForm($forms, $form)) return $f;
        }
        return null;
    }

    /**
     * Whether a lookup into project $b is over its budget. Two windows: one per
     * signed-in session and searched project ($perSession; a session its
     * holder cannot shed without signing out), and one per searched project
     * for every caller together (tier 3 of the rate buckets). FAILS CLOSED,
     * unlike the survey throttle: a read of another project that cannot be
     * counted is not made, and "could not check" never blocks a save.
     */
    private function crossRateLimited($b, $perSession)
    {
        try {
            $now = time();
            if ($perSession && function_exists('session_status') && session_status() === PHP_SESSION_ACTIVE) {
                $key = 'uvalidate_cross_hits_' . (int) $b;
                $hits = (isset($_SESSION[$key]) && is_array($_SESSION[$key])) ? $_SESSION[$key] : [];
                $hits = array_values(array_filter($hits, function ($t) use ($now) {
                    return is_int($t) && ($now - $t) < 60;
                }));
                if (count($hits) >= $this->systemCount('exists-system-cross-user-per-minute', self::CROSS_USER_PER_MINUTE)) {
                    $_SESSION[$key] = $hits;
                    return true;
                }
                $hits[] = $now;
                $_SESSION[$key] = $hits;
            }
            $db = new Scan\ModuleDb($this);
            $bucket = ((int) floor($now / 60)) * self::RATE_TIERS + 3;
            $db->exec('INSERT INTO ' . Scan\Schema::table('rate_bucket') . '
                (project_id, bucket, hits) VALUES (?, ?, LAST_INSERT_ID(1))
                ON DUPLICATE KEY UPDATE hits = LAST_INSERT_ID(hits + 1)',
                [(int) $b, $bucket]);
            $r = $db->select('SELECT LAST_INSERT_ID()', []);
            if (!isset($r[0][0]) || $r[0][0] === null) return true;
            $hits = (int) $r[0][0];
            if ($hits === 1) {
                $db->exec('DELETE FROM ' . Scan\Schema::table('rate_bucket')
                    . ' WHERE project_id = ? AND bucket < ?', [(int) $b, $bucket - 2 * self::RATE_TIERS]);
            }
            return $hits > $this->systemCount('exists-system-cross-project-per-minute', self::CROSS_PROJECT_PER_MINUTE);
        } catch (\Throwable $e) {
            return true;
        }
    }

    /**
     * One line in the searched project's module log for a lookup into it from
     * another project, answered or refused: the asking project, the channel
     * (staff, survey, audit), the user ("survey" for a respondent), the field
     * searched, the value as a keyed hash under the searched project's key
     * (left out when that project logs no values: "none" or "off"), and the
     * result. A cross-project value is never logged raw.
     */
    private function logCrossProbe($b, $a, $channel, array $rule, $value, $result)
    {
        try {
            $mode = $this->logMode($b);
            $user = $this->currentUsername();
            $entry = [
                'project_id'     => (int) $b,
                'source_project' => (string) (int) $a,
                'channel'        => (string) $channel,
                'user'           => $user !== null ? $user : 'survey',
                'field'          => implode(',', self::crossFields($rule)),
                'result'         => (string) $result,
            ];
            if ($mode !== 'none' && $mode !== 'off' && $value !== null && trim((string) $value) !== '') {
                $h = $this->hashedIdentifier($b, trim((string) $value));
                if ($h !== null) $entry['value_hash'] = $h;
            }
            $this->log('uv-exists-probe', $entry);
        } catch (\Throwable $e) {
        }
    }

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
            $ruleB['existsScope'] = 'dag';
            $dag = $confine;
        }
        if ($scan) {
            // The scan's plan already refused a user confined there (scanPlan).
            if ($confine !== null || $callerDag !== null) return $unknown('your account is in a Data Access Group of the other project');
            $this->crossSource = (int) $pid;
            $r = $this->existsIndexLookup($b, $ruleB, $value, $locals, null, null);
        } else {
            if ($this->crossRateLimited($b, true)) {
                $this->logCrossProbe($b, $pid, $channel, $rule, $value, 'throttled');
                return $unknown('too many lookups in the other project in the last minute');
            }
            $r = $this->findExisting($b, $ruleB, $value, $locals, null, $dag, true,
                ['foreign' => true, 'callerDag' => $callerDag] + $opts);
            $this->logCrossProbe($b, $pid, $channel, $rule, $value, $r['state']);
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
        if ($pk === null) return false;
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
     */
    private function findingsRequired(array $rule, $type, array $values, array $dupes, $onForm, $project_id, $record, $event_id, array $resolution)
    {
        $out = ['invalid' => [], 'unconfigurable' => []];
        foreach ($rule['fields'] as $field) {
            if (isset($dupes[$field])) continue;
            if ($onForm !== null && !isset($onForm[$field])) continue;
            $value = isset($values[$field]) ? $values[$field] : null;
            if (is_array($value)) continue; // non-scalar (checkbox map) — not a required target
            if ($value === null || trim((string) $value) === '') {
                $out['invalid'][] = ['field' => $field, 'value' => '', 'algo' => 'required', 'type' => 'required', 'reason' => 'required-blank'];
            }
        }
        return $out;
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
            if (!Logic::evaluate($a['ast'], $values, Logic::BLANK_PASSES, !empty($rule['caseSensitive']))) {
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
    private function findingsWindow(array $rule, $type, array $values, array $dupes, $onForm, $project_id, $record, $event_id, array $resolution)
    {
        $out = ['invalid' => [], 'unconfigurable' => []];
        $anchor = null;
        if (isset($rule['windowFrom']) && is_string($rule['windowFrom']) && $rule['windowFrom'] !== '') {
            if (array_key_exists('windowFromValue', $rule)) {
                // Compiled on the extended (event/instance) path, which resolved it.
                $anchor = $rule['windowFromValue'];
                if (!is_string($anchor)) {
                    $out['unconfigurable'][] = ['fields' => $rule['fields'],
                        'why' => 'the "from" date ' . $rule['windowFrom'] . ' could not be resolved — field skipped'];
                    return $out;
                }
            } else {
                $op = ModeRegistry::operandRef($rule['windowFrom']);
                if ($op === null) {
                    $out['unconfigurable'][] = ['fields' => $rule['fields'], 'why' => 'the "from" date cannot be evaluated — field skipped'];
                    return $out;
                }
                $state = isset($resolution[$op[1]]) ? $resolution[$op[1]] : 'ok';
                if ($state !== 'ok') {
                    $out['unconfigurable'][] = ['fields' => $rule['fields'],
                        'why' => 'the "from" date ' . self::resolutionProblem($state, $op[1])];
                    return $out;
                }
                $v = isset($values[$op[1]]) ? $values[$op[1]] : '';
                $anchor = is_array($v) ? '' : (string) $v;
            }
        }
        $spec = [
            'lo' => isset($rule['windowLo']) ? $rule['windowLo'] : null,
            'hi' => isset($rule['windowHi']) ? $rule['windowHi'] : null,
            'unit' => isset($rule['windowUnit']) ? $rule['windowUnit'] : 'days',
            'notFuture' => !empty($rule['windowNotFuture']),
            'type' => isset($rule['dateType']) ? $rule['dateType'] : null,
            'fromType' => isset($rule['fromType']) ? $rule['fromType'] : null,
        ];
        $clock = $spec['notFuture'] ? $this->serverClock($project_id) : null;
        // The extended path marks a "from" that resolved to the field itself, in
        // this same entry ([baseline_arm_1][visit_date] saved on the baseline
        // visit): no window applies there.
        $self = isset($rule['windowFromValueSelf']) ? $rule['windowFromValueSelf'] : null;
        foreach ($rule['fields'] as $field) {
            if (isset($dupes[$field])) continue;
            if ($onForm !== null && !isset($onForm[$field])) continue;
            $value = isset($values[$field]) ? $values[$field] : null;
            if ($value === null || is_array($value)) continue;
            $r = TemporalLogic::windowVerdict($spec, (string) $value, 'ymd', $self === $field ? '' : $anchor, 'ymd', $clock);
            if ($r['verdict'] === 'unknown') {
                $out['unconfigurable'][] = ['fields' => [$field],
                    'why' => 'the saved date or its "from" date is not a date this rule can read — field skipped'];
                continue;
            }
            if ($r['verdict'] === 'ok' || $r['verdict'] === 'inert') continue;
            $out['invalid'][] = ['field' => $field, 'value' => $value, 'algo' => 'window', 'type' => 'window',
                                 'reason' => $r['verdict']];
        }
        return $out;
    }

    /** @var array|null a pinned clock (tests); null reads the real one */
    private $clockOverride = null;
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
            $this->clockMemo[$key] = ['today' => $now->format('Y-m-d'), 'now' => $now->format('Y-m-d H:i:s')];
        }
        return $this->clockMemo[$key];
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
        $unconfigurable = [];
        foreach ($rule['fields'] as $field) {
            if (isset($dupes[$field])) continue;
            if ($onForm !== null && !isset($onForm[$field])) continue;
            $value = isset($values[$field]) ? $values[$field] : null;
            if ($value === null || $value === '') continue;
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
            } elseif (empty($res['ok'])) {
                $out['invalid'][] = ['field' => $field, 'value' => $value, 'algo' => $algo, 'type' => $type,
                                     'reason' => isset($res['reason']) ? $res['reason'] : ''];
            }
        }
        if ($unconfigurable) {
            $out['unconfigurable'][] = ['fields' => $unconfigurable, 'why' => 'rule cannot be evaluated server-side (unsafe or uncompilable configuration)'];
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
    private function logInvalid($mode, $pid, $record, $field, $value, $algo, $type, $instrument, $event_id, $repeat_instance, $reason)
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
        $this->log('invalid-id-saved', $entry);
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
            $config['rules'] = self::encodableRules($config['rules'], $flags);
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
     * configuration and ASCII by validation.
     */
    private static function encodableRules(array $rules, $flags)
    {
        foreach ($rules as $i => $rule) {
            if (!is_array($rule) || json_encode($rule, $flags) !== false) continue;
            $rules[$i] = [
                'type' => isset($rule['type']) && is_string($rule['type']) ? $rule['type'] : 'single',
                'fields' => array_values(array_filter(
                    isset($rule['fields']) && is_array($rule['fields']) ? $rule['fields'] : [],
                    function ($f) { return is_string($f) && preg_match('/^[a-z][a-z0-9_]*$/D', $f); }
                )),
                'deferred' => true,
                'deferredWhy' => ['A saved value this rule reads is not valid UTF-8 text, so the rule cannot run in the browser.'],
                'blockSave' => 'off',
            ];
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
        $rules = $this->rulesOnInstrument($rules, $pid, $instrument);
        $config = array_merge($this->defaults(), [
            'singleFields' => [],
            'pooledFields' => [],
            'context'      => $context,
            'rules'        => $rules,
        ]);

        $config['rules'] = $this->foldTemporalRules($rules, $pid, $record, $instrument, $event_id, $repeat_instance, $context);
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
        return $config;
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
        // 1 => case-sensitive]. Two rules may share a condition string and
        // differ only in "caseSensitive", and an off-page comparison settled to
        // a constant under one mode is not the constant the other would produce.
        $folded = [];
        $foldedAssert = [];
        $frozen = [];   // condition text => a live side had to be given up
        $blocked = [];  // condition text => field => why it could not be resolved
        $snapshot = []; // condition text => off-page fields baked at render time
        foreach ($asts as $w => $ast) {
            $f = false;
            $b = [];
            $sn = [];
            $folded[$w] = [];
            $folded[$w][0] = Logic::fold($ast, $values, $live, $disclosable, $f, $unresolved, $b, $sn, Logic::BLANK_INERT, false);
            // Freshness diagnostics come from reference resolution and liveness
            // alone, never from a verdict, so the pass above already recorded
            // them; this one discards its own.
            $fCs = false;
            $bCs = [];
            $snCs = [];
            $folded[$w][1] = Logic::fold($ast, $values, $live, $disclosable, $fCs, $unresolved, $bCs, $snCs, Logic::BLANK_INERT, true);
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
            foreach ([0, 1] as $cs) {
                $f2 = false;
                $b2 = [];
                $sn2 = [];
                $foldedAssert[$w][$cs] = Logic::fold($ast, $values, $live, $disclosable, $f2, $unresolved, $b2, $sn2, Logic::BLANK_PASSES, (bool) $cs);
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
        //                      ("notFuture"); the browser says the rest is
        //                      checked after saving.
        $foldOperand = function ($text) use ($live, $values, $disclosable, $unresolved, $unknownForm) {
            $out = ['op' => null, 'deferred' => true, 'why' => [], 'snapshot' => []];
            $op = ModeRegistry::operandRef($text);
            if ($op === null) return $out;
            $f = (string) $op[1];
            if (isset($unresolved[$f])) {
                $out['why'][$f . '|' . $unresolved[$f]] = self::resolutionProblem($unresolved[$f], $f);
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
            if (isset($r['when']) && isset($folded[$r['when']])) {
                $rules[$i]['whenAst'] = $folded[$r['when']][empty($r['caseSensitive']) ? 0 : 1];
                // An unresolvable "when" gates on a value we never read, so the
                // rule must not act on it either.
                if (!empty($blocked[$r['when']])) { $rules[$i]['deferred'] = true; $noteFor($i, $r['when']); }
                // A gate that had to give up a live side is stale in the same way
                // an assert is: defer rather than gate on page-load truth.
                if (!empty($frozen[$r['when']])) $rules[$i]['deferred'] = true;
                foreach (isset($snapshot[$r['when']]) ? $snapshot[$r['when']] : [] as $sf => $_) $snapFields[$sf] = true;
            }
            // The TEST conditions ("assert", and any a mode declares with role
            // "test" in php/modes.json) fold with the test polarity.
            foreach (ModeRegistry::refKeys('cond', 'test') as $rk) {
                $tk = $rk['key'];
                if (!isset($r[$tk]) || !isset($folded[$r[$tk]])) continue;
                $rules[$i][$rk['ast']] = $foldedAssert[$r[$tk]][empty($r['caseSensitive']) ? 0 : 1];
                // A frozen TEST must never block: its verdict is stale the
                // moment the user types, and the post-save audit re-checks it.
                if (!empty($frozen[$r[$tk]])) $rules[$i]['deferred'] = true;
                if (!empty($blocked[$r[$tk]])) $noteFor($i, $r[$tk]);
                foreach (isset($snapshot[$r[$tk]]) ? $snapshot[$r[$tk]] : [] as $sf => $_) $snapFields[$sf] = true;
            }
            foreach (ModeRegistry::operandKeys() as $rk) {
                if (!isset($r[$rk['key']])) continue;
                $fo = $foldOperand($r[$rk['key']]);
                if ($fo['op'] !== null) $rules[$i][$rk['op']] = $fo['op'];
                if ($fo['deferred']) $rules[$i]['deferred'] = true;
                foreach ($fo['why'] as $wk => $wt) $notes[$i][$wk] = $wt;
                foreach ($fo['snapshot'] as $sf => $_) $snapFields[$sf] = true;
            }
            if ($snapFields) $rules[$i]['snapshotFields'] = array_keys($snapFields);
            if (isset($r['branches']) && is_array($r['branches'])) {
                // An unresolved SELECTOR makes the branch decision undecidable, and
                // the client would otherwise fall through to the fallback branch and
                // enforce it (H-01). Mark EVERY branch deferred, not just the blocked
                // one, so no branch can be selected and enforced on this page.
                $selectorBlocked = [];
                // A SELECTOR settled on the server picks the branch from page-load
                // truth. Whichever branch that turns out to be, its verdict rests
                // on a value that may already have changed, so the staleness
                // belongs to the whole rule — every branch is marked, because only
                // the selected one's BLOCK setting is ever consulted (H-01).
                $selectorSnapshot = [];
                foreach ($r['branches'] as $bi => $b) {
                    if (!isset($b['when']) || $b['when'] === '') continue;
                    if (!empty($blocked[$b['when']])) {
                        foreach ($blocked[$b['when']] as $bf => $bstate) $selectorBlocked[$bf] = $bstate;
                    }
                    foreach (isset($snapshot[$b['when']]) ? $snapshot[$b['when']] : [] as $sf => $_) $selectorSnapshot[$sf] = true;
                }
                foreach ($r['branches'] as $bi => $b) {
                    $bWhy = [];
                    $bSnap = $selectorSnapshot;
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
                        $rules[$i]['branches'][$bi][$rk['ast']] = $foldedAssert[$b[$tk]][empty($b['caseSensitive']) ? 0 : 1];
                        if (!empty($frozen[$b[$tk]])) $rules[$i]['branches'][$bi]['deferred'] = true;
                        if (!empty($blocked[$b[$tk]])) {
                            $noteFor($i, $b[$tk]);
                            foreach ($blocked[$b[$tk]] as $bf => $bs) $bWhy[$bf . '|' . $bs] = self::resolutionProblem($bs, $bf);
                        }
                        foreach (isset($snapshot[$b[$tk]]) ? $snapshot[$b[$tk]] : [] as $sf => $_) $bSnap[$sf] = true;
                    }
                    foreach (ModeRegistry::operandKeys() as $rk) {
                        if (!isset($b[$rk['key']])) continue;
                        $fo = $foldOperand($b[$rk['key']]);
                        if ($fo['op'] !== null) $rules[$i]['branches'][$bi][$rk['op']] = $fo['op'];
                        if ($fo['deferred']) $rules[$i]['branches'][$bi]['deferred'] = true;
                        foreach ($fo['why'] as $wk => $wt) { $bWhy[$wk] = $wt; $notes[$i][$wk] = $wt; }
                        foreach ($fo['snapshot'] as $sf => $_) $bSnap[$sf] = true;
                    }
                    // M-01: branch configs never inherit rule-level keys on the
                    // client, so a branch's snapshot/deferral diagnostics have to
                    // be written ONTO the branch or they are silently dropped.
                    if ($bSnap) $rules[$i]['branches'][$bi]['snapshotFields'] = array_keys($bSnap);
                    if ($selectorBlocked) {
                        $rules[$i]['branches'][$bi]['deferred'] = true;
                        foreach ($selectorBlocked as $bf => $bs) $bWhy[$bf . '|' . $bs] = self::resolutionProblem($bs, $bf);
                    }
                    if ($bWhy) $rules[$i]['branches'][$bi]['deferredWhy'] = array_values($bWhy);
                }
                if ($selectorBlocked) {
                    $rules[$i]['deferred'] = true;
                    foreach ($selectorBlocked as $bf => $bs) {
                        $notes[$i][$bf . '|' . $bs] = self::resolutionProblem($bs, $bf)
                            . ' No branch can be chosen, so the field is not checked here.';
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
                // REDCap form rights: 0 = no access. 1 view/edit, 2 read-only,
                // 3 edit survey responses all imply the user can read the form.
                if (!$allForms && (string) $forms[$form] === '0') continue;
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
        return (string) $rights[$form] !== '0';
    }

    /**
     * All active rules, from BOTH configuration channels:
     *   1. the repeatable "rules" project settings (module Configure dialog),
     *   2. @UVALIDATE field annotations (Online Designer / data dictionary CSV).
     * A field claimed by more than one rule gets a duplicate-rule config error on
     * the client, so the two channels cannot silently fight over a field.
     */
    /** @var array|null per-request memo: pid => resolved rule list */
    private $rulesMemo = [];

    private function getRules($pid = null)
    {
        // Memoized per request. A finding cites a rule by ORDINAL, and the
        // report resolves that ordinal against getRules() again; unmemoized,
        // those were two independent reads, and anything that changed the rule
        // list between them shifted every ordinal so that every label, message
        // and assertion in the report attached to the wrong rule with nothing to
        // detect it. Stable rule identity is Tasks 5-6; this closes the window
        // in the meantime.
        $key = (string) ($pid === null ? '' : $pid) . '|qualified=' . ($this->temporalOptions($pid)['qualified'] ? '1' : '0');
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
        foreach ($this->getSettingRules($pid) as $r)    { $r['_origin'] = 'settings';   $out[] = $r; }
        foreach ($this->getAnnotationRules($pid) as $r) { $r['_origin'] = 'annotation'; $out[] = $r; }
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
                if (!isset($extendedShape)) $extendedShape = TemporalMetadata::load($pid, $dd);
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
        $resolved = Branching::resolve($out);
        if ($this->dataDictionary($pid)) $this->rulesMemo[$key] = $resolved;
        return $resolved;
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

        foreach ($subs as $s) {
            $rule = $this->settingRowToRule(is_array($s) ? $s : [], $known, $types, $choices, $identifiers, $this->temporalOptions($pid));
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

    private function settingRowToRule(array $s, $known, $types, $choices = null, $identifiers = null, array $opts = [])
    {
        // Stored settings can hold surprising shapes after upgrades or manual
        // edits; for these keys only scalars are meaningful — discard anything
        // else instead of warning or letting it reach the engine.
        foreach (['rule-type', 'fields-csv', 'when', 'case-sensitive', 'assert', 'message',
                  'unique-with', 'unique-scope', 'unique-surveys', 'algorithm', 'source',
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
            if ($mode === 'unique') {
                if (isset($s['unique-with']) && trim((string) $s['unique-with']) !== '') {
                    $rule['uniqueWith'] = array_map('strtolower',
                        preg_split('/[,;\s]+/', trim((string) $s['unique-with']), -1, PREG_SPLIT_NO_EMPTY));
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
                        foreach (Logic::checkRefs($w['ast'], $types, is_array($choices) ? $choices : []) as $e) {
                            $errors[] = $e;
                        }
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
                // The Identifier refusal covers the primary field(s) AND the
                // composite "with" fields (H-01) — an identifying value anywhere in
                // the uniqueness key makes the survey answer an existence oracle.
                $withF = (isset($rule['uniqueWith']) && is_array($rule['uniqueWith'])) ? $rule['uniqueWith'] : [];
                $idField = self::firstIdentifier($identifiers, array_merge($fields, $withF));
                if ($idField !== null) $errors[] = 'field "' . $idField . '": ' . self::SURVEY_ON_IDENTIFIER;
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
                foreach (Logic::checkRefs($w['ast'], $types, is_array($choices) ? $choices : []) as $e) {
                    $errors[] = $e;
                }
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
            $wasEnabled = $this->temporalOptions($pid)['qualified'];
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
                $rule = $this->settingRowToRule($row, $known, $types, $choices, $identifiers, ['qualified' => $parseExtended]);
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
     * Reassemble per-rule rows from the flat key => [per-instance values] shape
     * validateSettings() receives for repeatable sub-settings.
     */
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
        $col = function ($k) use ($settings) {
            return (isset($settings[$k]) && is_array($settings[$k])) ? array_values($settings[$k]) : [];
        };
        $isPid = function ($v) { return preg_match('/^[1-9][0-9]{0,9}$/', trim((string) $v)) === 1; };
        $errors = [];
        $aliases = $col('exists-alias');
        $aliasPids = $col('exists-alias-project');
        $seen = [];
        for ($i = 0, $n = max(count($aliases), count($aliasPids)); $i < $n; $i++) {
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
        $modes = $col('exists-consumer-mode');
        $surveys = $col('exists-consumer-surveys');
        $seen = [];
        for ($i = 0, $n = max(count($projects), count($targets), count($modes), count($surveys)); $i < $n; $i++) {
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
                $errors[] = $where . 'list the fields that project may search, separated by commas ("record" for the record ID).';
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
        }
        return $errors;
    }

    private static function rowsFromFlatSettings(array $settings)
    {
        $keys = ['references-json', 'rule-note', 'rule-type', 'fields', 'fields-csv', 'when', 'case-sensitive', 'assert', 'message',
                 'unique-with', 'unique-scope', 'unique-surveys',
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
    private function getAnnotationRules($pid = null)
    {
        $dd = $this->dataDictionary($pid);
        if (!$dd) return [];
        $perField = [];
        foreach ($dd as $name => $meta) {
            $ann = isset($meta['field_annotation']) ? (string) $meta['field_annotation'] : '';
            // Cheap pre-filter: every module tag starts with "@UV" (@UVALIDATE,
            // @UVASSERT, …). parseAllTags then finds the real, boundary-checked ones.
            if ($ann === '' || stripos($ann, '@UV') === false) continue;
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
                if ($hook !== null) $frags[$k] = $this->$hook($frag, $name, $meta, $pid);
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
                    $errs = Logic::checkRefs($w['ast'], $types === null ? [] : $types, $choices === null ? [] : $choices);
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
                $perField[$name][$k] = $this->$hook($frag, $name, $types, $choices, $pid);
            }
        }
        return AnnotationRules::groupMulti($perField);
    }

    /**
     * @UVUNIQUE "field" hook: refuse the survey opt-in when the primary field
     * OR any composite "with" field is an Identifier (H-01), naming the
     * offending field so a composite hit is not mistaken for the primary one.
     */
    private function annotateUniqueField(array $frag, $name, array $meta, $pid)
    {
        if (empty($frag['uniqueSurveys'])) return $frag;
        $withF = (isset($frag['uniqueWith']) && is_array($frag['uniqueWith'])) ? $frag['uniqueWith'] : [];
        $idField = self::firstIdentifier($this->projectIdentifierFields($pid), array_merge([$name], $withF));
        if ($idField === null) return $frag;
        return ['error' => ($idField === $name ? '' : 'composite "with" field "' . $idField . '": ')
            . self::SURVEY_ON_IDENTIFIER, '_tag' => AnnotationRules::TAG_UNIQUE];
    }

    /**
     * @UVUNIQUE "dictionary" hook: composite-key fields must exist and hold ONE
     * scalar value (checkbox is multi-valued; file/descriptive have no
     * comparable value), and "with" naming the field itself is a tautology,
     * not a composite.
     */
    private function annotateUniqueDictionary(array $frag, $name, $types, $choices, $pid = null)
    {
        if (!isset($frag['uniqueWith'])) return $frag;
        $errs = self::checkUniqueWith($frag['uniqueWith'], $name, $types);
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
            if ($pk !== null) $touch[] = $pk;
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
     * same kind of date (or both no date: REDCap stores dates as Y-M-D and the
     * comparison is exact), and "event" must name an event of the project.
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
        foreach ($pairs as list($a, $b)) {
            if ($family($a) !== $family($b)) {
                return $refuse('"' . $a . '" holds ' . $family($a) . ' and "' . $b . '" holds ' . $family($b)
                    . ' — values are compared exactly, so both must be the same kind.');
            }
        }
        if (isset($frag['existsEvent'])) {
            if ($this->eventIdOf($pid, $frag['existsEvent']) === null) {
                return $refuse('"event" "' . $frag['existsEvent'] . '" is not an event of this project — use its unique event name.');
            }
        }
        return $frag;
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
     * no searched field is an Identifier there. The resolved project id is
     * kept on the rule as existsPid.
     */
    private function annotateExistsRemote(array $frag, $name, $types, $pid)
    {
        $refuse = function ($why) { return ['error' => $why, '_tag' => AnnotationRules::TAG_EXISTS]; };
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
                    . $kindA($here) . ' — values are compared exactly, so both must be the same kind.');
            }
        }
        if (isset($frag['existsEvent']) && $this->eventIdIn($b, $frag['existsEvent']) === null) {
            return $refuse('"event" "' . $frag['existsEvent'] . '" is not an event of project ' . $b . ' — use its unique event name.');
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
                . 'so use days or weeks.', '_tag' => AnnotationRules::TAG_WINDOW];
        }
        $frag['dateType'] = $tv['type'];
        $frag['dateFormat'] = $tv['format'];
        return $frag;
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
    private function rulesOnInstrument(array $rules, $pid, $instrument)
    {
        $onForm = $this->fieldsOnInstrument($pid, $instrument);
        if ($onForm === null) return $rules;   // cannot identify the form's fields: leave the set as-is
        $out = [];
        foreach ($rules as $r) {
            if (!empty($r['configError'])) { $out[] = $r; continue; }   // keep config-error notices
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
            // Additive: raising the limit adds rows here on the next save;
            // lowering it never deletes one, because a row being deleted may be
            // leased right now.
            $limitKey = 'scan-system-max-concurrent-projects';
            // Through ScanPolicy so an unset or malformed setting lands on the
            // documented default rather than on zero, which would provision
            // nothing and reproduce the very fault this fixes.
            $policy = Scan\ScanPolicy::resolve(
                [$limitKey => $this->getSystemSetting($limitKey)], []);
            $slots = new Scan\WorkerSlots(new Scan\ModuleDb($this));
            $added = $slots->provision($policy['maxProjects']);
            $census = $slots->census();
            $this->log('scan-slots-provision', [
                'limit' => (int) $policy['maxProjects'],
                'added' => (int) $added,
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

    /**
     * Run every configured rule over EVERY saved record — the retrospective
     * sweep the per-save audit cannot give you: legacy data, Data Import Tool
     * and API writes (whose save-hook coverage is version-dependent), and
     * records entered before a rule existed.
     *
     * Reads records in CHUNKS (memory-safe on large projects) and evaluates
     * each record/event/instance context through ruleFindings() — the same
     * dispatch the save-hook audit uses, so the two can never disagree.
     * Unique rules are handled in ONE aggregate pass over the scanned data
     * (grouping by value + composite key + scope) instead of a whole-project
     * read per record.
     *
     * $dagFilter: a DAG GROUP ID — only records in that group are scanned (pass
     * ScanPageView::scanScope()['dag'], which produces it, so a DAG-bound user
     * never sees other groups' record ids). null scans everything. It USED to
     * be the unique name, and the module now has exactly one DAG axis: the id.
     * The name is derived from it internally, because this path's record groups
     * come from the export, which reports names. A group id that cannot be
     * resolved to a name refuses the scan rather than scanning unconfined.
     *
     * Returns ['violations' => [ ['record','event_id','instance','field',
     * 'type','reason','rule' => 1-based index], ... ], 'unconfigurable' =>
     * [ ['rule','fields','why'], ... ] (deduplicated), 'stats' => [...]].
     * Values are returned per the scan-value-storage project setting: shown,
     * redacted for fields REDCap marks as an Identifier, or withheld entirely.
     * Redaction fails CLOSED — an unreadable dictionary withholds everything,
     * because a dictionary that cannot be read cannot clear a field.
*/
    public function scanProject($pid, $dagFilter = null, $chunkSize = 200, ?FindingSink $sink = null, array $opts = [])
    {
        // 'status' is part of the contract: a scan that could not read everything
        // must never be presentable as a clean bill of health. 'complete' is
        // only ever set at the very end of the full path.
        $result = ['violations' => [], 'unconfigurable' => [], 'incomplete' => [],
                   'status' => 'failed',
                   'stats' => ['records' => 0, 'contexts' => 0, 'rules' => 0, 'violations' => 0]];

        // Violations are the only channel that grows with the DATA, so they are
        // the only thing handed out as they are found. Without a sink the
        // findings are collected here and returned, which is what every caller
        // before 1.7.0 expected and what every test still asserts against.
        $collect = ($sink === null);
        if ($collect) $sink = new ArrayFindingSink();

        // ONE AXIS FOR THE WHOLE MODULE, AND THIS IS THE CONVERSION.
        //
        // $dagFilter now arrives as the numeric group id, because that is what
        // ScanPageView::scanScope() produces and what every durable consumer
        // compares on. This path cannot compare ids: its record groups come from
        // \REDCap::getData(exportDataAccessGroups => true), whose
        // redcap_data_access_group field is the DAG's unique NAME. So the id is
        // resolved back to a name HERE, once, through the same resolver the page
        // uses - rather than leaving a second parameter on a second axis for
        // somebody to fill from the wrong producer. Verified: with the page
        // returning the id and this resolution absent, five checks in
        // tests/scan_page_php.php go red, all of them a scan that listed zero
        // records because it compared 'north' against '7'.
        //
        // AN UNRESOLVABLE GROUP REFUSES, exactly as scanScope() does. Scanning
        // on with a name we could not read means matching no record, and this
        // method's own H-10 note records what that produced: a green tick over
        // "Scanned 0 record(s)".
        $dagName = null;
        if ($dagFilter !== null && $dagFilter !== '') {
            $dagName = ScanPageView::dagNameOf($dagFilter);
            if ($dagName === null) {
                $result['incomplete'][] = 'the Data Access Group this scan was confined to could '
                    . 'not be resolved, so no record could be placed inside or outside it and '
                    . 'nothing was examined';
                return $result;                  // status stays 'failed'
            }
        }

        $plan = $this->scanPlan($pid, $opts, $dagFilter);
        if ($plan['fatal'] !== null) {
            $result['incomplete'][] = $plan['fatal'];
            return $result;                  // status stays 'failed'
        }
        $result['stats']['rules'] = count($plan['live']);
        // Attach the config-error notices NOW so EVERY subsequent return carries them
        // — the empty-idData and no-records early returns below would otherwise drop
        // them, reporting a clean project when a rule is actually inert (UV-1553-01,
        // the M-05 silent-failure the feature exists to prevent). The final assignment
        // on the full path re-attaches $unconf with any runtime additions.
        $unconf = $plan['unconf'];
        $result['unconfigurable'] = array_values($unconf);
        if ($plan['nothingToScan']) {
            $result['status'] = 'complete';
            $result['coverage'] = isset($plan['policy']['maxCompletion'])
                ? $plan['policy']['maxCompletion'] : 'manifest-complete';
            $result['limits'] = isset($plan['policy']['limits']) ? $plan['policy']['limits'] : [];
            return $result;
        }

        $live       = $plan['live'];
        $hostFields = $plan['hostFields'];
        $readSet    = $plan['readSet'];
        $dupes      = $plan['dupes'];

        // Record list first (ids only), then chunked full reads.
        $pk = null;
        try {
            if (is_callable(['\REDCap', 'getRecordIdField'])) $pk = \REDCap::getRecordIdField();
        } catch (\Throwable $e) {
        }
        if (!is_string($pk) || $pk === '') {
            // REDCap's FIRST data-dictionary field is the record identifier, so
            // derive it rather than fall back to what this used to do: ask for
            // every rule field for every record in ONE unchunked call. On a large
            // project that is the whole project in memory before a single record
            // has been examined, which defeats the chunk loop below entirely and
            // fails as an uncatchable OOM rather than as a reported result.
            $ddPk = $this->dataDictionary($pid);
            $ddKeys = is_array($ddPk) ? array_keys($ddPk) : [];
            $pk = isset($ddKeys[0]) ? (string) $ddKeys[0] : '';
        }
        if ($pk === '') {
            $result['incomplete'][] = 'the record identifier field could not be determined, so the '
                . 'record list could not be read without exporting the whole project';
            return $result;              // status stays 'failed'
        }
        // A throw here used to escape scanProject entirely, so the operator saw a
        // PHP error page rather than a scan result - and nothing recorded that
        // the project had not been examined.
        try {
            $idData = \REDCap::getData([
                'project_id' => $pid, 'return_format' => 'array',
                'fields' => [$pk],
                'exportDataAccessGroups' => true,
            ]);
        } catch (\Throwable $e) {
            $result['incomplete'][] = 'the record list could not be read: ' . get_class($e);
            return $result;
        }
        if (!is_array($idData)) {
            $result['incomplete'][] = 'the record list could not be read';
            return $result;
        }
        $ids = [];
        $ungrouped = 0;
        foreach ($idData as $rec => $node) {
            if ($dagFilter !== null) {
                // The SHAPE check is not part of the exclusion test. Written as
                // one conjunction - is_array($node) && dagOf($node) !== $filter -
                // a node REDCap did not return as an array failed the test and
                // was therefore ADMITTED: a record whose group could not be
                // established reached a DAG-scoped report, and its id was
                // printed under a header stating the file covers one group only.
                // A group that cannot be read is not this group.
                if (!is_array($node)) { $ungrouped++; continue; }
                // NAME AGAINST NAME. dagOfRecordNode() returns
                // redcap_data_access_group from the export, which is the unique
                // NAME; $dagName is the same name, resolved once above from the
                // group id the caller passed. Comparing $dagFilter here - the id
                // - excludes every record in the project and reports a clean,
                // complete scan of nothing.
                if (self::dagOfRecordNode($node) !== $dagName) continue;
            }
            $ids[] = $rec;
        }
        if ($ungrouped > 0) {
            // Counted, never listed, and never named. One string per record is
            // the unbounded accumulator this scan exists to avoid, and the id is
            // the exact thing that must not cross the group boundary - so the
            // note says how many and stops there.
            $result['incomplete'][] = $ungrouped . ' record(s) could not be read well enough to '
                . 'establish a Data Access Group, so they were left OUT of this group-scoped scan';
        }
        // The MANIFEST size. The headline count is set at the end, from what was
        // actually reached: a scan halted at the first chunk boundary used to
        // report the full manifest as "Scanned 400 record(s)" in bold while the
        // truth sat in a bullet inside a warning box. The whole point of the
        // 1.6.4 halt guard is that a stopped scan says so.
        $result['stats']['manifest'] = count($ids);
        $result['stats']['records'] = count($ids);
        unset($idData);                  // dead from here; it was held to the return
        if (!$ids) {
            // Zero records IN SCOPE is not a clean project, and the three ways
            // to reach it are indistinguishable from here: the group genuinely
            // has no records; exportDataAccessGroups was not honoured so no
            // record carried a group at all; or the DAG name and the exported
            // group label disagree. All three used to render the green tick over
            // "Scanned 0 record(s)". That is S-03 — the defect 1.6.2 exists to
            // fix — reached by a different route: 1.6.2 refused when the DAG
            // NAME could not be resolved, not when it resolved and matched
            // nothing.
            $result['incomplete'][] = $dagFilter === null
                ? 'the project contains no records, so there was nothing to examine'
                : 'no record was in scope for Data Access Group "' . $dagName . '", so nothing was '
                  . 'examined — this is not evidence that the group\'s data is clean';
            $result['status'] = 'incomplete';
            return $result;
        }

        $uniqueSeen = [];   // aggregate pass: groupKey => [ [record,event,instance,field,rule], ... ]
        // $unconf was declared above (it already holds any config-error rules)

        // The budget. This runs synchronously inside one page request, so the two
        // ways it actually dies are the execution limit and the memory limit —
        // and BOTH are uncatchable fatals. The process stops before the return
        // below, the page renders nothing, and NOTHING records that the project
        // was not examined: no status, no 'incomplete' entry, just a blank screen
        // that looks the same as a network failure. Stopping short and SAYING so
        // is the entire contract (M-03).
        //
        // Measured on a live 39-record project with 329 rules: ~20s warm. A
        // project an order of magnitude larger does not fit in a default
        // execution limit, so this is the expected exit on real data, not a
        // pathological one.
        $tStart   = microtime(true);
        $maxSec   = (int) ini_get('max_execution_time');          // 0 = no limit (CLI)
        $deadline = $maxSec > 0 ? $tStart + ($maxSec * 0.75) : null;
        $memLimit = self::memoryLimitBytes();
        $memCap   = $memLimit > 0 ? (int) ($memLimit * 0.70) : null;
        $reached  = 0;

        // Sliced, not array_chunk()'d. array_chunk builds a SECOND copy of every
        // id up front and holds it for the whole scan, so a 200,000-record
        // project paid for its record list twice before examining anything.
        // array_slice hands back one chunk at a time and the previous one is
        // released as the loop turns.
        $chunkSize = max(1, (int) $chunkSize);

        // A chunk read costs WIDTH x HEIGHT, and only the height was bounded.
        // Every rule field, every when/assert operand and every composite unique
        // partner goes into one getData() call, so a project with 1,500 ruled
        // fields built a 1,500-column export of 200 records at once - and the
        // 1.6.4 halt guard measures memory BETWEEN chunks, so it notices after
        // the allocation that caused the problem, not before.
        //
        // Narrower reads instead of a refusal: the same records are examined,
        // in more passes. CELL_BUDGET is a shape constant, not a tuning knob -
        // 200 records x 200 fields is what the previous behaviour cost on an
        // ordinary project, so nothing changes for one and a wide project stops
        // scaling its peak by rule count.
        $width = max(1, count($readSet));
        $cellBudget = 40000;
        if ($width * $chunkSize > $cellBudget) {
            $narrowed = max(1, (int) ($cellBudget / $width));
            $result['limits'][] = 'this project has ' . $width . ' fields under rules, so records are '
                . 'read ' . $narrowed . ' at a time instead of ' . $chunkSize . ' to keep one read '
                . 'inside memory';
            $chunkSize = $narrowed;
        }

        $total = count($ids);
        for ($offset = 0; $offset < $total; $offset += $chunkSize) {
            $chunk = array_slice($ids, $offset, $chunkSize);
            // Checked BETWEEN chunks and nowhere else. Stopping part-way through
            // a record would leave it half-checked with nothing written down,
            // which is the silent skip this guard exists to prevent (H-05).
            $why = self::scanHalt($deadline, $memCap, microtime(true), memory_get_usage(true));
            if ($why !== null) {
                $halt = ($why === 'time')
                    ? 'the scan stopped after ' . $reached . ' record(s) to stay inside the server '
                      . 'execution limit of ' . $maxSec . 's'
                    : 'the scan stopped after ' . $reached . ' record(s) to avoid exhausting the '
                      . 'server memory limit of ' . ini_get('memory_limit');
                $result['incomplete'][] = $halt . '; ' . (count($ids) - $reached)
                    . ' record(s) were not checked';
                // Duplicate detection is the one check that needs the WHOLE
                // project, so a short run under-reports it. That is a wrong
                // negative rather than a missing row, and the operator has to be
                // told which it is.
                $result['incomplete'][] = 'because the scan stopped early, duplicate values are '
                    . 'under-reported: a value is only seen as duplicated if both records were reached';
                break;
            }
            // A chunk that cannot be read is RECORDED, never skipped in silence:
            // skipping it produced a green "No violations found" for records that
            // were never examined, which is the worst possible failure for a tool
            // whose entire output is an assurance.
            try {
                $data = \REDCap::getData([
                    'project_id' => $pid, 'return_format' => 'array',
                    'records' => $chunk, 'fields' => array_keys($readSet),
                    'exportDataAccessGroups' => true,
                ]);
            } catch (\Throwable $e) {
                $result['incomplete'][] = 'reading ' . count($chunk) . ' record(s) failed: '
                    . get_class($e);
                continue;
            }
            if (!is_array($data)) {
                $result['incomplete'][] = 'reading ' . count($chunk) . ' record(s) returned no usable data';
                continue;
            }
            foreach ($chunk as $rec) {
                if (!isset($data[$rec]) || !is_array($data[$rec])) {
                    // The SAME record-id posture the findings use. 'none' mode
                    // exists for sites where the record id is itself identifying,
                    // and these notes are rendered on the page and written into
                    // the CSV twice - for exactly the records a site is chasing.
                    $result['incomplete'][] = 'record ' . $this->reportRecordId($plan, $rec)
                        . ' was requested but not returned';
                    continue;
                }
                try {
                    $one = $this->scanRecord($plan, $pid, $rec, $data[$rec], $sink, $uniqueSeen, $unconf);
                } catch (\Throwable $e) {
                    // The SINK is a caller-supplied consumer - it writes to a
                    // spool, a socket, a table. When it threw, the exception
                    // escaped scanProject entirely and took the result with it:
                    // no status, no incomplete list, nothing recording that the
                    // project had not been examined, which is the one failure
                    // this contract exists to prevent (M-03).
                    $result['incomplete'][] = 'record ' . $this->reportRecordId($plan, $rec)
                        . ' could not be reported: ' . get_class($e);
                    continue;
                }
                if ($one['why'] !== null) {
                    $result['incomplete'][] = $one['why'];
                    continue;
                }
                $result['stats']['contexts'] += $one['contexts'];
            }
            // The chunk's rows are dead now. Releasing them before the next
            // getData allocates means the two do not coexist, which halves the
            // read peak; without it the last chunk is also held to the return.
            $reached += count($chunk);
            unset($data);
        }

        // Aggregate duplicate detection: a group is a violation when TWO OR
        // MORE DISTINCT RECORDS share the key (same-record repeats mirror the
        // endpoint/audit, which only compare against OTHER records).
        $emitted = [];
        foreach ($uniqueSeen as $entries) {
            $records = [];
            foreach ($entries as $e) $records[$e['record']] = true;
            if (count($records) < 2) continue;
            foreach ($entries as $e) {
                // One row, one finding. Host scoping already stops a rule being
                // collected from contexts it does not live in; this is the belt to
                // that brace, so a row can never be listed twice for one rule
                // whatever the record shape (H-04).
                $at = $e['rule'] . '|' . $e['record'] . '|' . $e['event_id'] . '|' . $e['instance'] . '|' . $e['field'];
                if (isset($emitted[$at])) continue;
                $emitted[$at] = true;
                // Already filtered at collection time (see collectUniqueCandidates):
                // false means withheld by policy, null means there was nothing.
                $rv = array_key_exists('value', $e) ? $e['value'] : null;
                $sink->violation([
                    'record' => $this->reportRecordId($plan, $e['record']), 'event_id' => $e['event_id'],
                    'instance' => $e['instance'], 'field' => $e['field'],
                    'type' => 'unique', 'reason' => 'duplicate-value', 'rule' => $e['rule'],
                    'value' => ($rv === false) ? null : $rv,
                    'valueWithheld' => ($rv === false),
                    'instrument' => isset($e['instrument']) ? $e['instrument'] : null,
                    'dag' => isset($e['dag']) ? $e['dag'] : null,
                ]);
            }
        }
        $result['unconfigurable'] = array_values($unconf);
        // The count is kept whatever the sink does with the rows, so a streaming
        // caller can still say how many findings there were — and so 'no
        // violations' is never inferred from an empty array that was never
        // filled in the first place (M-02).
        // What was EXAMINED, not what was listed. Set before the loop and never
        // revised, this reported the full manifest as the headline on a scan
        // that had halted at the first chunk boundary — "Scanned 400 record(s)"
        // in bold, with the truth in a bullet inside a warning box, and the same
        // 400 on the export's metadata line.
        $result['stats']['records'] = $reached;
        $result['stats']['violations'] = $sink->count();
        // COVERAGE is a separate axis from STATUS. Status says whether the sweep
        // finished; coverage says what finishing is worth on this installation.
        // A run that read every record on its opening list, on a server where no
        // change fence can be proved, is 'manifest-complete': it cannot know the
        // project did not move underneath it, and per the plan that must never
        // render as complete or clean.
        // MERGED, not overwritten. The installation's limits come from the
        // capability policy; the run's own limits are recorded during the sweep
        // (the narrowed chunk read above). Assigning here discarded the second
        // set, which is the only kind a reader can act on.
        $result['limits'] = array_merge(
            isset($plan['policy']['limits']) ? $plan['policy']['limits'] : [],
            isset($result['limits']) ? $result['limits'] : []);
        if ($collect) $result['violations'] = $sink->violations;
        // Only now can the scan claim it saw everything.
        $result['status'] = $result['incomplete'] ? 'incomplete' : 'complete';
        // AFTER the status, never before: read a line too early this consulted
        // the initial 'failed' and every run came back 'partial', which silently
        // withheld the tick from scans that had earned it.
        $maxCov = isset($plan['policy']['maxCompletion']) ? $plan['policy']['maxCompletion'] : 'manifest-complete';
        $result['coverage'] = ($result['status'] === 'complete') ? $maxCov : 'partial';
        // The rule list the ordinals in these findings refer to. A report that
        // re-read getRules() to resolve 'Rule 12' was joining two INDEPENDENT
        // reads by array position: add or reorder a rule between them and every
        // label lands on the wrong finding, silently. Bounded by the rule count,
        // never by the data.
        $result['rules'] = isset($plan['allRules']) ? $plan['allRules'] : [];
        return $result;
    }

    /**
     * The label snapshot a report needs, for THIS project.
     *
     * Public because the export page needs it and the pieces it is built from
     * are private. By the time a report asks, the scan has already read both the
     * dictionary and the rules, so this is a memory read rather than a second
     * pass over the project.
     */
    public function scanDimensions($pid, ?array $rules = null)
    {
        $dd = $this->dataDictionary($pid);
        // Prefer the snapshot the scan actually used. Re-reading here joined the
        // findings to a SECOND read by array position, so a rule added or
        // reordered between the two moved every label onto the wrong finding.
        if ($rules === null) {
            $rules = [];
            try { $rules = $this->getRules($pid); } catch (\Throwable $e) { $rules = []; }
        }
        return ScanDimensions::build($pid, is_array($dd) ? $dd : [], is_array($rules) ? $rules : []);
    }

    /** The record id as the report may show it, honouring the log-values posture. */
    private function reportRecordId(array $plan, $rec)
    {
        if (empty($plan['hashRecordIds'])) return (string) $rec;
        try {
            $h = $this->hashedIdentifier($plan['pid'], (string) $rec);
        } catch (\Throwable $e) {
            $h = null;
        }
        // hashedIdentifier RETURNS null when no key can be obtained - it catches
        // its own failure rather than throwing - so a catch alone never fired and
        // every Record cell rendered EMPTY. On screen that is a table of
        // violations with no way to reach any of them; in a CSV it reads as a
        // fault in the reader's own export. Never the raw id, but never blank
        // either: say that it was withheld.
        return ($h === null || $h === '') ? '[record id unavailable]' : $h;
    }

    /** Longest value the report will carry. A report is not a second copy of the project. */
    const REPORT_VALUE_MAX = 120;

    /**
     * The value to show beside one finding, or null to show nothing.
     *
     * Four things can stop a value reaching the report, and they are NOT the
     * same and must not look the same to a reader:
     *   - policy says never          -> false, rendered as a marker
     *   - the finding has no value   -> null (a required-blank IS the blank)
     *   - the field is an Identifier -> a marker, so the reader knows a value
     *                                   exists and was withheld rather than absent
     *   - the bytes are not text     -> a marker with the length. The module's own
     *                                   L-01 comment records that values can carry
     *                                   invalid UTF-8 from a Latin-1 import, and
     *                                   pasting those into a CSV corrupts the file.
     */
    private static function reportValue(array $v, array $plan)
    {
        // Fail CLOSED on a missing key. This defaulted to 'raw', which put the
        // MOST disclosing option twenty lines above valueRank()'s docblock
        // promising that "anything unrecognised is treated as the least
        // disclosing option" - a fail-open default in the one function whose
        // entire job is to withhold. scanPlan() always sets the key today, so
        // this was latent; a default that is only safe because nobody takes it
        // is not a safe default.
        $mode = isset($plan['valueMode']) ? $plan['valueMode'] : 'locations';

        if (!array_key_exists('value', $v)) return null;
        $val = $v['value'];
        if (is_array($val)) $val = implode(', ', array_map('strval', $val));   // a checkbox
        $val = (string) $val;

        // NOTHING TO WITHHOLD and WITHHELD are different claims, and telling
        // them apart is the entire reason the marker exists. This branched on
        // the KEY existing - but the required path sets 'value' => ''
        // unconditionally (see ruleFindings above), so the key ALWAYS exists and
        // every required-blank finding rendered '[withheld by policy]'. That is
        // an affirmative false statement about a field that is empty, made on
        // the one finding type whose whole content is that the field is empty.
        // Branch on there being a value, which is what the sentence means.
        if ($val === '') return null;
        if ($mode === 'locations') return false;

        $field = isset($v['field']) ? (string) $v['field'] : '';
        $ids = isset($plan['identifiers']) ? $plan['identifiers'] : null;
        if (self::mustRedact($ids, $field, $mode)) return '[identifier withheld]';

        if (!mb_check_encoding($val, 'UTF-8')) {
            return '[' . strlen($val) . ' bytes, not valid text]';
        }
        if (mb_strlen($val, 'UTF-8') > self::REPORT_VALUE_MAX) {
            return mb_substr($val, 0, self::REPORT_VALUE_MAX, 'UTF-8') . '… (truncated)';
        }
        return $val;
    }

    /**
     * How disclosing a value mode is. Higher shows more. Unknown ranks LOWEST,
     * so anything unrecognised is treated as the least disclosing option rather
     * than the most.
     */
    private static function valueRank($mode, $unknown = 0)
    {
        $r = ['locations' => 0, 'identifier-redacted' => 1, 'raw' => 2];
        return isset($r[$mode]) ? $r[$mode] : $unknown;
    }

    /** The less disclosing of two modes. */
    private static function valueFloor($a, $b)
    {
        return self::valueRank($a) <= self::valueRank($b) ? $a : $b;
    }

    /**
     * How the scan report may show values: 'raw' | 'identifier-redacted' | 'locations'.
     *
     * A settings read that throws must not decide between showing values and
     * withholding them, so a failure lands on the most permissive documented
     * default rather than silently switching policy — the same posture logMode()
     * takes, and for the same reason: a quietly-changed privacy mode is worse
     * than a wrong one, because nobody can tell it happened.
     */
    private function scanValueMode($pid)
    {
        try {
            $m = $this->getProjectSetting('scan-value-storage', $pid);
            if (self::valueRank($m, -1) >= 0) return $m;
        } catch (\Throwable $e) {
        }
        // An External Modules dropdown stores NOTHING until the settings dialog
        // is saved, so this is null on every project nobody has reconfigured -
        // which is most of them, and all of them on upgrade. Landing on 'raw'
        // there would switch every existing installation from locations-only to
        // full disclosure with nobody having decided anything. Unknown or
        // unreadable settings fail toward LESS disclosure.
        return 'locations';
    }

    /**
     * TRUE when this field's value must NOT appear in the report.
     *
     * The INVERSE of isIdentifier()'s posture, deliberately. That helper answers
     * "is this field known to be an identifier", so an unreadable dictionary
     * means "nothing is" — right for refusing to enable a survey feature, and
     * catastrophic here, where it would mean "redact nothing". A dictionary we
     * cannot read is a dictionary that cannot clear a field, so in 'identifiers'
     * mode an unreadable one redacts EVERYTHING.
     *
     * @param array|null $ids projectIdentifierFields(), which returns null on a failed read
     */
    private static function mustRedact($ids, $field, $mode)
    {
        if ($mode === 'locations') return true;
        if ($mode === 'raw')       return false;
        if (!is_array($ids))       return true;     // cannot clear it -> withhold it
        return isset($ids[$field]);
    }

    /**
     * The one seam between this framework adapter and the durable scan.
     *
     * WHY A SINGLE METHOD RATHER THAN PUBLIC ACCESSORS. Everything under
     * php/Scan/ is written to be testable without REDCap; the moment it can
     * reach into this class it stops being. So the durable side asks once, gets
     * closures, and never learns what a data dictionary is. scanPlan() and
     * scanRecord() stay private, which also means the legacy synchronous path
     * and the durable one cannot drift into two different ideas of what a rule
     * means - they run the same two methods.
     *
     * WHAT IT REFUSES. A plan with a fatal problem does not become a run with a
     * caveat: a scan that cannot resolve its own rules has nothing true to say
     * about the project, and starting one would produce a report whose emptiness
     * looks like good news.
     *
     * @return array{ok:bool, why:?string, plan:?array, evaluate:?callable,
     *               read:?callable, rules:array, ownership:array, problems:array}
     */
    public function durableScanContext($pid, array $opts = [], $dagFilter = null)
    {
        $plan = $this->scanPlan($pid, $opts, $dagFilter);
        // THE SHAPE IS THE SAME ON EVERY RETURN, including the refusals. A
        // caller that has to know which branch answered before it knows which
        // keys exist is a caller that will read the wrong one.
        if ($plan['fatal'] !== null) {
            return ['ok' => false, 'why' => $plan['fatal'], 'plan' => null,
                    'evaluate' => null, 'read' => null, 'rules' => [], 'ownership' => [],
                    'problems' => array_values($plan['unconf'])];
        }
        if (!empty($plan['nothingToScan'])) {
            // The rule problems survive the refusal even though no run can carry
            // them today. scanPlan()'s own note says every rule barred is not
            // nothing to scan - the rule problems ARE the report, and they must
            // survive - and this return is where they stopped surviving. Closing
            // it completely needs a run that can exist with no live rules, which
            // is a later decision; handing them back is what makes it possible.
            return ['ok' => false, 'why' => 'this project has no rules this scan can evaluate',
                    'plan' => null, 'evaluate' => null, 'read' => null,
                    'rules' => [], 'ownership' => [],
                    'problems' => array_values($plan['unconf'])];
        }

        $key = $this->hmacKey();

        // THE GENERATION IS THE RUN'S, AND THERE IS NO DEFAULT.
        //
        // It used to be `isset($opts['generation']) ? ... : 1`, and no caller
        // anywhere passed one - so every run of every project wrote generation
        // 1. That single default is the root cause of five failed pilots: the
        // second scan of any project re-inserted identities that were already
        // active, the unique key refused them, and the batch rolled back
        // forever. A default is what let it ship, so there is no longer one.
        //
        // NULL is a legitimate value and means "planning": start() needs the
        // rules and the ownership map before a run exists to have a generation,
        // so it asks for a context with no evaluator rather than inventing a
        // number. Passing no key at all is still an error.
        if (!array_key_exists('generation', $opts)) {
            throw new \InvalidArgumentException(
                'durableScanContext requires the run generation (null for planning)');
        }
        $gen = ($opts['generation'] === null) ? null : (int) $opts['generation'];
        $runSeq = isset($opts['runSeq']) ? (int) $opts['runSeq'] : 0;

        // When a stored value preview expires, decided at WRITE time. The
        // policy method that computes this had no callers at all, so every
        // preview was written with a NULL expiry and the query that removes
        // them could never have matched one.
        $valueExpiry = Scan\ScanPolicy::valueExpiry(
            isset($opts['policy']) && is_array($opts['policy']) ? $opts['policy'] : [], time());

        // Taken from the plan, never re-derived. See scanPlan()'s note where
        // ruleIds is built: deriving it twice is what let the evaluator and the
        // planner disagree about which rule an ordinal named.
        $ids = isset($plan['ruleIds']) && is_array($plan['ruleIds']) ? $plan['ruleIds'] : [];

        // TAKEN FROM THE PLAN, exactly as $ids is one block above, and for the
        // same reason. It used to be built HERE by walking $plan['hostFields'],
        // which ruleHostForms() fills from $rule['fields'] alone - so the map
        // ScanService turns into an entitlement named the forms the RULES live
        // on while getData was asked for the read set, which also carries every
        // when/assert operand and every unique-composite partner. scanPlan()
        // derives it from $readSet now; a second derivation here is the drift
        // this file keeps paying for.
        $ownership = isset($plan['ownership']) && is_array($plan['ownership'])
                   ? $plan['ownership'] : [];

        $module = $this;
        // No generation, no evaluator. A caller that only needs the rule list
        // gets one it cannot accidentally scan with.
        $evaluate = ($gen === null) ? null : function ($recordId, array $node) use ($module, $plan, $pid, $gen, $key, $ids,
                                                          $runSeq, $valueExpiry) {
            return $module->durableEvaluateRecord($plan, $pid, $recordId, $node, $gen, $key, $ids,
                                                  $runSeq, $valueExpiry);
        };

        // The read the worker performs. Explicit records, and only the fields
        // the plan actually needs - the same narrowing the chunked legacy path
        // does, for the same reason.
        $fields = array_keys($plan['readSet']);
        $read = function (array $recordIds) use ($pid, $fields) {
            try {
                if (!is_callable(['\REDCap', 'getData'])) {
                    return ['ok' => false, 'data' => [],
                            'why' => 'this installation does not expose a record read'];
                }
                $data = \REDCap::getData([
                    'project_id' => $pid, 'return_format' => 'array',
                    'records' => array_values($recordIds), 'fields' => $fields,
                    'exportDataAccessGroups' => true,
                ]);
                if (!is_array($data)) {
                    return ['ok' => false, 'data' => [], 'why' => 'the records could not be read'];
                }
                return ['ok' => true, 'data' => $data, 'why' => null];
            } catch (\Throwable $e) {
                // A FAILED READ IS NOT AN EMPTY ONE. The worker requeues on
                // false and would commit "examined, nothing found" on an empty
                // success - which is the difference this return exists to keep.
                return ['ok' => false, 'data' => [],
                        'why' => 'the records could not be read (' . get_class($e) . ')'];
            }
        };

        // THE RULE PROBLEMS TRAVEL. They were computed by scanPlan() - config
        // errors, rules whose instrument cannot be resolved, rules on an
        // instrument no event collects, project-scope uniqueness under a group
        // scope - and then dropped right here, which is why ScanOutcome's
        // `ruleProblems` term had nothing to read and `clean` quietly meant "no
        // findings". array_values because the keys are the dedupe (rule|reason),
        // not data anything downstream should depend on.
        return ['ok' => true, 'why' => null, 'plan' => $plan, 'evaluate' => $evaluate,
                'read' => $read, 'rules' => $plan['live'], 'ownership' => $ownership,
                'verifyFingerprint' => true, 'structure' => $this->temporalScanStructure($pid, $plan['live']),
                'problems' => array_values($plan['unconf'])];
    }

    /**
     * One record, turned into durable rows.
     *
     * Public only because the closure above needs it; it is not part of any
     * contract and takes the plan it was built from. Everything it maps is a
     * decision already made elsewhere - reportValue() decides disclosure, the
     * rule identities come from the planner - so this method chooses nothing and
     * exists to translate.
     *
     * @return array{findings:array, candidates:array, bytes:int, contexts:int,
     *               problems:array, why:?string}
     */
    public function durableEvaluateRecord(array $plan, $pid, $recordId, array $node, $gen, $key,
                                          array $ids, $runSeq = 0, $valueExpiry = null)
    {
        $found = [];
        $sink = new CallbackFindingSink(function (array $v) use (&$found) { $found[] = $v; });
        $seen = [];
        $unconf = [];
        $r = $this->scanRecord($plan, $pid, $recordId, $node, $sink, $seen, $unconf);
        if ($r['why'] !== null) {
            return ['findings' => [], 'candidates' => [], 'bytes' => 0, 'contexts' => 0,
                    'problems' => [], 'collapsed' => 0, 'why' => $r['why']];
        }

        $recHash = Scan\Hmac::raw(Scan\Hmac::P_RECORD, $pid, (string) $recordId, $key);
        $missed = [];
        $rule = function ($ord) use ($ids, &$missed) {
            $i = ((int) $ord) - 1;
            // A rule the planner could not name is still reported, under a name
            // that says so. Dropping the finding would be the silent skip.
            //
            // AND IT IS NOW LOUD. $ids is keyed by the same ordinals as
            // $plan['live'], so after the key-preserving fix this branch is
            // unreachable in normal operation - which means any occurrence is a
            // bug in us, not a fact about the project. It used to be reachable
            // on every project with one config-broken rule, and it produced a
            // plausible-looking name ('unnamed:7') that no reader would question.
            if (isset($ids[$i])) return $ids[$i];
            $missed[(int) $ord] = true;
            return ['source_id' => 'unnamed:' . (int) $ord, 'revision' => str_repeat('0', 64)];
        };

        $findings = [];
        $byIdentity = [];
        $collapsed = 0;
        $bytes = 0;
        foreach ($found as $v) {
            $id = $rule($v['rule']);
            $loc = ['record' => (string) $recordId, 'event_id' => $v['event_id'],
                    'instance' => $v['instance'], 'host_form' => $v['instrument'],
                    'field' => $v['field'], 'rule_source_id' => $id['source_id'],
                    'reason_code' => Scan\ReasonCode::code($v['reason']),
                    // The within-location discriminator. See Hmac::findingIdentity.
                    'locus' => isset($v['locus']) ? (string) $v['locus'] : ''];
            $val = empty($v['valueWithheld']) && isset($v['value']) ? $v['value'] : null;
            $blob = ($val === null) ? null : substr((string) $val, 0, 255);
            $identity = Scan\Hmac::findingIdentity($pid, $loc, $key);

            // BACKSTOP, NOT THE FIX. With the discriminator in place nothing
            // legitimate produces one identity twice, so a repeat here is a bug
            // in a rule kind rather than a fact about the project - and it must
            // be COUNTED rather than allowed to reach the unique key, where it
            // would roll back a whole batch of correctly examined records. The
            // count travels out so the worker can raise it; swallowing it in
            // SQL with ON DUPLICATE KEY UPDATE would hide exactly the thing
            // this release exists to make visible.
            $seenKey = bin2hex($identity);
            if (isset($byIdentity[$seenKey])) { $collapsed++; continue; }
            $byIdentity[$seenKey] = true;

            if ($blob !== null) $bytes += strlen($blob);
            $findings[] = [
                'project_id' => (int) $pid,
                'generation_id' => $gen,
                'identity' => $identity,
                // The RUN's sequence number, not a per-record ordinal. It used
                // to be `++$seq`, which put valid_from_seq and valid_to_seq in
                // different number spaces and made the interval columns the
                // schema is built around describe nothing.
                'valid_from_seq' => $runSeq,
                'record_hash' => $recHash,
                'record_id_bin' => (string) $recordId,
                'event_id' => $v['event_id'],
                'instance' => $v['instance'],
                'host_form' => (string) $v['instrument'],
                'field' => (string) $v['field'],
                'rule_source_id' => $id['source_id'],
                'rule_revision' => $id['revision'],
                'rule_ord' => (int) $v['rule'],
                'check_type' => (string) $v['type'],
                'reason_code' => Scan\ReasonCode::code($v['reason']),
                'dag_key' => isset($v['dag']) ? $v['dag'] : null,
                'value_bin' => $blob,
                'value_len' => ($val === null) ? null : strlen((string) $val),
                'value_truncated' => ($val !== null && strlen((string) $val) > 255) ? 1 : 0,
                'value_fingerprint' => ($val === null) ? null
                    : Scan\Hmac::raw(Scan\Hmac::P_VALUE, $pid, (string) $val, $key),
                // WRITTEN AT WRITE TIME. The expiry policy was computed by a
                // method with no callers, so every stored preview carried a NULL
                // here and the query that expires them - WHERE value_expires_at
                // IS NOT NULL - could never have matched a row even once it was
                // wired. Participant data was retained indefinitely in a table
                // any user with design rights can read.
                'value_expires_at' => ($blob === null) ? null : $valueExpiry,
            ];
        }

        // Uniqueness produces CANDIDATES, never findings: no record is a
        // duplicate on its own evidence. The composite key built by the legacy
        // path is reused verbatim and then keyed, so the live check, the audit
        // and the scan all agree about what "the same value" means.
        $candidates = [];
        $candSeen = [];
        foreach ($seen as $groupKey => $rows) {
            $g = Scan\Hmac::raw(Scan\Hmac::P_UNIQUE, $pid, (string) $groupKey, $key);
            foreach ($rows as $row) {
                $id = $rule($row['rule']);
                // scope_key was the literal 'project' whatever the rule said, so
                // a rule scoped to a Data Access Group or to an event was stored
                // as though it were project-wide. The rule knows its own scope;
                // the store was being told something else.
                $scope = isset($row['scope']) && is_string($row['scope']) && $row['scope'] !== ''
                    ? (string) $row['scope'] : 'project';
                // The candidate key the store enforces, computed here so an
                // intra-record repeat is dropped before it can refuse a batch -
                // the same backstop the findings get, for the same reason.
                $ck = $g . '|' . $recHash . '|' . (string) $row['event_id'] . '|'
                    . (string) $row['instance'] . '|' . (string) $row['field'];
                if (isset($candSeen[$ck])) { $collapsed++; continue; }
                $candSeen[$ck] = true;
                $candidates[] = [
                    'project_id' => (int) $pid,
                    'generation_id' => $gen,
                    'rule_source_id' => $id['source_id'],
                    'rule_revision' => $id['revision'],
                    'group_hmac' => $g,
                    'scope_key' => $scope,
                    'record_hash' => $recHash,
                    'record_id_bin' => (string) $recordId,
                    'event_id' => $row['event_id'],
                    'instance' => $row['instance'],
                    'host_form' => (string) $row['instrument'],
                    'field' => (string) $row['field'],
                ];
            }
        }

        // A rule ordinal the planner could not name is an INTERNAL fault, and it
        // travels as a rule problem rather than as a plausible name nobody
        // questions. It is reported per record, which is where it was noticed;
        // the aggregate that counts rule problems dedupes on the text.
        $problems = array_values($unconf);
        foreach (array_keys($missed) as $ord) {
            $problems[] = [
                'rule'   => (int) $ord,
                'fields' => [],
                'why'    => 'internal: this scan holds no identity for rule ' . (int) $ord
                    . ', so any finding it produced is recorded under a placeholder name',
            ];
        }

        return ['findings' => $findings, 'candidates' => $candidates, 'bytes' => $bytes,
                'contexts' => $r['contexts'], 'problems' => $problems,
                // Non-zero means a rule kind produced one identity twice. With
                // the locus discriminator in place nothing legitimate does, so
                // this is a bug report rather than a fact about the project.
                'collapsed' => $collapsed, 'why' => null];
    }

    /**
     * Everything a scan needs to know before it reads its first record: which
     * rules are live, where each one lives, what has to be read, and which rule
     * problems are already known. Computed once per scan.
     *
     * Lifted out of scanProject() whole in 1.7.0. It is the half that does not
     * depend on the data, so a caller that scans a project in slices computes it
     * once rather than per slice — and it is the half whose failures are FATAL,
     * which is why they come back as one 'fatal' string rather than being mixed
     * in with per-record notes.
     *
     * @return array{fatal: ?string, nothingToScan: bool, live: array, hostFields: array,
     *               ownership: array<string,?string> field => owning instrument, NULL when it
     *               could not be placed - the fail-closed encoding ScanService reads as
     *               unknown ownership and mayStart() refuses on,
     *               readSet: array, dupes: array, unconf: array}
     */
    private function scanPlan($pid, array $opts = [], $dagFilter = null)
    {
        $out = ['pid' => $pid, 'fatal' => null, 'nothingToScan' => false, 'live' => [], 'hostFields' => [],
                // Present on EVERY return, including the refusals. An entitlement
                // key that exists only on the success path is one a caller reads as
                // "no forms" on the path where it should read as "no answer".
                'ownership' => [],
                'readSet' => [], 'dupes' => [], 'unconf' => [],
                // Resolved once: the policy cannot change mid-scan, and the
                // identifier set is a dictionary read we already paid for.
                // The PROJECT's setting, capped by what THIS READER is entitled
                // to see. The project decides how disclosing the report may be;
                // the reader's own export rights decide how disclosing it
                // actually is. Neither can raise the other.
                'valueMode' => self::valueFloor($this->scanValueMode($pid),
                                                // 'locations' when the caller says nothing. This
                                                // read 'raw' - the MOST disclosing option - as the
                                                // default of the one expression whose job is to cap
                                                // disclosure, and beside a valueRank() whose docblock
                                                // states that anything unrecognised ranks LOWEST. Both
                                                // pages pass a ceiling, so it was latent; a caller that
                                                // wants raw can say so.
                                                isset($opts['valueCeiling']) ? $opts['valueCeiling'] : 'locations'),
                'identifiers' => $this->projectIdentifierFields($pid),
                // 'none' is the log mode for sites where the RECORD ID is itself
                // identifying. The report is a new surface and must not
                // contradict the posture the audit already applies.
                'hashRecordIds' => ($this->logMode($pid) === 'none')];

        // What this installation can actually support, and therefore what a run
        // on it is ALLOWED TO CLAIM. ScanCapabilities computed this cap from the
        // start and nothing consulted it, so the module contained a correct,
        // tested implementation of its own central safety property and did not
        // call it - which is worse than not having written it, because the suite
        // reported the property as covered.
        try {
            $out['policy'] = ScanCapabilities::policy(ScanCapabilities::all($this, $pid));
        } catch (\Throwable $e) {
            // A probe layer that fails cannot license a claim. Cap at the
            // weakest coverage rather than assume the strongest.
            $out['policy'] = ['mayScan' => true, 'maxCompletion' => 'manifest-complete',
                              'incremental' => false,
                              'limits' => ['the capabilities of this installation could not be '
                                           . 'established: ' . get_class($e)]];
        }

        // Rule DISCOVERY is a read like any other and can throw: a settings
        // backend failure used to escape scanProject entirely, so the operator
        // got a PHP error page instead of a scan result and nothing recorded
        // that the project had not been examined (M-03).
        try {
            $rules = $this->getRules($pid);
        } catch (\Throwable $e) {
            $out['fatal'] = 'the rule list could not be read: ' . get_class($e);
            return $out;
        }
        if (!is_array($rules)) $rules = [];

        // The dictionary is load-bearing twice: annotation rules are READ from
        // it, and every rule has to be located on an instrument before it can be
        // evaluated at all. Establish that independently of whether any rule
        // survived — a failed read that left one settings rule standing used to
        // scan that rule and report 'complete' while every annotation rule had
        // silently vanished from the list (H-05).
        if (!$this->dataDictionary($pid)) {
            $out['fatal'] = 'the project data dictionary could not be read, so the rule list is '
                . 'incomplete and no rule can be located on an instrument';
            return $out;
        }
        if (!$rules) { $out['nothingToScan'] = true; return $out; }

        $live = [];
        $unconf = [];   // dedupe rule-problem notes by rule+why (config errors AND runtime)
        foreach ($rules as $i => $r) {
            if (!empty($r['configError'])) {
                // A config-broken rule enforces NOTHING. Surface it in the scan
                // rather than imply a clean project — the module's rule is that
                // nothing fails silently (M-05). It also shows on the data-entry
                // form, but a scan operator would not otherwise know a rule is inert.
                $unconf[$i . '|configError'] = [
                    'rule'   => $i + 1,
                    'fields' => (isset($r['fields']) && is_array($r['fields'])) ? $r['fields'] : [],
                    'why'    => 'configuration error — this rule validates nothing: ' . $r['configError'],
                ];
                continue;
            }
            $live[$i] = $r;
        }
        $out['live']   = $live;
        $out['unconf'] = $unconf;
        $out['allRules'] = $rules;   // the list the ordinals in findings refer to
        if (!$live) { $out['nothingToScan'] = true; return $out; }

        $dupes = [];
        foreach (self::duplicateFields($rules) as $f) $dupes[$f] = true;
        $out['dupes'] = $dupes;

        // WHERE each rule lives, computed once. A rule whose field cannot be
        // located on any instrument is not evaluated in some arbitrary context
        // and hoped for — it is reported, because a guessed location produces
        // confident nonsense rather than a near miss (H-02).
        $hostFields = [];
        foreach ($live as $i => $r) {
            $h = $this->ruleHostForms($r, $pid);
            if ($h['unknown']) {
                $unconf[$i . '|unlocatable'] = ['rule' => $i + 1, 'fields' => $h['unknown'],
                    'why' => 'the instrument that owns this rule\'s field(s) could not be determined from the '
                           . 'data dictionary, so there is no context in which to check them — the field is not scanned'];
            }
            $hostFields[$i] = $h['forms'];
        }
        // A rule whose instrument is designated for NO event can never run.
        // hostContextsFor() drops every context for an unmapped form, so the
        // rule yields no violation - and, because nothing ever reached the
        // evaluator, no rule problem either. The scan then reports the project
        // complete and clean while the rule has enforced nothing since the day
        // it was written. Every OTHER unevaluable condition in this module says
        // so out loud; this was the one that did not.
        //
        // Fails OPEN. A null map - a classic project, or a build that does not
        // expose the mapping - makes NO claim, because wrongly declaring an
        // instrument uncollected would suppress a rule that works. Only a
        // mapping that actually names instruments is trusted, and then only to
        // say that a form it does not name collects nothing.
        $mapped = $this->mappedInstruments($pid);
        if (is_array($mapped)) {
            foreach ($hostFields as $i => $forms) {
                $orphanForms = [];
                $orphanFields = [];
                foreach ($forms as $form => $ownFields) {
                    if (isset($mapped[$form])) continue;
                    $orphanForms[] = (string) $form;
                    foreach ((array) $ownFields as $f) $orphanFields[] = (string) $f;
                }
                if (!$orphanForms) continue;
                // Per HOST, not per rule: a rule spanning two instruments where
                // only one is unmapped is still checked on the other, and saying
                // the whole rule was skipped would be the opposite error (H-02).
                $unconf[$i . '|unmapped-instrument'] = [
                    'rule'   => $i + 1,
                    'fields' => $orphanFields,
                    'why'    => 'instrument ' . implode(', ', $orphanForms) . ' is not designated to any event, '
                              . 'so no record can hold these field(s) and the rule was NOT evaluated on them. '
                              . 'Assign the instrument to an event, or move the rule to an instrument that is.',
                ];
            }
        }
        // DESIGN RIGHTS ARE NOT INSTRUMENT RIGHTS.
        //
        // The scan reads through REDCap::getData() with a project id and no
        // user, so REDCap's own per-instrument access control never runs. A
        // designer with No Access to an instrument therefore received that
        // instrument's findings - and, on a project that had opted into raw
        // values, its values. The 1.8.x export-rights ceiling caps how much of a
        // value is shown; it says nothing about which instruments a reader may
        // see at all, and the docblock that introduced it names this exact case.
        //
        // Enforced by DROPPING the rule before it is ever evaluated, not by
        // filtering rows afterwards. A row filter still leaks: the finding count
        // moves, the instrument label appears in a summary, an aggregate over
        // the project reveals how many problems live on a form the reader cannot
        // open. A rule that never runs produces nothing to leak.
        //
        // The entitlement set is every host instrument PLUS every instrument
        // owning a field the rule references, because a `when` or `assert`
        // operand read from a barred form decides the verdict just as directly
        // as the field being checked.
        //
        // Opt-in per call. Only a request made BY a user can be scoped to that
        // user, and scanProject() is also reachable with no user context at all;
        // both pages pass it, which tests/scan_page_php.php asserts.
        if (!empty($opts['enforceFormRights'])) {
            // NULL means the rights could not be established, and a right that
            // cannot be read cannot clear an instrument - same posture as
            // mustRedact() and disclosableFields(), for the same reason.
            $formRights = $this->userFormRights($pid);
            $ddForms = $this->dataDictionary($pid);
            $whyUnreadable = 'your per-instrument rights could not be established, so no instrument '
                . 'could be cleared for reading and this rule was NOT evaluated - a right that cannot '
                . 'be read cannot grant access. Ask an administrator to check your user rights.';
            foreach ($live as $i => $r) {
                // TWO questions, because they have different answers.
                //
                // A rule's CONDITION is rule-wide: a `when` or `assert` operand
                // read from a barred instrument decides every host's verdict, so
                // one barred operand makes the whole rule unevaluable.
                //
                // A rule's HOSTS are independent. Annotation rules pool by
                // configuration, so one rule routinely spans several
                // instruments; barring the rule outright would throw away the
                // hosts the reader is perfectly entitled to, which is the
                // over-broad half of H-02. Bar the host, keep the rest.
                $own = [];
                foreach ((isset($r['fields']) && is_array($r['fields'])) ? $r['fields'] : [] as $f) {
                    $own[(string) $f] = true;
                }
                $condBarred = [];
                foreach (self::ruleRefFields($r) as $f) {
                    if (isset($own[$f])) continue;                  // a host field, handled below
                    if (!isset($ddForms[$f]['form_name']) || $ddForms[$f]['form_name'] === '') continue;
                    $form = (string) $ddForms[$f]['form_name'];
                    if (!self::mayReadForm($formRights, $form)) $condBarred[$form] = true;
                }
                $hostBarred = [];
                foreach ($hostFields[$i] as $form => $_) {
                    if (!self::mayReadForm($formRights, (string) $form)) $hostBarred[(string) $form] = true;
                }
                if (!$condBarred && !$hostBarred) continue;

                $whole = (bool) $condBarred || count($hostBarred) >= count($hostFields[$i]);
                $named = array_keys($condBarred ? $condBarred : $hostBarred);
                $unconf[$i . '|no-instrument-rights'] = [
                    'rule'   => $i + 1,
                    'fields' => array_keys($own),
                    'why'    => $formRights === null ? $whyUnreadable
                        : ($condBarred
                            ? 'this rule\'s condition reads instrument ' . implode(', ', $named)
                              . ', which you do not have access to, so the rule was NOT evaluated '
                              . 'anywhere. Nothing about that instrument appears in this report.'
                            : ($whole
                                ? 'you do not have access to instrument ' . implode(', ', $named)
                                  . ', which this rule checks, so it was NOT evaluated. Nothing about '
                                  . 'that instrument appears in this report.'
                                : 'you do not have access to instrument ' . implode(', ', $named)
                                  . ', so this rule was checked only on the instrument(s) you can '
                                  . 'open. Nothing about that instrument appears in this report.')),
                ];
                if ($whole) {
                    unset($live[$i], $hostFields[$i]);
                    continue;
                }
                foreach (array_keys($hostBarred) as $form) unset($hostFields[$i][$form]);
            }
        }

        // Reassigned, because $live and $hostFields are copies taken above and
        // the gate may have removed entries from both. Leaving the earlier
        // assignment standing would evaluate rules the gate had just barred.
        $out['live']       = $live;
        $out['hostFields'] = $hostFields;
        $out['unconf']     = $unconf;
        // ONE LIST, ONE OWNER. The rule identities are derived HERE, from the
        // final $live, and keyed identically to it by construction. They used
        // to be derived a second time in durableScanContext() from a copy that
        // had been re-indexed on the way, so the two disagreed about which rule
        // an ordinal named - and since rule_source_id is hashed into the
        // finding identity, the disagreement was silent and permanent. A second
        // derivation of the same thing is a second thing that can drift.
        $out['ruleIds'] = Scan\ScanPlanner::identifyAll($live);
        if (!$live) {
            // Every rule barred is not "nothing to scan": the rule problems above
            // are the report, and they must survive. nothingToScan short-circuits
            // to a complete status, which unconfigurable[] then keeps off green.
            $out['nothingToScan'] = true;
            return $out;
        }

        // Everything the evaluation needs to read: rule fields + when/assert
        // refs + composite unique keys.
        $readSet = [];
        foreach ($live as $r) {
            if (TemporalRules::extended($r)) {
                foreach ($this->temporalReadFields([$r], $pid) as $f) $readSet[$f] = true;
            }
            foreach ($r['fields'] as $f) $readSet[$f] = true;
            foreach (ModeRegistry::refFields($r) as $f) $readSet[$f] = true;
        }
        // A project-scope unique rule cannot be evaluated from a DAG-confined
        // scan: the scan reads one group, so a value duplicated ACROSS groups is
        // invisible and the rule reports nothing. The live unique-check endpoint
        // queries the whole project and WOULD flag it, so the two disagree and
        // the scan is the one issuing certificates. Every other unevaluable
        // condition in this module lands in 'unconfigurable'; this one was
        // silent, which is the one outcome the contract forbids.
        //
        // @UVEXISTS has the same blind spot from the other side: a value saved
        // only in another group reads "not found" from a group-confined read,
        // and whether this request's read is confined depends on who runs it.
        // A rule that looks across groups is reported the same way.
        if ($dagFilter !== null) {
            foreach ($live as $i => $r) {
                $mode = ModeRegistry::modeOfType(isset($r['type']) ? $r['type'] : '');
                if ($mode === 'unique') {
                    $scope = isset($r['uniqueScope']) ? strtolower((string) $r['uniqueScope']) : 'project';
                    if ($scope !== 'project') continue;      // 'dag' and 'event' ARE evaluable here
                    $unconf[$i . '|dag-scoped-unique'] = [
                        'rule' => $i + 1,
                        'fields' => (isset($r['fields']) && is_array($r['fields'])) ? $r['fields'] : [],
                        'why' => 'this rule requires values to be unique across the WHOLE project, but this scan '
                               . 'is confined to one Data Access Group - a duplicate in another group cannot be '
                               . 'seen from here, so the rule was NOT evaluated. Run the scan without a group '
                               . 'scope to check it.',
                    ];
                } elseif ($mode === 'exists' && !self::existsConfinedToDag($r)) {
                    $out['skip'][$i] = true;
                    $unconf[$i . '|dag-scoped-exists'] = [
                        'rule' => $i + 1,
                        'fields' => (isset($r['fields']) && is_array($r['fields'])) ? $r['fields'] : [],
                        'why' => 'this rule looks for the value across every Data Access Group, but this scan is '
                               . 'confined to one group - a value saved only in another group would read as not '
                               . 'found, so the rule was NOT evaluated. Run the scan without a group scope to '
                               . 'check it.',
                    ];
                }
            }
            $out['unconf'] = $unconf;
        }

        // A rule that searches another project is checked only when that project
        // answers the person running the scan (crossScanProblem); otherwise it is
        // reported, never silently passed.
        $crossProjects = [];
        foreach ($live as $i => $r) {
            if (isset($out['skip'][$i]) || ModeRegistry::modeOfType(isset($r['type']) ? $r['type'] : '') !== 'exists') continue;
            $why = $this->crossScanProblem($pid, $r);
            if ($why === null) {
                foreach (self::existsParts($r) as $p) if (!empty($p['existsPid'])) $crossProjects[(int) $p['existsPid']] = true;
                continue;
            }
            $out['skip'][$i] = true;
            $unconf[$i . '|cross-project-exists'] = [
                'rule'   => $i + 1,
                'fields' => (isset($r['fields']) && is_array($r['fields'])) ? $r['fields'] : [],
                'why'    => 'this rule looks the value up in another project, but ' . $why . ', so the rule was NOT evaluated.',
            ];
            $out['unconf'] = $unconf;
        }

        $out['readSet'] = $readSet;

        // A @UVEXISTS verdict depends on OTHER records. The change fence and the
        // catch-up re-check the records that changed, not the records whose
        // lookup a change elsewhere moved, so a run with such a rule can vouch
        // for the list it read and no more.
        $out['crossRecordLookups'] = false;
        foreach ($live as $i => $r) {
            if (!isset($out['skip'][$i]) && ModeRegistry::modeOfType(isset($r['type']) ? $r['type'] : '') === 'exists') {
                $out['crossRecordLookups'] = true;
                break;
            }
        }
        if ($out['crossRecordLookups'] && isset($out['policy']['maxCompletion'])
                && $out['policy']['maxCompletion'] === 'complete-through-fence') {
            $out['policy']['maxCompletion'] = 'manifest-complete';
            $out['policy']['limits'][] = '@UVEXISTS rules were checked against the values saved when each part of '
                . 'the scan ran; a value saved or removed in another record during the scan was not re-checked';
            if ($crossProjects) {
                $out['policy']['limits'][] = '@UVEXISTS rules that search project ' . implode(', ', array_keys($crossProjects))
                    . ' read it as it stood when each part of the scan ran; changes saved there do not re-open this scan';
            }
        }

        // WHICH INSTRUMENT OWNS EACH FIELD THE RUN WILL READ - derived from the
        // READ SET, and from nothing else.
        //
        // It used to be built in durableScanContext() by walking
        // $plan['hostFields'], which ruleHostForms() fills from $rule['fields']
        // alone. The read is $readSet: that list PLUS every field a `when` or
        // `assert` operand references PLUS every unique-composite "with" field.
        // ScanService turns this map into the entitlement set
        // ScanAuthorization::mayStart() is asked about, so the gate was being
        // asked where the RULES LIVE while getData was being asked for the
        // OPERANDS. A designer with explicit No Access to an instrument could
        // therefore start a scan that read it, and whose findings differed by
        // its values - reproduced on every rule kind (@UVALIDATE, @UVASSERT,
        // @UVREQUIRED, @UVUNIQUE, @UVCHOICES), on branch operands, and on both
        // configuration channels. mayStart()'s own docblock already said the
        // entitlement is "every form the run will read"; the caller was the half
        // that was wrong.
        //
        // DERIVED FROM $readSet, NOT FROM ruleRefFields(). That helper computes
        // the same three sources per rule and would give the same answer today -
        // which is the problem: a second derivation of the same set is a second
        // thing that can drift, the same reason ruleIds is derived once from the
        // final $live and taken from the plan thereafter. $readSet is the array
        // durableScanContext() hands to getData, so $readSet is the only honest
        // answer to "what does this run read".
        //
        // NULL means "could not be placed", and it is the fail-closed direction:
        // ScanService reads a null or empty form as unknown ownership and
        // mayStart() refuses the run rather than dropping the field from the set.
        // Nothing could set that flag before - hostFields only ever contains
        // forms that WERE determined - so that arm of the control was dead in
        // production. A dictionary that came back unreadable is the same answer:
        // a form that cannot be read cannot clear an instrument.
        //
        // AND THAT ARM IS NOW REACHABLE FOR A RULE'S OWN FIELD, which is a real
        // behaviour change and was argued both ways. The `n|unlocatable` note at
        // the top of this method already reports such a field by name and says
        // the field is not scanned - so the case for excluding it here is that
        // the rule is already refused. That case is FALSE: $readSet above is
        // built from every live rule's `fields` unconditionally, the unlocatable
        // note does not remove the rule from $live, and $fields at :3122 is
        // array_keys($plan['readSet']). The value IS read. A field that is read
        // and cannot be placed is a field whose access cannot be checked, and
        // refusing is the only answer consistent with the rest of this file. The
        // refusal names the fields (ScanAuthorization::mayStart) so it is a
        // diagnosis rather than an undiagnosable no.
        //
        // WITH enforceFormRights ON, this map now includes a host form the
        // narrowing gate removed from $hostFields at the barred-host block
        // above. Harmless today - durableScanContext never passes that flag and
        // scanProject has no production caller - but whoever re-wires that gate
        // has to decide whether the narrowed run should be entitled to the form
        // it narrowed away, and this is where the two meet.
        $ddOwn = $this->dataDictionary($pid);
        $ownership = [];
        foreach (array_keys($readSet) as $f) {
            if (!isset($ddOwn[$f]) && substr($f, -9) === '_complete') { $ownership[(string)$f] = substr($f, 0, -9); continue; }
            $ownership[(string) $f] =
                (is_array($ddOwn) && isset($ddOwn[$f]['form_name']) && $ddOwn[$f]['form_name'] !== '')
                    ? (string) $ddOwn[$f]['form_name'] : null;
        }
        $out['ownership'] = $ownership;
        return $out;
    }

    /**
     * Evaluate every live rule against ONE record, handing findings to the sink.
     *
     * Lifted out of scanProject()'s chunk loop in 1.7.0, unchanged. Everything
     * it needs that outlives the record — the whole-project unique candidates
     * and the deduped rule problems — is threaded by reference, because both are
     * bounded by the RULE list rather than by the data.
     *
     * @return array{contexts: int, why: ?string}  'why' is set when the record
     *         could not be examined at all, which is reported, never assumed clean.
     */
    private function scanRecord(array $plan, $pid, $rec, array $node, FindingSink $sink,
                                array &$uniqueSeen, array &$unconf)
    {
        $this->temporalBegin($pid);
        $ctxAll = self::recordContexts($node);
        if (!$ctxAll) {
            // REDCap returned the record with no event row at all. There is
            // nothing to evaluate, and nothing that says the record is
            // clean — certifying it was the same silent skip as an
            // unreadable chunk, one step further down (H-05).
            return ['contexts' => 0,
                    'why' => 'record ' . $this->reportRecordId($plan, $rec)
                           . ' was returned with no data rows, so it was not checked'];
        }
        $recDag = self::dagOfRecordNode($node);
        // Resolution is a property of the CONTEXT, not of the rule that
        // happens to be asking. Computing it per rule re-derived the same
        // ownership map contexts x rules times (M-05).
        $resCache = [];
        $hostCache = [];    // host form => its contexts in THIS record; rules share hosts
        foreach ($plan['live'] as $i => $r) {
            // Reported as not evaluated by scanPlan(); evaluating it anyway would
            // add findings the same report says it cannot vouch for.
            if (isset($plan['skip'][$i])) continue;
            $mode = ModeRegistry::modeOfType(isset($r['type']) ? $r['type'] : '');
            foreach ($plan['hostFields'][$i] as $hostForm => $ownFields) {
                $onForm = array_fill_keys($ownFields, true);
                if (!isset($hostCache[$hostForm])) {
                    $hostCache[$hostForm] = $this->hostContextsFor($ctxAll, $hostForm, $pid);
                }
                foreach ($hostCache[$hostForm] as $ck => $ctx) {
                    if (!isset($resCache[$ck])) {
                        $resCache[$ck] = $this->contextResolution($ctx, array_keys($plan['readSet']), $pid);
                    }
                    $evaluatedRule = $r;
                    $evaluatedMode = $mode;
                    if (TemporalRules::extended($r)) {
                        if ($this->temporalBudget->exhausted()) {
                            $unconf[$i.'|temporal-budget']=['rule'=>$i+1,'fields'=>$ownFields,'why'=>'Extended record evaluation budget exhausted; this record was not fully checked.'];
                            break;
                        }
                        if (!isset($temporalShape)) $temporalShape = $this->temporalShape($pid);
                        $prepared = $this->temporalPrepared($r, $temporalShape, $node, $this->temporalContext($ctx, $hostForm));
                        if ($prepared['problems']) {
                            $unconf[$i . '|temporal|' . $ck] = ['rule'=>$i+1, 'fields'=>$ownFields,
                                'why'=>'Extended validation unavailable at event ' . $ctx['event_id'] . ', instance ' . $ctx['instance'] . ': ' . implode(', ', $prepared['problems'])];
                            continue;
                        }
                        $evaluatedRule = $prepared['rule'];
                        $evaluatedMode = ModeRegistry::modeOfType($evaluatedRule['type'] ?? '');
                    }
                    if ($evaluatedMode === 'unique' && !isset($evaluatedRule['uniqueRecordResults'])) {
                        self::collectUniqueCandidates($uniqueSeen, $unconf, $evaluatedRule, $i, $ctx, $rec, $recDag, $plan['dupes'], $onForm, $resCache[$ck], $hostForm, $plan);
                        continue;
                    }
                    // A scan request answers @UVEXISTS from one index per rule, not one
                    // whole-project read per record (findingsExists).
                    $f = $this->ruleFindings($evaluatedRule, $i, $ctx['values'], $plan['dupes'], $onForm, $pid, $rec, $ctx['event_id'], null, $resCache[$ck], ['dag' => $recDag, 'existsIndex' => true]);
                    foreach ($f['invalid'] as $v) {
                        // Computed ONCE, and compared with === false. A truthiness
                        // test here would turn a legitimate value of '0' into null.
                        $rv = self::reportValue($v, $plan);
                        $sink->violation([
                            'record' => $this->reportRecordId($plan, $rec), 'event_id' => $ctx['event_id'],
                            'instance' => $ctx['instance'], 'field' => $v['field'],
                            'type' => $v['type'], 'reason' => $v['reason'], 'rule' => $i + 1,
                            'value' => ($rv === false) ? null : $rv,
                            'valueWithheld' => ($rv === false),
                            // RAW, and never through reportValue(). The
                            // discriminator is part of the LOCATION; routing it
                            // through the value path would null it under a
                            // withholding policy, and the collision this exists
                            // to prevent would come back on exactly the privacy
                            // setting the module recommends.
                            'locus' => isset($v['locus']) ? (string) $v['locus'] : '',
                            // $hostForm, NOT $ctx['instrument']: that is null for
                            // every base row (:2297) and deliberately null for a
                            // repeating-EVENT context (:2320), which between them
                            // is most projects.
                            'instrument' => $hostForm, 'dag' => $recDag,
                        ]);
                    }
                    foreach ($f['unconfigurable'] as $u) {
                        $key = $i . '|' . $u['why'];
                        if (!isset($unconf[$key])) {
                            $unconf[$key] = ['rule' => $i + 1, 'fields' => $u['fields'], 'why' => $u['why']];
                        }
                    }
                }
            }
        }
        return ['contexts' => count($ctxAll), 'why' => null];
    }

    /**
     * 'time' | 'memory' | null — why the chunk loop must stop now.
     *
     * Split out from the loop so the decision can be tested WITHOUT asking PHP
     * to enforce a real limit: setting max_execution_time inside a test kills
     * the test process (the timer is wall-clock on Windows and does not reset),
     * and setting memory_limit low enough to trip is one allocation away from a
     * fatal. A null bound means "no limit known", which never halts — declining
     * to guess, because a guard that fires on a misread would stop healthy scans
     * and report them as incomplete.
     */
    private static function scanHalt($deadline, $memCap, $now, $usage)
    {
        if ($deadline !== null && $now >= $deadline) return 'time';
        if ($memCap !== null && $usage >= $memCap) return 'memory';
        return null;
    }

    /**
     * PHP's memory_limit in bytes, or 0 when there is no limit or it cannot be
     * read. Shorthand suffixes are case-insensitive and BINARY (1M = 1048576),
     * per PHP's own ini parser; a bare number is already bytes, and -1 means
     * unlimited. Returning 0 for "unknown" is deliberate: the caller then
     * imposes no cap, because a guard that fires on a misparse would stop
     * healthy scans and report them as incomplete.
     */
    private static function memoryLimitBytes()
    {
        return self::parseByteSize((string) ini_get('memory_limit'));
    }

    /**
     * One PHP shorthand byte size in bytes; 0 for unlimited or unreadable.
     * Pure, and separate from memoryLimitBytes() so it can be tested directly:
     * ini_set('memory_limit', ...) REFUSES any value below current usage, so a
     * test that went through the ini would silently assert against whatever the
     * limit already was.
     */
    private static function parseByteSize($raw)
    {
        $raw = trim($raw);
        if ($raw === '' || $raw === '-1') return 0;
        if (!preg_match('/^(\d+(?:\.\d+)?)\s*([KMG])?$/i', $raw, $m)) return 0;
        $n = (float) $m[1];
        switch (isset($m[2]) ? strtoupper($m[2]) : '') {
            case 'G': $n *= 1024; // fall through
            case 'M': $n *= 1024; // fall through
            case 'K': $n *= 1024;
        }
        return (int) $n;
    }

    /**
     * Every value context of one record node: the plain event rows, plus each
     * repeat instance merged over its event row (a repeat row wins where both
     * carry a field — the same precedence readValues applies).
     */
    private static function recordContexts(array $recordNode)
    {
        $out = [];
        foreach ($recordNode as $k => $node) {
            if ($k === 'repeat_instances' || !is_array($node)) continue;
            $out[] = ['event_id' => $k, 'instance' => 1, 'instrument' => null,
                      // 'repeatKey' is the raw bucket this row came from: null for
                      // the event's base row, '' for a repeating EVENT instance,
                      // the form name for a repeating FORM instance. 'instrument'
                      // cannot carry that distinction — it is deliberately null for
                      // the repeating-event bucket (every form shares it), which
                      // makes a repeating-event row indistinguishable from a base
                      // row. hostContextsFor() needs to tell them apart to decide
                      // where a rule actually lives (H-02).
                      'repeatKey' => null,
                      'node' => $recordNode, 'values' => self::cleanRow($node)];
        }
        if (isset($recordNode['repeat_instances']) && is_array($recordNode['repeat_instances'])) {
            foreach ($recordNode['repeat_instances'] as $evt => $byInstr) {
                if (!is_array($byInstr)) continue;
                $base = (isset($recordNode[$evt]) && is_array($recordNode[$evt])) ? self::cleanRow($recordNode[$evt]) : [];
                foreach ($byInstr as $formKey => $byInst) {
                    if (!is_array($byInst)) continue;
                    foreach ($byInst as $inst => $row) {
                        if (!is_array($row)) continue;
                        $out[] = ['event_id' => $evt, 'instance' => $inst,
                                  // '' is the repeating-EVENT bucket: shared by
                                  // every form, so nothing is instrument-scoped.
                                  'instrument' => ($formKey === '' ? null : $formKey),
                                  'repeatKey' => $formKey,
                                  'node'   => $recordNode,
                                  'values' => array_merge($base, self::cleanRow($row))];
                    }
                }
            }
        }
        return $out;
    }

    /**
     * The instruments that HOST one rule, as form_name => [its fields], plus the
     * fields whose owning form could not be determined.
     *
     * A rule lives where its OWN fields live — not wherever the caller happened
     * to be standing. Evaluating a rule in an arbitrary context is not a
     * near-miss, it produces confident nonsense: a populated repeating field
     * reported blank because the base row was examined, a populated event-1
     * field reported blank in event 2, and the same rule declared both
     * unconfigurable and hard-violated for one record (H-02). The save audit's
     * reverse-dependency pass had the same defect in a different shape, logging
     * one copy of a base-form violation per unrelated repeat row (H-03), and the
     * unique aggregator inherited it too (H-04).
     *
     * A rule may legitimately span forms (a pooled rule over fields on two
     * instruments); each host is returned separately so the rule is evaluated
     * once per host, over that host's fields only.
     */
    private function ruleHostForms(array $rule, $pid)
    {
        $out = ['forms' => [], 'unknown' => []];
        $dd = $this->dataDictionary($pid);
        foreach ((isset($rule['fields']) && is_array($rule['fields'])) ? $rule['fields'] : [] as $f) {
            $form = ($dd && isset($dd[$f]['form_name']) && $dd[$f]['form_name'] !== '') ? $dd[$f]['form_name'] : null;
            if ($form === null) { $out['unknown'][] = $f; continue; }
            $out['forms'][$form][] = $f;
        }
        return $out;
    }

    /**
     * Of one record's contexts, the ones in which $form's fields actually live,
     * keys preserved so the caller can reuse a per-context resolution cache.
     *
     * Three questions, answered from the SAME signals resolveOne() uses so the
     * two can never disagree: is $form designated for this event at all; does
     * the EVENT repeat; does $form itself repeat here.
     */
    private function hostContextsFor(array $contexts, $form, $pid)
    {
        $out = [];
        $shape = [];    // event id => ['repeats' => bool|null, 'eventRepeats' => bool, 'mapped' => bool]
        foreach ($contexts as $k => $ctx) {
            $evt = $ctx['event_id'];
            if (!isset($shape[$evt])) {
                $rec = (isset($ctx['node']) && is_array($ctx['node'])) ? $ctx['node'] : [];
                $byEvent = (isset($rec['repeat_instances'][$evt]) && is_array($rec['repeat_instances'][$evt]))
                    ? $rec['repeat_instances'][$evt] : null;
                $eventForms = $this->formsForEvent($pid, $evt);
                $repeating  = $this->repeatingFormsForEvent($pid, $evt, [$form]);
                $byMeta   = is_array($repeating) ? isset($repeating[$form]) : null;
                $byBucket = is_array($byEvent) ? array_key_exists($form, $byEvent) : null;
                $repeats = null;
                if ($byMeta === true || $byBucket === true) $repeats = true;
                elseif ($byMeta === false || $byBucket === false) $repeats = false;
                $shape[$evt] = [
                    // A NULL mapping means "cannot tell" (classic project, or a
                    // build without the API) and must fail OPEN, exactly as
                    // contextResolution's own off-event check does.
                    'mapped'       => ($eventForms === null) ? true : isset($eventForms[$form]),
                    'eventRepeats' => is_array($byEvent) && array_key_exists('', $byEvent),
                    'repeats'      => $repeats,
                ];
            }
            $s = $shape[$evt];
            if (!$s['mapped']) continue;                       // this form is not collected in this event
            $rk = array_key_exists('repeatKey', $ctx) ? $ctx['repeatKey'] : null;
            if ($s['eventRepeats']) {
                // Every form in a repeating event is instance-scoped; the base row
                // is folded into each instance, so evaluating it too would double
                // every finding.
                if ($rk !== '') continue;
            } elseif ($s['repeats'] === true) {
                if ($rk !== $form) continue;                   // only this form's own instances
            } else {
                if ($rk !== null) continue;                    // base row only
            }
            $out[$k] = $ctx;
        }
        return $out;
    }

    /**
     * Resolution states for ONE scan context, computed with the SAME resolver
     * the form hooks and the save audit use. Previously the scan had its own,
     * weaker rule (ambiguous only, inferred from which fields happened to
     * appear in repeat rows), so it reported hard violations for data the save
     * path declared unconfigurable, and missed off-event references entirely
     * (H-04). Divergence here is a correctness bug by construction, so there is
     * now exactly one implementation.
     */
    private function contextResolution(array $ctx, array $fields, $pid)
    {
        $res = [];
        if (!$fields) return $res;
        $rec = (isset($ctx['node']) && is_array($ctx['node'])) ? $ctx['node'] : [];
        $evt = $ctx['event_id'];
        $inst = (int) ($ctx['instance'] ?: 1);
        $instrument = isset($ctx['instrument']) ? $ctx['instrument'] : null;

        $byEvent = null;
        if (isset($rec['repeat_instances'][$evt]) && is_array($rec['repeat_instances'][$evt])) {
            $byEvent = $rec['repeat_instances'][$evt];
        }
        $formOf = [];
        $dd = $this->dataDictionary($pid);
        if ($dd) foreach ($fields as $f) {
            if (isset($dd[$f]['form_name'])) $formOf[$f] = $dd[$f]['form_name'];
        }
        $eventForms = $this->formsForEvent($pid, $evt);
        $repeating  = $this->repeatingFormsForEvent($pid, $evt, array_values($formOf));

        foreach ($fields as $f) {
            if ($eventForms !== null && isset($formOf[$f]) && !isset($eventForms[$formOf[$f]])) {
                $res[$f] = 'missing';
                continue;
            }
            $r = self::resolveOne($f, $rec, $byEvent, $formOf, $repeating, $evt, $instrument, $inst);
            if ($r['state'] !== 'ok') $res[$f] = $r['state'];
        }
        return $res;
    }


    /** Drop empty values from a data row (mirrors readValues: missing == empty). */
    private static function cleanRow(array $row)
    {
        $out = [];
        foreach ($row as $f => $v) {
            if ($v === null || $v === '') continue;
            $out[$f] = is_array($v) ? $v : (is_string($v) ? $v : (string) $v);
        }
        return $out;
    }

    /**
     * Collect one context's candidate values for a unique rule into the
     * aggregate map. The group key mirrors findCollision's semantics: the
     * trimmed value + composite "with" values, widened by the scope (event id
     * for scope=event, the record's DAG for scope=dag). Branch rules resolve
     * their active branch against this context first.
     */
    private static function collectUniqueCandidates(array &$seen, array &$unconf, array $rule, $ruleIndex, array $ctx, $rec, $recDag, array $dupes, $onForm = null, array $resolution = [], $hostForm = null, array $plan = [])
    {
        // Every reference this aggregation consumes goes through the SAME
        // resolution the rest of the scan uses. Without it the composite key was
        // built by substituting '' for anything unresolvable, so two records whose
        // composite field lives on an independently repeating instrument — with no
        // defined pairing between their instances — collapsed to the same key and
        // were reported as duplicates of each other, with nothing said about why
        // (H-04). An undefined pairing is refused, never guessed.
        $refuse = function ($why, $suffix) use (&$unconf, $ruleIndex, $rule) {
            $unconf[$ruleIndex . '|unique-' . $suffix] = ['rule' => $ruleIndex + 1,
                'fields' => $rule['fields'], 'why' => $why];
        };
        $unresolved = function (array $ast) use ($resolution) {
            foreach (Logic::referencedFields($ast) as $ref) {
                $state = isset($resolution[$ref[0]]) ? $resolution[$ref[0]] : 'ok';
                if ($state !== 'ok') return [$state, $ref[0]];
            }
            return null;
        };

        $cfg = $rule;
        if (isset($rule['branches']) && is_array($rule['branches'])) {
            $active = [];
            $else = null;
            foreach ($rule['branches'] as $bi => $b) {
                if (!isset($b['when']) || !is_string($b['when']) || $b['when'] === '') { $else = $bi; continue; }
                $p = Logic::parse($b['when']);
                if (empty($p['ok'])) {
                    // A branch condition that no longer parses means the value is not
                    // checked here — surface it rather than a silent skip (M-05).
                    $refuse('a unique-rule branch "when" cannot be evaluated — the value is not checked', 'branch-unparseable');
                    return;
                }
                if (($u = $unresolved($p['ast'])) !== null) {
                    $refuse('a unique-rule branch "when" condition ' . self::resolutionProblem($u[0], $u[1])
                        . ' No branch can be chosen, so the value is not checked here.', 'branch-unresolved');
                    return;
                }
                if (Logic::evaluate($p['ast'], $ctx['values'], Logic::BLANK_INERT, !empty($b['caseSensitive']))) $active[] = $bi;
            }
            if (count($active) === 1) {
                $pick = $active[0];
            } elseif (!count($active) && $else !== null) {
                $pick = $else;
            } else {
                if (count($active) > 1) {
                    // Two branch conditions true at once — mirror the non-unique scan
                    // and the live client, which report this rather than guess (M-05).
                    $refuse('more than one unique-rule "when" is true for a record (branch conflict) — the value is not checked', 'branch-conflict');
                }
                return;
            }
            $b = $rule['branches'][$pick];
            unset($b['when']);
            $cfg = array_merge(['type' => 'unique', 'fields' => $rule['fields']], $b);
        }
        if (isset($cfg['when']) && is_string($cfg['when']) && $cfg['when'] !== '') {
            $p = Logic::parse($cfg['when']);
            if (empty($p['ok'])) {
                $refuse('the unique rule\'s "when" condition cannot be evaluated — the value is not checked', 'when-unparseable');
                return;
            }
            if (($u = $unresolved($p['ast'])) !== null) {
                $refuse('the unique rule\'s "when" condition ' . self::resolutionProblem($u[0], $u[1]), 'when-unresolved');
                return;
            }
            if (!Logic::evaluate($p['ast'], $ctx['values'], Logic::BLANK_INERT, !empty($cfg['caseSensitive']))) return;
        }
        $with  = (isset($cfg['uniqueWith']) && is_array($cfg['uniqueWith'])) ? $cfg['uniqueWith'] : [];
        $scope = isset($cfg['uniqueScope']) ? $cfg['uniqueScope'] : 'project';
        // A composite key is only meaningful when every part of it was actually
        // read for THIS row. One unreadable part makes the whole tuple undefined.
        foreach ($with as $w) {
            $state = isset($resolution[$w]) ? $resolution[$w] : 'ok';
            if ($state !== 'ok') {
                $refuse('the unique rule\'s composite key ' . self::resolutionProblem($state, $w), 'with-unresolved');
                return;
            }
        }
        // M1: A 'dag' SCOPE NEEDS A DAG, AND A RECORD IN NO GROUP HAS NONE.
        //
        // The bucket key below appends (string) $recDag, and $recDag is null for
        // a record REDCap returned with no redcap_data_access_group. (string)
        // null is '', so every ungrouped record in the project fell into ONE
        // bucket and any two of them sharing a value were reported as duplicates
        // OF EACH OTHER - under a rule whose entire meaning is "unique within a
        // Data Access Group", for records that are not in one. The rule's
        // question has no answer for these records, and this module's contract
        // is that an unevaluable condition is reported, never answered wrongly.
        //
        // A PROPERTY OF THE RECORD, NOT OF THE RULE. The same rule stays live and
        // is still evaluated on every record that does have a group; only this
        // record's contribution is withheld. $refuse() keys $unconf by
        // ruleIndex|suffix, so a project with ten thousand ungrouped records
        // produces ONE rule problem rather than ten thousand - which is the
        // deduplication every other refusal in this function relies on and the
        // reason the report stays bounded.
        //
        // AFTER the composite-key loop above, so a rule broken in two ways
        // reports the more specific problem first, and a `return` rather than a
        // `continue`, because the group is a property of the CONTEXT and not of
        // any one field - matching every other context-level refusal here.
        if ($scope === 'dag' && ($recDag === null || (string) $recDag === '')) {
            $refuse('this rule requires values to be unique within a Data Access Group, and this '
                . 'record is not in one, so the rule was NOT evaluated for it. Records with no '
                . 'group are not compared with each other.', 'dag-no-group');
            return;
        }
        foreach ($rule['fields'] as $field) {
            if (isset($dupes[$field])) continue;
            if ($onForm !== null && !isset($onForm[$field])) continue;
            $state = isset($resolution[$field]) ? $resolution[$field] : 'ok';
            if ($state !== 'ok') {
                $refuse('the unique rule ' . self::resolutionProblem($state, $field), 'field-unresolved');
                continue;
            }
            $v = isset($ctx['values'][$field]) ? $ctx['values'][$field] : null;
            if ($v === null || is_array($v) || trim((string) $v) === '') continue;
            // Collision-free, LOSSLESS composite key (L-01, L01-UTF8-COLLAPSE): a raw
            // byte in a value (a 0x1F separator, or an invalid-UTF8 byte from a Latin-1
            // import) must not let two DISTINCT tuples share a key and read as a false
            // duplicate. bin2hex encodes every byte injectively and '.' cannot appear
            // in hex output, so the joined key round-trips uniquely — unlike
            // json_encode with JSON_INVALID_UTF8_SUBSTITUTE, which collapsed distinct
            // invalid-UTF8 values to U+FFFD. findCollision compares raw bytes, so the
            // key must too (keeps the scan and the audit in agreement).
            $keyParts = [(string) $ruleIndex, $field, trim((string) $v)];
            foreach ($with as $w) {
                $keyParts[] = (isset($ctx['values'][$w]) && !is_array($ctx['values'][$w])) ? trim((string) $ctx['values'][$w]) : '';
            }
            if ($scope === 'event') { $keyParts[] = 'evt'; $keyParts[] = (string) $ctx['event_id']; }
            elseif ($scope === 'dag') { $keyParts[] = 'dag'; $keyParts[] = (string) $recDag; }
            $key = '';
            foreach ($keyParts as $kp) $key .= bin2hex($kp) . '.';
            $seen[$key][] = ['record' => (string) $rec, 'event_id' => $ctx['event_id'],
                             'instance' => $ctx['instance'], 'field' => $field, 'rule' => $ruleIndex + 1,
                             // Kept RAW here and filtered at emit time: the value
                             // is already inside $key, so this costs nothing, and
                             // the report policy lives where the plan is in scope.
                             // The REPORTABLE form, decided now rather than kept
                             // raw to the end. The key above already carries
                             // bin2hex(trim($v)), so holding the raw value too was
                             // roughly three times the bytes per candidate,
                             // retained project-wide - including in locations
                             // mode, where it can never be shown at all. On a
                             // @UVUNIQUE rule over a Notes field that was the most
                             // expensive thing in the scan.
                             'value' => self::reportValue(['field' => $field, 'value' => $v], $plan),
                             'instrument' => $hostForm, 'dag' => $recDag];
        }
    }

    // -- uniqueness (@UVUNIQUE): live endpoint + shared lookup ---------------

    /**
     * The durable scan's AJAX verbs.
     *
     * Thin on purpose. Everything that decides anything - the feature flags,
     * the schema health, the rights, the scope, the run's own state - lives in
     * ScanService, so this method reads a run id, calls one of four things, and
     * hands back what it said. A handler that made decisions would be a second
     * place those decisions live.
     *
     * THE RUN ID IS A LOCATOR, NEVER AN AUTHORISATION. It is cast to an integer
     * and bound to $project_id inside the service before any answer can
     * distinguish "no such run" from "not yours" - which is why every refusal
     * below shares one sentence.
     */
    private function scanAction($action, $project_id, $payload)
    {
        // A FATAL MUST NOT BECOME AN EMPTY 200.
        //
        // The try/catch below handles anything that is a Throwable, which is
        // most things - but not memory exhaustion, and not the request simply
        // running out of time. Those end the process with no output at all, and
        // the client then gets HTTP 200 with an empty body: a success status
        // over nothing. The pilot lost a round to exactly that, because an empty
        // 200 is indistinguishable from a broken client and gives nobody a
        // thread to pull.
        //
        // The shutdown handler cannot rescue the request. It can make the
        // failure SAY something, which is the difference between a bug report
        // and a shrug.
        $answered = false;
        register_shutdown_function(function () use (&$answered) {
            if ($answered) return;
            $e = error_get_last();
            if ($e === null) return;
            $fatal = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR];
            if (!in_array($e['type'], $fatal, true)) return;
            // The MESSAGE is not echoed: it names paths and can quote the
            // statement. The KIND is, because "ran out of memory" and "ran out
            // of time" need different answers and the caller can act on neither
            // if told nothing.
            $why = (stripos($e['message'], 'memory') !== false)
                 ? 'this scan ran out of memory partway through a batch; nothing from it was kept'
                 : 'this scan stopped unexpectedly partway through a batch; nothing from it was kept';
            if (!headers_sent()) header('Content-Type: application/json');
            echo json_encode(['ok' => false, 'stop' => 'fatal', 'why' => $why]);
        });

        try {
            $svc = new Scan\ScanService($this);
            $runId = (isset($payload['run_id']) && is_scalar($payload['run_id']))
                   ? (int) $payload['run_id'] : 0;

            if ($action === 'scan-start') {
                $r = $svc->start($project_id);
                $answered = true;
                return $r;
            }
            if ($runId <= 0) {
                $answered = true;
                return ['ok' => false, 'why' => Scan\ScanService::NO_RUN];
            }
            if ($action === 'scan-work') {
                $r = $svc->work($project_id, $runId, 'browser');
                $answered = true;
                return $r;
            }
            if ($action === 'scan-status') { $answered = true; return $svc->status($project_id, $runId); }
            if ($action === 'scan-cancel') { $answered = true; return $svc->cancel($project_id, $runId); }
            $answered = true;
            return ['ok' => false, 'why' => 'unknown action'];
        } catch (\Throwable $e) {
            $answered = true;
            // Never leaks the exception. A class name or a message from here can
            // describe the installation's schema and its database user, to a
            // caller who has just been told the answer is no.
            return ['ok' => false,
                    'why' => 'the validation scan could not be reached; ask an administrator to '
                           . 'check the module log'];
        }
    }

    /**
     * Live uniqueness endpoint (framework AJAX). The client sends the field
     * name and the CANDIDATE values (the field's own, plus the composite
     * "with" fields'); everything else — scope, composite key, eligibility —
     * is re-derived from the module's own stored rules, never trusted from
     * the page. Anti-oracle: only a field covered by a live unique rule is
     * answered at all, so this cannot be used to probe arbitrary fields for
     * value existence.
     *
     * Survey requests (no-auth) are answered ONLY when the rule opted in
     * ("surveys": true) and always with a boolean — never a record id. For
     * authenticated staff the colliding record id is included only when it
     * is inside the user's Data Access Group (or the user has none).
     */
    public function redcap_module_ajax($action, $payload, $project_id, $record, $instrument, $event_id, $repeat_instance, $survey_hash, $response_id, $survey_queue_hash, $page, $page_full, $user_id, $group_id)
    {
        // THE DURABLE SCAN'S FOUR VERBS. Declared in auth-ajax-actions ONLY - a
        // scan reads and stores record values, so there is no version of it an
        // unauthenticated caller may reach. $user_id is the only value here that
        // means REDCap authenticated this caller; the framework's own action
        // list is a first gate, and this is the second, because
        // redcap_module_ajax() guards the action NAME and hands the identity
        // straight through without checking it.
        if (in_array($action, ['scan-start', 'scan-work', 'scan-status', 'scan-cancel'], true)) {
            if ($user_id === null || $user_id === '') {
                return ['ok' => false, 'why' => 'you are not signed in'];
            }
            if (!$project_id) {
                return ['ok' => false, 'why' => 'this action only works inside a project'];
            }
            return $this->scanAction($action, $project_id, $payload);
        }

        if ($action === 'exists-check') {
            return $this->existsCheck($payload, $project_id, $record, $instrument, $event_id, $repeat_instance,
                $survey_hash, $user_id, $group_id);
        }
        if ($action !== 'unique-check') return ['error' => 'unknown action'];
        try {
            // AUTHENTICATION, not survey-ness, decides which guards apply.
            //
            // "unique-check" is declared in no-auth-ajax-actions, so this route
            // is reachable with NO session and NO survey hash. v1.4.1 keyed its
            // guards on $survey_hash, which meant an unauthenticated caller who
            // simply OMITTED the hash was treated as staff: the surveys opt-in
            // check, the Identifier refusal and the rate limit were all skipped
            // and the endpoint still answered used/free — an unthrottled
            // existence oracle on exactly the identifying fields v1.4.1 set out
            // to protect. Defeated by leaving a parameter out. (Adversarial
            // review of v1.4.1; the tests only covered (hash,null) and
            // (null,staff) — never (null,null).)
            //
            // $user_id is the only value here that means "REDCap authenticated
            // this caller"; a survey hash is caller-supplied and proves nothing.
            $isAuthenticated = ($user_id !== null && $user_id !== '');
            $isSurvey = ($survey_hash !== null && $survey_hash !== '');
            $field = (isset($payload['field']) && is_string($payload['field']))
                ? strtolower(trim($payload['field'])) : '';
            if ($field === '' || !preg_match('/^[a-z][a-z0-9_]*$/', $field)) {
                return ['error' => 'not a checkable field'];
            }
            $raw = (isset($payload['values']) && is_array($payload['values'])) ? $payload['values'] : [];
            if (count($raw) > 8) return ['error' => 'too many values'];
            $values = [];
            foreach ($raw as $k => $v) {
                if (!is_string($k) || (!is_string($v) && !is_numeric($v))) continue;
                $v = (string) $v;
                if (strlen($v) > 1024) return ['error' => 'value too long'];
                $values[strtolower($k)] = $v;
            }

            $live = $this->liveCondValues($payload, $project_id, $instrument);
            if ($live === null) return ['error' => 'malformed request'];
            $rule = $this->uniqueRuleFor($this->getRules($project_id), $field, $project_id, $record, $event_id, $instrument, $repeat_instance, $isAuthenticated && !$isSurvey, $live);
            if ($rule === null) return ['error' => 'not a checkable field'];
            if (!$isAuthenticated) {
                // An unauthenticated caller gets an answer ONLY for a rule whose
                // designer opted surveys in...
                if (empty($rule['uniqueSurveys'])) return ['error' => 'not enabled on surveys'];
                // ...never for an identifying field (the configuration channels
                // already refuse that opt-in; this re-check is what actually
                // holds the line for a caller who skips the survey machinery
                // altogether — security scan 15 Jul 2026 advisory)...
                //
                // FAIL CLOSED when identifier status is UNVERIFIABLE (F3). All
                // three identifier gates (the two config channels and this one)
                // read the same data dictionary; projectIdentifierFields() returns
                // null on any getDataDictionary failure/empty result, and
                // isIdentifier(null, …) is false — so a transient dictionary read
                // failure would silently reopen the unauthenticated existence
                // oracle this check exists to close. findCollision() does NOT need
                // the dictionary, so it would still answer. Refuse instead: an
                // unauthenticated caller loses only a convenience (the post-save
                // audit and the Validation scan still cover the field), while a
                // known-identifier field stays protected even when the dictionary
                // momentarily cannot be read.
                // The refusal covers the primary field AND every composite "with"
                // field (H-01), not just the primary: an "already used" answer whose
                // key includes an identifying value is the same existence oracle.
                $identifiers = $this->projectIdentifierFields($project_id);
                $withFields = (isset($rule['uniqueWith']) && is_array($rule['uniqueWith'])) ? $rule['uniqueWith'] : [];
                if ($identifiers === null
                        || self::firstIdentifier($identifiers, array_merge([$field], $withFields)) !== null) {
                    return ['error' => 'not enabled on surveys'];
                }
                // ...and never faster than the throttle allows.
                if ($this->surveyRateLimited($project_id)) return ['error' => 'too many checks — slow down'];
            } else {
                // A signed-in caller is throttled too, and answered only about
                // forms they may open: "already used" on a field of a form the
                // user has no access to is a read of that form by another door.
                if ($this->signedInRateLimited($project_id)) return ['error' => 'too many checks — slow down'];
                $withFields = (isset($rule['uniqueWith']) && is_array($rule['uniqueWith'])) ? $rule['uniqueWith'] : [];
                if ($this->firstUnreadableField($project_id, $user_id, array_merge([$field], $withFields)) !== null) {
                    return ['error' => 'not a checkable field'];
                }
                // The record's saved values are read below; one of another
                // Data Access Group is not this caller's to read.
                if ($this->recordBeyondCallerGroup($project_id, $record, $group_id)) {
                    return ['error' => 'record not available'];
                }
            }

            $with  = (isset($rule['uniqueWith']) && is_array($rule['uniqueWith'])) ? $rule['uniqueWith'] : [];
            $scope = isset($rule['uniqueScope']) ? $rule['uniqueScope'] : 'project';
            // A date arrives as the field shows it; the comparison is on stored values.
            $values = $this->storedFormOf($project_id, $values);

            // Resolve composite "with" values the browser could not read (H-03). A
            // field that is not on the rendered instrument is sent as "" by the
            // client, which would compare against blank and MISS a real collision.
            // For such a field, read its saved value on the server (authoritative);
            // a field ON the instrument keeps the client's live value (unsaved edits
            // count), mirroring how "when"/"assert" conditions fold. The resolved
            // values feed ONLY the in-PHP comparison — nothing off-page is ever
            // returned to the page, so no record value leaks (SEC-005 posture holds).
            if ($with && $record !== null && $record !== '') {
                $onForm = $this->fieldsOnInstrument($project_id, $instrument);
                $offPage = [];
                foreach ($with as $w) {
                    // Sent by the page: its value counts, unsaved edits included.
                    // Left out (not on this page: another form, or another page
                    // of a multi-page survey): read from the saved record. A
                    // blank sent for a field of another form counts as left out.
                    if (array_key_exists($w, $values)
                            && ($values[$w] !== '' || ($onForm !== null && isset($onForm[$w])))) continue;
                    $offPage[] = $w;
                }
                if ($offPage) {
                    try {
                        $saved = $this->readValues($project_id, $record, $offPage, $event_id, $instrument, $repeat_instance, false);
                        foreach ($offPage as $w) {
                            if (isset($saved[$w]) && !is_array($saved[$w])) $values[$w] = (string) $saved[$w];
                        }
                    } catch (\Throwable $e) {
                        // Read failure: keep the client's blank — fails OPEN (never a
                        // false "used"), consistent with the endpoint's catch-all below.
                    }
                }
            }
            // $narrow = true: this is the live endpoint, the amplification vector —
            // let REDCap filter to candidate matches instead of exporting the whole
            // project (F4). The post-save audit's findCollision call keeps the full,
            // authoritative scan.
            $col = $this->findCollision($project_id, $field, $with, $scope, $values, $record, $event_id, $group_id, true);
            if ($col === null) return ['used' => false, 'record' => null];

            // The colliding record id goes ONLY to an authenticated user, and a
            // survey page never names a record even if a staff session happens
            // to be open in the same browser.
            $recOut = null;
            if ($isAuthenticated && !$isSurvey) {
                $recOut = $col['record'];
                if ($group_id !== null && $group_id !== '') {
                    // A DAG-bound user may learn THAT the value is used, but a
                    // record id outside their DAG is not theirs to see.
                    $userDag = ScanPageView::dagNameOf($group_id);
                    if ($userDag === null || $col['dag'] !== $userDag) $recOut = null;
                }
            }
            return ['used' => true, 'record' => $recOut];
        } catch (\Throwable $e) {
            return ['error' => 'unique check failed']; // client fails open; no detail leaks
        }
    }

    /**
     * The live @UVEXISTS lookup: is this value already saved where the rule
     * looks? Same posture as unique-check, in this order:
     *   1. the field must carry a live exists rule; where to look is re-read
     *      from that stored rule, never taken from the request;
     *   2. an unauthenticated caller (a survey) is answered only for a rule that
     *      opted in, never when any field the lookup touches is an Identifier
     *      (unreadable flags refuse), and never faster than the survey throttle;
     *   3. a signed-in caller is throttled per session and answered only when
     *      they may open every form the lookup reads;
     *   4. "match" fields the page did not send are read from the saved record;
     *   5. a rule that searches another project then meets crossLookup(): that
     *      project's agreement, the caller's standing there, its budget;
     *   6. the reply is found / not-found / unknown. A survey never gets a reason
     *      or a record; staff in a DAG get the record only when it is in their
     *      own group, staff in none get it always. A record of another project
     *      is never returned.
     * The branch of a branched rule is the one the page is enforcing: chosen
     * from the values the page sent for its "when" fields ("cond"), and from
     * saved values for every other field (activeRuleFor).
     * Every failure answers unknown or an error, which the browser shows as
     * "could not check" and never blocks on.
     */
    private function existsCheck($payload, $project_id, $record, $instrument, $event_id, $repeat_instance, $survey_hash, $user_id, $group_id)
    {
        $unknown = function ($why = null) use ($user_id, $survey_hash) {
            $staff = ($user_id !== null && $user_id !== '') && ($survey_hash === null || $survey_hash === '');
            return ['state' => 'unknown', 'record' => null, 'why' => $staff ? $why : null];
        };
        try {
            $isAuthenticated = ($user_id !== null && $user_id !== '');
            $isSurvey = ($survey_hash !== null && $survey_hash !== '');
            $field = (isset($payload['field']) && is_string($payload['field'])) ? strtolower(trim($payload['field'])) : '';
            if ($field === '' || !preg_match('/^[a-z][a-z0-9_]*$/', $field)) return ['error' => 'not a checkable field'];
            $raw = (isset($payload['values']) && is_array($payload['values'])) ? $payload['values'] : [];
            if (count($raw) > 8) return ['error' => 'too many values'];
            $values = [];
            foreach ($raw as $k => $v) {
                if (!is_string($k) || (!is_string($v) && !is_numeric($v))) continue;
                $v = (string) $v;
                if (strlen($v) > 1024) return ['error' => 'value too long'];
                $values[strtolower($k)] = $v;
            }
            $live = $this->liveCondValues($payload, $project_id, $instrument);
            if ($live === null) return ['error' => 'malformed request'];
            $rule = $this->activeRuleFor($this->getRules($project_id), 'exists', $field, $project_id, $record,
                $event_id, $instrument, $repeat_instance, false, $live);
            if ($rule === null) return ['error' => 'not a checkable field'];
            $locals = (isset($rule['existsLocal']) && is_array($rule['existsLocal'])) ? $rule['existsLocal'] : [];
            $targets = (isset($rule['existsTargets']) && is_array($rule['existsTargets'])) ? $rule['existsTargets'] : [];
            if (!$isAuthenticated) {
                if (empty($rule['existsSurveys'])) return ['error' => 'not enabled on surveys'];
                $touch = array_merge([$field], $locals, $targets);
                // Another project's fields are checked there (crossCaller).
                if (($rule['existsIn'] ?? null) === 'record' && empty($rule['existsPid'])) {
                    $pk = $this->recordIdFieldOf($project_id);
                    if ($pk === null) return ['error' => 'not enabled on surveys'];
                    $touch[] = $pk;
                }
                $ids = $this->projectIdentifierFields($project_id);
                if ($ids === null || self::firstIdentifier($ids, $touch) !== null) return ['error' => 'not enabled on surveys'];
                if ($this->surveyRateLimited($project_id)) return ['error' => 'too many checks — slow down'];
            } else {
                if ($this->signedInRateLimited($project_id)) return $unknown('too many checks in the last minute — wait a moment');
                // Generic on purpose: naming the field would tell the page where
                // the rule looks.
                if ($this->firstUnreadableField($project_id, $user_id, array_merge([$field], $locals, $targets)) !== null) {
                    return $unknown('you do not have access to every form this lookup reads');
                }
                if ($this->recordBeyondCallerGroup($project_id, $record, $group_id)) {
                    return $unknown('this record is not in your Data Access Group');
                }
            }
            $value = isset($values[$field]) ? trim($values[$field]) : '';
            if ($value === '') return ['error' => 'nothing to look up'];
            // Values arrive as the page shows them; the lookup compares stored ones.
            $values = $this->storedFormOf($project_id, $values);
            $value = trim($values[$field]);
            $lv = [];
            $offPage = [];
            $onForm = $locals ? $this->fieldsOnInstrument($project_id, $instrument) : null;
            foreach ($locals as $lf) {
                // A match field the page shows is sent, blank or not. One it does
                // not show (another form, or another page of a multi-page
                // survey) is left out and read from the saved record; so is a
                // blank sent for a field of another form.
                if (array_key_exists($lf, $values)
                        && (trim($values[$lf]) !== '' || ($onForm !== null && isset($onForm[$lf])))) {
                    $lv[$lf] = trim($values[$lf]);
                    continue;
                }
                $offPage[] = $lf;
            }
            if ($offPage) {
                if ($record === null || $record === '') {
                    foreach ($offPage as $lf) $lv[$lf] = '';
                } else {
                    $saved = $this->readValues($project_id, $record, $offPage, $event_id, $instrument, $repeat_instance, false);
                    foreach ($offPage as $lf) {
                        $lv[$lf] = (isset($saved[$lf]) && !is_array($saved[$lf])) ? trim((string) $saved[$lf]) : '';
                    }
                }
            }
            foreach ($lv as $lf => $v) {
                if ($v === '') return $unknown('[' . $lf . '] is blank, so there is nothing to match against yet');
            }
            $dag = null;
            if (($rule['existsScope'] ?? 'project') === 'dag') {
                $rd = $this->recordDagOf($project_id, $record);
                if ($rd === false) return $unknown('the record\'s Data Access Group could not be read');
                // A record not saved yet is created in the user's own group, or in none.
                // A group whose name cannot be read is not "no group" (dagNameOf).
                if ($rd['found']) {
                    $dag = $rd['dag'];
                } elseif ($group_id !== null && $group_id !== '') {
                    $dag = ScanPageView::dagNameOf($group_id);
                    if ($dag === null) return $unknown('your Data Access Group could not be read');
                }
            }
            $opts = [];
            if (!$isAuthenticated) {
                // Anyone can produce misses, and a miss is confirmed by a read of
                // the whole searched field: a budget per project keeps that
                // read from being a lever.
                $opts['mayFullRead'] = function () use ($project_id) { return $this->surveyFullReadAllowed($project_id); };
            } elseif ($group_id !== null && $group_id !== '' && empty($rule['existsPid'])) {
                $opts['callerDag'] = ScanPageView::dagNameOf($group_id);
                if ($opts['callerDag'] === null) return $unknown('your Data Access Group could not be read');
            }
            // Another project: its agreement, the caller's standing there and its
            // budget decide; the group that matters is the caller's group THERE.
            $r = !empty($rule['existsPid'])
                ? $this->crossLookup($project_id, $rule, $value, $lv, $isAuthenticated ? 'staff' : 'survey', $opts)
                : $this->findExisting($project_id, $rule, $value, $lv, $event_id, $dag, true, $opts);
            if ($r['state'] === 'unknown') {
                return $unknown(isset($r['why']) && $r['why'] !== null ? $r['why'] : 'the saved values could not be read just now');
            }
            $recOut = null;
            if ($r['state'] === 'found' && $isAuthenticated && !$isSurvey && ($rule['existsIn'] ?? null) !== 'record'
                    && empty($rule['existsPid'])) {
                $recOut = $r['record'];
                if ($group_id !== null && $group_id !== '') {
                    $userDag = ScanPageView::dagNameOf($group_id);
                    if ($userDag === null || $r['dag'] !== $userDag) $recOut = null;
                }
            }
            return ['state' => $r['state'], 'record' => $recOut];
        } catch (\Throwable $e) {
            return ['error' => 'lookup failed'];   // the client shows "could not check"; no detail leaks
        }
    }

    /**
     * The "cond" part of a live-lookup request: the values the page read for
     * the fields its "when" conditions test, so the server picks the branch the
     * page is enforcing (activeRuleFor). Only fields of the rendered instrument
     * are taken; every other field keeps its saved value. A checkbox arrives as
     * code => "1"/"0". The values are compared as sent, as the page compared
     * them. Absent: []. Malformed: null.
     */
    private function liveCondValues($payload, $pid, $instrument)
    {
        if (!is_array($payload) || !isset($payload['cond'])) return [];
        if (!is_array($payload['cond']) || count($payload['cond']) > 40) return null;
        $onForm = $this->fieldsOnInstrument($pid, $instrument);
        $out = [];
        foreach ($payload['cond'] as $k => $v) {
            if (!is_string($k)) return null;
            $k = strtolower($k);
            if (!preg_match('/^[a-z][a-z0-9_]*$/', $k)) return null;
            if (is_array($v)) {
                if (count($v) > 200) return null;
                $codes = [];
                foreach ($v as $c => $on) {
                    $c = (string) $c;
                    if ($c === '' || strlen($c) > 100 || (!is_string($on) && !is_numeric($on))) return null;
                    $codes[$c] = ((string) $on === '1') ? '1' : '0';
                }
                $v = $codes;
            } elseif (is_string($v) || is_numeric($v)) {
                $v = (string) $v;
                if (strlen($v) > 1024) return null;
            } else {
                return null;
            }
            if ($onForm !== null && isset($onForm[$k])) $out[$k] = $v;
        }
        return $out;
    }

    /**
     * Whether a signed-in caller in a Data Access Group names a record of
     * another group, or one whose group cannot be read. The live lookups read
     * that record's saved values ("match" and "with" fields, "scope":"dag"), so
     * such a request is refused. A record not saved yet is fine: REDCap creates
     * it in the caller's group.
     */
    private function recordBeyondCallerGroup($pid, $record, $group_id)
    {
        if ($group_id === null || $group_id === '' || $record === null || $record === '') return false;
        $userDag = ScanPageView::dagNameOf($group_id);
        if ($userDag === null) return true;
        $rd = $this->recordDagOf($pid, $record);
        if ($rd === false) return true;
        return $rd['found'] && $rd['dag'] !== $userDag;
    }

    /**
     * Whether an unauthenticated @UVEXISTS lookup may read the whole searched
     * field now (tier 2 of the rate buckets, THROTTLE_SURVEY_FULL_READS per
     * project per minute). FAILS CLOSED, unlike the throttles: a refusal costs
     * the respondent a "could not check", which a survey shows as nothing,
     * while a counter that cannot be kept would leave the read unbounded.
     */
    private function surveyFullReadAllowed($pid)
    {
        try {
            if (!$pid) return false;
            $db = new Scan\ModuleDb($this);
            $bucket = ((int) floor(time() / 60)) * self::RATE_TIERS + 2;
            $db->exec('INSERT INTO ' . Scan\Schema::table('rate_bucket') . '
                (project_id, bucket, hits) VALUES (?, ?, LAST_INSERT_ID(1))
                ON DUPLICATE KEY UPDATE hits = LAST_INSERT_ID(hits + 1)',
                [(int) $pid, $bucket]);
            $r = $db->select('SELECT LAST_INSERT_ID()', []);
            if (!isset($r[0][0]) || $r[0][0] === null) return false;
            return (int) $r[0][0] <= self::THROTTLE_SURVEY_FULL_READS;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /** Per-session window for a signed-in caller of the live lookups (unique-check, exists-check). */
    const THROTTLE_SIGNED_IN = 60;

    /**
     * Throttle for SIGNED-IN callers of the live lookups, per session and project.
     *
     * Keyed on the session, which for this caller is not something they can
     * shed: the session IS the sign-in, so a request without it is not signed
     * in and meets the survey path's guards instead. One budget covers both
     * endpoints. Fails open (no session, unreadable state), like the survey
     * throttle: the live check is a convenience and the audit is the net.
     */
    private function signedInRateLimited($pid)
    {
        try {
            if (!function_exists('session_status') || session_status() !== PHP_SESSION_ACTIVE) return false;
            $key = 'uvalidate_lookup_hits_' . (int) $pid;
            $now = time();
            $hits = (isset($_SESSION[$key]) && is_array($_SESSION[$key])) ? $_SESSION[$key] : [];
            $hits = array_values(array_filter($hits, function ($t) use ($now) {
                return is_int($t) && ($now - $t) < 60;
            }));
            if (count($hits) >= self::THROTTLE_SIGNED_IN) { $_SESSION[$key] = $hits; return true; }
            $hits[] = $now;
            $_SESSION[$key] = $hits;
            return false;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * The first of $fields whose form the signed-in caller may not open, or
     * null when they may open them all. A field the dictionary cannot place, or
     * rights that cannot be read, count as not readable (fail closed).
     */
    private function firstUnreadableField($pid, $user_id, array $fields)
    {
        $rights = $this->callerFormRights($pid, $user_id);
        $dd = $this->dataDictionary($pid);
        foreach ($fields as $f) {
            $f = (string) $f;
            if ($f === '') continue;
            $form = (is_array($dd) && isset($dd[$f]['form_name'])) ? (string) $dd[$f]['form_name'] : '';
            if ($form === '' || !self::mayReadForm($rights, $form)) return $f;
        }
        return null;
    }

    /**
     * The form rights of the signed-in caller of an AJAX action: the framework
     * user's, or - when this request has no framework user - those REDCap
     * holds for $user_id, the identity the framework authenticated.
     */
    private function callerFormRights($pid, $user_id)
    {
        $r = $this->userFormRights($pid);
        if ($r !== null || $this->currentUsername() !== null || $user_id === null || $user_id === '') return $r;
        try {
            if (is_callable(['\REDCap', 'getUserRights'])) {
                $all = \REDCap::getUserRights((string) $user_id);
                if (is_array($all) && isset($all[$user_id]['forms']) && is_array($all[$user_id]['forms'])) {
                    return $all[$user_id]['forms'];
                }
            }
        } catch (\Throwable $e) {
        }
        return null;
    }

    /** Per-session window. Cheap, and keyed on something the caller controls. */
    const THROTTLE_SESSION = 30;

    /**
     * Per-project windows, which are keyed on something the caller does not.
     *
     * The sessionless cap is the older number and keeps its meaning: a
     * cookieless enumerator gets 600 a minute for the whole project. The
     * sessioned cap has to sit above what a busy public survey really spends -
     * tier 1 allows each session 30 a minute, so 600 would be twenty people
     * typing at once - while still being a bound rather than none.
     */
    const THROTTLE_PROJECT_ANON = 600;
    const THROTTLE_PROJECT_SESSIONED = 6000;
    /**
     * Whole-field reads an unauthenticated @UVEXISTS caller may cause per
     * project per minute (surveyFullReadAllowed). Over it, a lookup that needs
     * one answers unknown, which a survey page shows as nothing.
     */
    const THROTTLE_SURVEY_FULL_READS = 60;
    /**
     * Rate-bucket tiers, interleaved in one bucket column: minute * RATE_TIERS
     * + tier. 0 = survey callers with no session, 1 = with a session, 2 =
     * whole-field reads for survey lookups, 3 = kept for cross-project lookups.
     */
    const RATE_TIERS = 4;

    /**
     * Throttle for the UNAUTHENTICATED (survey) uniqueness path.
     *
     * TWO TIERS THAT BOTH RUN, which is the correction. They were alternatives:
     * tier 1 returned as soon as it passed, so tier 2 was reached only by a
     * caller carrying no session at all. That made the throttle keyed, in
     * practice, on something the caller chooses - discard the cookie between
     * requests and each one starts a fresh 30-request budget, without ever
     * meeting the per-project cap. The evasion costs an attacker one header.
     *
     *   (1) With an active session (a normal survey respondent): a per-SESSION
     *       window, cheap and touching no shared storage. It can only ever
     *       refuse; passing it no longer ends the check.
     *   (2) Always: a per-PROJECT fixed window (F5) held as a COUNTER IN THE
     *       DATABASE, incremented by one statement, so a flood is bounded even
     *       with nothing to key a session on. Sessioned and sessionless traffic
     *       are counted in SEPARATE buckets with separate caps, so extending
     *       this tier to normal traffic bounds the evasion without turning a
     *       busy survey into an outage.
     *
     *       Tier (2) was a read-modify-write over a system setting holding an
     *       array of timestamps, and concurrency defeated it exactly under the
     *       traffic it was written for: every concurrent request read the same
     *       array, appended one entry, and the last write won. See the tier's
     *       own comment for why the window is fixed rather than sliding.
     *
     * Still defence in depth, not THE defence: a single TARGETED probe is
     * inherent to answering "is this value already used?" at all, which is why
     * the survey opt-in is refused on Identifier fields and off by default
     * everywhere else. Fails OPEN on any error — the live check is a convenience,
     * never a gate on data entry.
     */
    private function surveyRateLimited($pid = null)
    {
        $window = 60;
        $now = time();
        // Tier (1): per-session window for a caller that has a session.
        //
        // IT DOES NOT RETURN WHEN IT PASSES, and that is the whole point of the
        // tier. It used to, which made the two tiers ALTERNATIVES: tier 2 was
        // reached only by a caller with no session at all, so the cheapest
        // possible evasion - discard the session cookie between requests, which
        // costs an attacker one header and nothing else - took a fresh
        // 30-request budget every time and never once met the per-project cap.
        // A budget keyed on something the caller chooses is not a budget.
        $sessioned = false;
        try {
            if (function_exists('session_status') && session_status() === PHP_SESSION_ACTIVE) {
                $sessioned = true;
                $key = 'uvalidate_unique_hits';
                $hits = (isset($_SESSION[$key]) && is_array($_SESSION[$key])) ? $_SESSION[$key] : [];
                $hits = array_values(array_filter($hits, function ($t) use ($now, $window) {
                    return is_int($t) && ($now - $t) < $window;
                }));
                if (count($hits) >= self::THROTTLE_SESSION) { $_SESSION[$key] = $hits; return true; }
                $hits[] = $now;
                $_SESSION[$key] = $hits;
            }
        } catch (\Throwable $e) {
            // Session state that cannot be read is not session state to trust.
            // The project tier below still runs, which is the tier that does
            // not depend on the caller keeping anything.
            $sessioned = false;
        }
        // Tier (2): bound the flood per project, whether or not tier 1 ran.
        //
        // A COUNTER THE DATABASE OWNS, not a timestamp array this process reads,
        // edits and writes back. The read-modify-write was defeated by the exact
        // traffic the tier was written for: N concurrent requests all read the
        // same array, each appended one entry, and the last write won - so a
        // flood of 600 concurrent checks recorded a handful of hits and the cap
        // was never reached. There is no read here at all. One statement
        // increments, and the value it returns is the count after this request.
        //
        // A FIXED WINDOW, not a sliding one, and that is a deliberate trade. A
        // sliding window needs the timestamps, and keeping the timestamps is
        // what made the counter loseable. The cost is that a burst straddling a
        // boundary can spend up to 2 x $pmax inside one 60-second span; the
        // benefit is that no increment can ever be lost. For a throttle whose
        // purpose is to bound a flood rather than to meter it, that is the right
        // way round.
        try {
            if (!$pid) return false;
            // TWO BUDGETS, NOT ONE, because the two populations are not alike.
            // Sessioned traffic is mostly real respondents, and a busy public
            // survey can legitimately spend more than the sessionless cap: tier
            // 1 allows each session 30 a minute, so 600 is only twenty people
            // typing at once. Charging both populations to one bucket would
            // have turned this hardening change into an outage for exactly the
            // projects that use the feature most. Separate buckets mean the
            // sessionless cap keeps the value it was chosen for, and the
            // sessioned cap only has to be low enough to bound enumeration -
            // which it is: it converts "unbounded" into "a day and a half for a
            // six-digit space", on an endpoint whose real defences are the
            // per-rule opt-in and the Identifier refusal.
            //
            // NOT SETTINGS. Every other tunable in this module is a setting,
            // deliberately; these are not, because the failure mode of a
            // mistyped rate limit is a security control quietly set to zero,
            // and there is no reading of it that an administrator needs.
            $pmax = $sessioned ? self::THROTTLE_PROJECT_SESSIONED : self::THROTTLE_PROJECT_ANON;
            $db = new Scan\ModuleDb($this);
            // ONE BUCKET COLUMN, SEVERAL NAMESPACES. The primary key is
            // (project_id, bucket) and a third dimension would mean an ALTER on
            // a table that is now created with IF NOT EXISTS on every
            // installation - so the tier rides in the low bits instead
            // (RATE_TIERS). INT UNSIGNED holds a quadrupled minute-counter
            // until the year 4000.
            $bucket = ((int) floor($now / $window)) * self::RATE_TIERS + ($sessioned ? 1 : 0);
            // LAST_INSERT_ID(expr) on both paths, so the fresh-bucket INSERT and
            // the existing-bucket UPDATE both answer with the number they wrote.
            // The table has no AUTO_INCREMENT, so without it the insert path
            // would report whatever the session last happened to set.
            $db->exec('INSERT INTO ' . Scan\Schema::table('rate_bucket') . '
                (project_id, bucket, hits) VALUES (?, ?, LAST_INSERT_ID(1))
                ON DUPLICATE KEY UPDATE hits = LAST_INSERT_ID(hits + 1)',
                [(int) $pid, $bucket]);
            $r = $db->select('SELECT LAST_INSERT_ID()', []);
            // "I DO NOT KNOW" IS NOT "UNDER THE CAP". This read used to answer
            // 0 for any shape it could not walk, which is silently the most
            // permissive answer available: no throttle, no pruning, and nothing
            // logged. ModuleDb::exec() already refuses to guess a row count for
            // exactly this reason and the read-back must not be more trusting
            // than the write. Raised rather than returned, so it lands in the
            // catch below and is recorded like any other storage failure.
            if (!isset($r[0][0]) || $r[0][0] === null) {
                throw new \RuntimeException('the counter did not report a value');
            }
            $hits = (int) $r[0][0];
            // Self-pruning, and only on the request that created the bucket, so
            // this is one DELETE per project per window rather than one per
            // check. Two windows of slack because a request can be in flight
            // across a boundary - two windows of RATE_TIERS slots each, because
            // the bucket number interleaves the tiers.
            if ($hits === 1) {
                $db->exec('DELETE FROM ' . Scan\Schema::table('rate_bucket')
                    . ' WHERE project_id = ? AND bucket < ?', [(int) $pid, $bucket - 2 * self::RATE_TIERS]);
            }
            return $hits > $pmax;
        } catch (\Throwable $e) {
            // FAILS OPEN, as the whole method does: the live check is a
            // convenience and never a gate on data entry, so a database that
            // is briefly unreachable must not start refusing survey responses.
            //
            // BUT IT SAYS SO. Failing open in silence is how this tier came to
            // be inert on every default installation for a whole release - the
            // table lived behind the durable scan's opt-in flag, the increment
            // threw on every request, and the only evidence was an absence.
            // An operator cannot notice an absence. One log row per project per
            // window is enough to see it and cheap enough to survive a flood.
            $this->noteThrottleUnavailable($pid, (int) floor($now / $window), $e);
            return false;
        }
    }

    /**
     * Record that the sessionless throttle could not reach its counter, at most
     * once per project per window.
     *
     * The marker is a system setting rather than the counter's own table, for
     * the obvious reason that this runs precisely when that table cannot be
     * reached. A lost update here costs one duplicate log row, which is why the
     * read-modify-write that was wrong for the counter is right for the marker.
     */
    private function noteThrottleUnavailable($pid, $bucket, \Throwable $e)
    {
        try {
            $key  = 'uv_throttle_down_' . (int) $pid;
            $seen = $this->getSystemSetting($key);
            if ((string) $seen === (string) $bucket) return;      // already said so this window
            $this->setSystemSetting($key, (string) $bucket);
            // The CLASS, not the message. A message from here can name the
            // installation's schema and its database user to a caller who is
            // unauthenticated by definition; the class plus the redacted detail
            // is what DbError exists to produce.
            $this->log('uv-throttle-storage-unavailable', [
                'project_id' => (int) $pid,
                'class'      => get_class($e),
                'detail'     => Scan\DbError::safe($e),
            ]);
        } catch (\Throwable $ignored) {
            // A failure to record a failure is not worth a second failure.
        }
    }

    /**
     * The live unique rule covering one field, flattened to its active branch.
     * Branch selection mirrors auditRule: conditions are evaluated against the
     * record's SAVED values (the client gates itself on live values before
     * calling). Returns null when no unique rule covers the field, or the
     * branch situation is unresolvable (conflict / unparseable) — the client
     * then fails open and the audit logs the config problem on save.
     */
    private function uniqueRuleFor(array $rules, $field, $pid, $record, $event_id, $instrument, $repeat_instance, $allowTemporalRead = false, array $live = [])
    {
        return $this->activeRuleFor($rules, 'unique', $field, $pid, $record, $event_id, $instrument, $repeat_instance, $allowTemporalRead, $live);
    }

    /**
     * The live rule of one MODE covering one field, flattened to its active
     * branch (see uniqueRuleFor), or null. Shared by the two live lookups.
     *
     * $live: values the page sent for the fields its "when" conditions read,
     * already limited to the rendered instrument (liveCondValues). They win
     * over the saved values, because the page picked its branch from them: a
     * selector just changed and not saved yet, or a record not saved at all,
     * must not be judged by another branch than the one the page enforces.
     */
    private function activeRuleFor(array $rules, $mode, $field, $pid, $record, $event_id, $instrument, $repeat_instance, $allowTemporalRead = false, array $live = [])
    {
        foreach ($rules as $r) {
            if (!empty($r['configError'])) continue;
            if (ModeRegistry::modeOfType(isset($r['type']) ? $r['type'] : '') !== $mode) continue;
            if (empty($r['fields']) || !is_array($r['fields']) || !in_array($field, $r['fields'], true)) continue;
            // Record-local rules are rendered as advisory assertions. The legacy
            // no-auth uniqueness endpoint must never reinterpret them as project scope.
            if (($r['uniqueScope'] ?? null) === 'record') return null;
            if (TemporalRules::extended($r) && !empty($r['branches'])) {
                if (!$allowTemporalRead || $record === null || $record === '') return null;
                $dd=$this->dataDictionary($pid) ?: [];$rights=$this->userFormRights($pid);
                foreach (TemporalRules::fields($r) as $source) {
                    if (!isset($dd[$source]['form_name']) || !self::mayReadForm($rights,$dd[$source]['form_name'])) return null;
                }
                $shape=$this->temporalShape($pid,$dd);$node=$this->temporalReadRecord($pid,$record,[$r]);
                $this->temporalBegin($pid);
                $p=$this->temporalPrepared($r,$shape,$node,['event'=>$event_id,'instrument'=>$instrument,'instance'=>$repeat_instance,'values'=>[]]);
                if ($p['problems'] || ($p['rule']['uniqueScope'] ?? null)==='record' || ($p['rule']['when'] ?? null)==='1=0') return null;
                return $p['rule'];
            }
            if (!isset($r['branches']) || !is_array($r['branches'])) return $r;

            $asts = [];
            $refs = [];
            $else = null;
            foreach ($r['branches'] as $bi => $b) {
                if (!isset($b['when']) || !is_string($b['when']) || $b['when'] === '') { $else = $bi; continue; }
                $p = Logic::parse($b['when']);
                if (empty($p['ok'])) return null;
                $asts[$bi] = $p['ast'];
                foreach (Logic::referencedFields($p['ast']) as $ref) $refs[$ref[0]] = true;
            }
            $values = ($record !== null && $record !== '')
                ? $this->readValues($pid, $record, array_keys($refs), $event_id, $instrument, $repeat_instance, true)
                : [];
            foreach ($live as $lf => $lv) {
                if (isset($refs[$lf])) $values[$lf] = $lv;
            }
            $active = [];
            foreach ($asts as $bi => $ast) {
                if (Logic::evaluate($ast, $values, Logic::BLANK_INERT, !empty($r['branches'][$bi]['caseSensitive']))) $active[] = $bi;
            }
            if (count($active) === 1) $pick = $active[0];
            elseif (!count($active) && $else !== null) $pick = $else;
            else return null;
            $b = $r['branches'][$pick];
            unset($b['when']);
            return array_merge(['type' => $r['type'], 'fields' => $r['fields']], $b);
        }
        return null;
    }

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
     */
    private static function collisionFilterLogic(array $need, array $target)
    {
        $clauses = [];
        foreach ($need as $f) {
            $tv = isset($target[$f]) ? $target[$f] : '';
            if ($tv === '') continue;                                       // don't constrain a blank component
            if (!preg_match('/^[A-Za-z0-9 ._:\/-]+$/', $tv)) return null;    // unsafe to inline -> full scan
            $clauses[] = '[' . $f . "] = '" . $tv . "'";
        }
        return $clauses ? implode(' and ', $clauses) : null;
    }

    /**
     * Scan every OTHER record for the candidate value(s). Comparison is exact
     * string equality after ASCII trimming — raw stored values (dropdown/radio
     * codes, canonical Y-M-D dates) on both sides, deliberately no
     * normalization: uniqueness is about what is stored. A blank primary value
     * never collides; composite "with" components match blank-to-blank. Each
     * other record is compared as its MERGED contexts (base-event row + each
     * repeat instance), so a composite key that spans an event-level field and a
     * repeating-instrument field is matched exactly the way the Validation scan
     * matches it — the per-save audit and the scan can no longer disagree (H-03).
     * Scopes: project (default), event (same event only), dag (records in the
     * same Data Access Group — resolved from the current record's saved rows,
     * falling back to the acting user's group; unresolvable DAG degrades to
     * project scope, the conservative direction for finding duplicates).
     * Returns null or ['record' => id, 'dag' => nameOrNull].
     */
    private function findCollision($pid, $field, array $with, $scope, array $values, $excludeRecord, $event_id, $groupId = null, $narrow = false)
    {
        $need = array_merge([$field], $with);
        $target = [];
        foreach ($need as $f) {
            $target[$f] = isset($values[$f]) ? trim((string) $values[$f]) : '';
        }
        if ($target[$field] === '') return null;

        $params = [
            'project_id'    => $pid,
            'return_format' => 'array',
            'fields'        => $need,
            'exportDataAccessGroups' => true,
        ];
        if ($scope === 'event' && $event_id) $params['events'] = [$event_id];

        // Live-endpoint amplification guard (F4): the no-auth path would otherwise
        // export the WHOLE project on every call. Narrow the read to candidate
        // matches with a filterLogic, so REDCap returns only the few records that
        // could collide. Best-effort — a value that cannot be safely inlined, or a
        // build that does not honor filterLogic, falls back to the full read. The
        // exact comparison below stays authoritative and the post-save audit (which
        // never narrows) is the correctness backstop, so this only ever saves work:
        // it can never turn a real duplicate into a missed save.
        // F4-DAG-01: dag scope must NOT narrow — a value-filtered read drops the
        // current record (whose saved value differs from the candidate being typed)
        // from the result, so its DAG can no longer be resolved from the record node
        // and a no-DAG acting user (group_id null) would silently degrade to project
        // scope, falsely flagging a collision in another DAG. Keep the full scan for
        // dag scope; narrowing (the F4 amplification guard) applies to project/event.
        $data = null;
        if ($narrow && $scope !== 'dag') {
            $fl = self::collisionFilterLogic($need, $target);
            if ($fl !== null) {
                try {
                    $n = \REDCap::getData($params + ['filterLogic' => $fl]);
                    if (is_array($n)) $data = $n;
                } catch (\Throwable $e) {
                    // filterLogic unsupported/malformed here — fall back below.
                }
            }
        }
        if ($data === null) $data = \REDCap::getData($params);
        if (!is_array($data)) return null;

        $currentDag = null;
        if ($scope === 'dag') {
            if ($excludeRecord !== null && $excludeRecord !== '' && isset($data[$excludeRecord]) && is_array($data[$excludeRecord])) {
                $currentDag = self::dagOfRecordNode($data[$excludeRecord]);
            }
            if ($currentDag === null && $groupId !== null && $groupId !== '') {
                $currentDag = ScanPageView::dagNameOf($groupId);
            }
        }

        foreach ($data as $rec => $node) {
            if ($excludeRecord !== null && $excludeRecord !== '' && (string) $rec === (string) $excludeRecord) continue;
            if (!is_array($node)) continue;
            $dag = self::dagOfRecordNode($node);
            if ($scope === 'dag' && $currentDag !== null && $dag !== $currentDag) continue;
            // Compare against MERGED contexts (base event row + each repeat
            // instance), not raw rows, so a composite spanning an event field and a
            // repeat-instrument field is detected — the same view the scan uses (H-03).
            foreach (self::recordContexts($node) as $ctx) {
                $row = $ctx['values'];
                $match = true;
                foreach ($target as $f => $tv) {
                    $rv = (isset($row[$f]) && !is_array($row[$f])) ? trim((string) $row[$f]) : '';
                    if ($rv !== $tv) { $match = false; break; }
                }
                if ($match) return ['record' => (string) $rec, 'dag' => $dag];
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
        $pk = null;
        try {
            if (is_callable(['\REDCap', 'getRecordIdField'])) $pk = \REDCap::getRecordIdField();
        } catch (\Throwable $e) {
            $pk = null;     // not exposed on this build; the guard below still holds
        }
        $readFields = $fields;
        if (is_string($pk) && $pk !== '' && !in_array($pk, $readFields, true)) $readFields[] = $pk;

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
        try {
            if (is_callable(['\REDCap', 'getRepeatingFormsEvents'])) {
                $map = \REDCap::getRepeatingFormsEvents($project_id);
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
            }
        } catch (\Throwable $e) {
            $out = null;
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
