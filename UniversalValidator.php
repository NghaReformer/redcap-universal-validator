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
require_once __DIR__ . '/php/Logic.php';
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
                    $touches = false;
                    foreach (array_merge(self::ruleWhens($r), self::ruleAsserts($r)) as $cond) {
                        $p = Logic::parse($cond);
                        if (empty($p['ok'])) continue;
                        foreach (Logic::referencedFields($p['ast']) as $ref) {
                            if (isset($onForm[$ref[0]])) { $touches = true; break 2; }
                        }
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

            // Constraint rules (@UVASSERT) compare fields their "assert" names,
            // and unique rules (@UVUNIQUE) read their composite "with" fields —
            // widen the read set with both, so the audit can evaluate them
            // (mirrors the "when" widening above).
            foreach ($rules as $r) {
                if (!empty($r['configError'])) continue;
                foreach (self::ruleAsserts($r) as $a) {
                    $pa = Logic::parse($a);
                    if (empty($pa['ok'])) continue;
                    foreach (Logic::referencedFields($pa['ast']) as $ref) $readSet[$ref[0]] = true;
                }
                foreach (self::ruleUniqueWith($r) as $w) $readSet[$w] = true;
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
                                    $depResolution[$hk]);
                            }
                        }
                        continue;
                    }
                    $this->auditRule($rule, $ruleIndex, $values, $dupes, $onForm, $logMode, $project_id, $record, $instrument, $event_id, $repeat_instance,
                        isset($whenAst[$ruleIndex]) ? $whenAst[$ruleIndex] : null, $auditResolution);
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
    private function auditRule(array $rule, $ruleIndex, array $values, array $dupes, $onForm, $logMode, $project_id, $record, $instrument, $event_id, $repeat_instance, $whenAst = null, array $resolution = [])
    {
        $f = $this->ruleFindings($rule, $ruleIndex, $values, $dupes, $onForm, $project_id, $record, $event_id, $whenAst, $resolution);
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
     * path the scan takes. Returns:
     *   ['invalid'         => [ ['field','value','algo','type','reason'], ... ],
     *    'unconfigurable'  => [ ['fields' => [...], 'why' => string], ... ]]
     */
    private function ruleFindings(array $rule, $ruleIndex, array $values, array $dupes, $onForm, $project_id, $record, $event_id, $whenAst = null, array $resolution = [])
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
                if (Logic::evaluate($ast, $values)) $active[] = $bi;
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
            return $this->ruleFindings($flat, $ruleIndex, $values, $dupes, $onForm, $project_id, $record, $event_id, null, $resolution);
        }

        $algo    = isset($rule['algorithm']) && $rule['algorithm'] !== '' ? $rule['algorithm'] : 'iso7064_mod37_36';
        $source  = isset($rule['source']) && $rule['source'] !== '' ? $rule['source'] : 'normalized_id';
        $strip   = isset($rule['strip']) ? $rule['strip'] : "-/ _|\\";
        $pattern = isset($rule['idPattern']) ? $rule['idPattern'] : null;
        $type    = isset($rule['type']) && $rule['type'] !== '' ? $rule['type'] : 'single';

        // An algorithm outside the whitelist would make CheckCharacter::compute
        // throw inside validateId, which reads as "invalid ID" — a config
        // problem must never be reported as a data problem. Constraint /
        // required / unique / choices rules carry no algorithm and skip this gate.
        if ($type !== 'constraint' && $type !== 'required' && $type !== 'unique' && $type !== 'choices'
            && !in_array($algo, AnnotationRules::ALGORITHMS, true)) {
            $out['unconfigurable'][] = ['fields' => $rule['fields'], 'why' => 'unknown algorithm "' . $algo . '"'];
            return $out;
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
            if (!Logic::evaluate($whenAst, $values)) return $out;
        }

        // Unique mode (@UVUNIQUE): the race backstop. The browser prevents the
        // common case live via the AJAX check; two near-simultaneous submits
        // can both pass it, so the audit re-checks the SAVED value against
        // every other record. (The scan page does NOT take this path — it
        // detects duplicates in one aggregate pass over the scanned data
        // instead of one whole-project read per record.)
        if ($type === 'unique') {
            $with  = (isset($rule['uniqueWith']) && is_array($rule['uniqueWith'])) ? $rule['uniqueWith'] : [];
            $scope = isset($rule['uniqueScope']) ? $rule['uniqueScope'] : 'project';
            foreach ($rule['fields'] as $field) {
                if (isset($dupes[$field])) continue;
                if ($onForm !== null && !isset($onForm[$field])) continue;
                $value = isset($values[$field]) ? $values[$field] : null;
                if ($value === null || is_array($value) || trim((string) $value) === '') continue;
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

        // Required mode (@UVREQUIRED): the INVERSE emptiness rule — a BLANK
        // field is the violation (every other mode is inert on blank). The
        // "when" gate above already skipped the rule when the condition is
        // false, so reaching here means the requirement is in force. Nothing
        // identifying is in a blank, so the finding carries an empty value.
        if ($type === 'required') {
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

        // Choices mode (@UVCHOICES): a saved value that is a currently-hidden
        // choice is the violation. The "when" gate above already skipped the
        // rule while its condition is false, so reaching here means the filter
        // is in force. A value outside the field's own choice list (e.g. a
        // missing-data code like -99) is out of the filter's scope — never
        // flagged. Checkbox values arrive as code=>0/1 maps (keepArrays); this
        // is the one mode that must judge them.
        if ($type === 'choices') {
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
                            $out['invalid'][] = ['field' => $field, 'value' => $c, 'algo' => 'choices',
                                                 'type' => 'choices', 'reason' => 'hidden-choice'];
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

        // Constraint mode (@UVASSERT): the field is invalid whenever its
        // "assert" condition is false against this context's values. An empty
        // field is inert (emptiness is @UVREQUIRED's concern, not a
        // constraint's). No check character / pattern — just the test. The
        // condition is re-parsed here (config-validated, cheap) and evaluated
        // against the full value map, so no fold is needed server-side.
        if ($type === 'constraint') {
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
                if (!Logic::evaluate($a['ast'], $values)) {
                    $out['invalid'][] = ['field' => $field, 'value' => $value, 'algo' => 'constraint', 'type' => 'constraint', 'reason' => 'assert:' . $rule['assert']];
                }
            }
            return $out;
        }

        $unconfigurable = [];
        foreach ($rule['fields'] as $field) {
            if (isset($dupes[$field])) continue;
            if ($onForm !== null && !isset($onForm[$field])) continue;
            $value = isset($values[$field]) ? $values[$field] : null;
            if ($value === null || $value === '') continue;
            if ($type === 'pooled') {
                $res = CheckCharacter::validatePooledField([
                    'algorithm'   => $algo, 'source' => $source, 'strip' => $strip,
                    'idPattern'   => $pattern, 'keepChars' => isset($rule['keepChars']) ? $rule['keepChars'] : '',
                    'idLengths'   => isset($rule['idLengths']) ? $rule['idLengths'] : null,
                    'idMinLen'    => isset($rule['idMinLen']) ? $rule['idMinLen'] : null,
                    'idMaxLen'    => isset($rule['idMaxLen']) ? $rule['idMaxLen'] : null,
                    'expectedIds' => isset($rule['expectedIds']) ? $rule['expectedIds'] : null,
                ], $value);
            } else {
                $res = CheckCharacter::validateSingleField($algo, $source, $strip, $pattern, $value);
            }
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
        if (self::hasUniqueRules($config['rules'])) {
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
        $json = json_encode(
            $config,
            JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE
        );
        if ($json === false) return; // never inject malformed config
        echo '<script type="application/json" id="inspire-validator-config">'
            . $json . '</script>' . "\n";
        echo '<script src="' . htmlspecialchars($engineUrl, ENT_QUOTES) . '"></script>' . "\n";
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

        $config['rules'] = $this->foldRuleConditions($rules, $pid, $record, $instrument, $event_id, $repeat_instance, $context);
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
        foreach ($rules as $r) {
            if (!empty($r['configError'])) continue;
            // Both the "when" gate and the "assert" test (constraint mode) are
            // folded the same way: a comparison the browser can read live stays
            // live; one needing an off-instrument field is settled on the server.
            foreach (array_merge(self::ruleWhens($r), self::ruleAsserts($r)) as $w) {
                if (isset($asts[$w])) continue;
                $p = Logic::parse($w);
                if (empty($p['ok'])) continue; // a bad condition is already a configError rule
                $asts[$w] = $p['ast'];
                foreach (Logic::referencedFields($p['ast']) as $ref) $refs[$ref[0]] = true;
            }
        }
        if (!$asts) return $rules;

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

        $folded = [];
        $frozen = [];   // condition text => a live side had to be given up
        $blocked = [];  // condition text => field => why it could not be resolved
        $snapshot = []; // condition text => off-page fields baked at render time
        foreach ($asts as $w => $ast) {
            $f = false;
            $b = [];
            $sn = [];
            $folded[$w] = Logic::fold($ast, $values, $live, $disclosable, $f, $unresolved, $b, $sn);
            $frozen[$w] = $f || $unknownForm;
            $blocked[$w] = $b;
            $snapshot[$w] = $sn;
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
                $rules[$i]['whenAst'] = $folded[$r['when']];
                // An unresolvable "when" gates on a value we never read, so the
                // rule must not act on it either.
                if (!empty($blocked[$r['when']])) { $rules[$i]['deferred'] = true; $noteFor($i, $r['when']); }
                // A gate that had to give up a live side is stale in the same way
                // an assert is: defer rather than gate on page-load truth.
                if (!empty($frozen[$r['when']])) $rules[$i]['deferred'] = true;
                foreach (isset($snapshot[$r['when']]) ? $snapshot[$r['when']] : [] as $sf => $_) $snapFields[$sf] = true;
            }
            if (isset($r['assert']) && isset($folded[$r['assert']])) {
                $rules[$i]['assertAst'] = $folded[$r['assert']];
                // A frozen ASSERT must never block: its verdict is stale the
                // moment the user types, and the post-save audit re-checks it.
                if (!empty($frozen[$r['assert']])) $rules[$i]['deferred'] = true;
                if (!empty($blocked[$r['assert']])) $noteFor($i, $r['assert']);
                foreach (isset($snapshot[$r['assert']]) ? $snapshot[$r['assert']] : [] as $sf => $_) $snapFields[$sf] = true;
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
                        $rules[$i]['branches'][$bi]['whenAst'] = $folded[$b['when']];
                        if (!empty($blocked[$b['when']])) {
                            $rules[$i]['branches'][$bi]['deferred'] = true;
                            $noteFor($i, $b['when']);
                            foreach ($blocked[$b['when']] as $bf => $bs) $bWhy[$bf . '|' . $bs] = self::resolutionProblem($bs, $bf);
                        }
                        if (!empty($frozen[$b['when']])) $rules[$i]['branches'][$bi]['deferred'] = true;
                    }
                    if (isset($b['assert']) && isset($folded[$b['assert']])) {
                        $rules[$i]['branches'][$bi]['assertAst'] = $folded[$b['assert']];
                        if (!empty($frozen[$b['assert']])) $rules[$i]['branches'][$bi]['deferred'] = true;
                        if (!empty($blocked[$b['assert']])) {
                            $noteFor($i, $b['assert']);
                            foreach ($blocked[$b['assert']] as $bf => $bs) $bWhy[$bf . '|' . $bs] = self::resolutionProblem($bs, $bf);
                        }
                        foreach (isset($snapshot[$b['assert']]) ? $snapshot[$b['assert']] : [] as $sf => $_) $bSnap[$sf] = true;
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
            $dd = $this->dataDictionary($pid);
            if (!$dd) return [];
            $out = [];
            foreach ($refs as $f) {
                if (!isset($dd[$f]['form_name'])) continue;          // unknown field -> no
                $form = $dd[$f]['form_name'];
                if ($instrument !== null && $form === $instrument) continue;  // already live
                if (!array_key_exists($form, $forms)) continue;      // no entry -> no
                // REDCap form rights: 0 = no access. 1 view/edit, 2 read-only,
                // 3 edit survey responses all imply the user can read the form.
                if ((string) $forms[$form] === '0') continue;
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
     */
    private function userFormRights($pid)
    {
        $user = $this->currentUsername();
        if ($user === null) return null;

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
        //    order cannot be got wrong, then keyed by username.
        try {
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
     * rather than per project, because an entitlement question is asked of one
     * rule at a time. Kept beside them so the two cannot drift: a source added
     * to the read set and forgotten here would be a field the scan reads and
     * never checks the reader's right to.
     *
     * @return string[]
     */
    private static function ruleRefFields(array $r)
    {
        $out = [];
        foreach ((isset($r['fields']) && is_array($r['fields'])) ? $r['fields'] : [] as $f) {
            $out[(string) $f] = true;
        }
        foreach (array_merge(self::ruleWhens($r), self::ruleAsserts($r)) as $cond) {
            $p = Logic::parse($cond);
            if (empty($p['ok'])) continue;
            foreach (Logic::referencedFields($p['ast']) as $ref) $out[(string) $ref[0]] = true;
        }
        foreach (self::ruleUniqueWith($r) as $w) $out[(string) $w] = true;
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
        if (!is_array($rights)) return false;                  // unestablished -> clears nothing
        if (!array_key_exists($form, $rights)) return false;   // no entry -> no
        return (string) $rights[$form] !== '0';
    }

    /** Every non-empty "when" a rule carries (its own, and its branches'). */
    private static function ruleWhens(array $r)
    {
        $out = [];
        if (isset($r['when']) && is_string($r['when']) && $r['when'] !== '') $out[] = $r['when'];
        if (isset($r['branches']) && is_array($r['branches'])) {
            foreach ($r['branches'] as $b) {
                if (isset($b['when']) && is_string($b['when']) && $b['when'] !== '') $out[] = $b['when'];
            }
        }
        return $out;
    }

    /** Every non-empty "assert" a rule carries (its own, and its branches'). */
    private static function ruleAsserts(array $r)
    {
        $out = [];
        if (isset($r['assert']) && is_string($r['assert']) && $r['assert'] !== '') $out[] = $r['assert'];
        if (isset($r['branches']) && is_array($r['branches'])) {
            foreach ($r['branches'] as $b) {
                if (isset($b['assert']) && is_string($b['assert']) && $b['assert'] !== '') $out[] = $b['assert'];
            }
        }
        return $out;
    }

    /** Every composite "with" field a unique rule carries (own + branches'). */
    private static function ruleUniqueWith(array $r)
    {
        $out = [];
        if (isset($r['uniqueWith']) && is_array($r['uniqueWith'])) {
            foreach ($r['uniqueWith'] as $w) { if (is_string($w) && $w !== '') $out[] = $w; }
        }
        if (isset($r['branches']) && is_array($r['branches'])) {
            foreach ($r['branches'] as $b) {
                if (isset($b['uniqueWith']) && is_array($b['uniqueWith'])) {
                    foreach ($b['uniqueWith'] as $w) { if (is_string($w) && $w !== '') $out[] = $w; }
                }
            }
        }
        return $out;
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
        $key = (string) ($pid === null ? '' : $pid);
        if (array_key_exists($key, $this->rulesMemo)) return $this->rulesMemo[$key];

        $out = $this->getSettingRules($pid);
        foreach ($this->getAnnotationRules($pid) as $r) $out[] = $r;
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
            $rule = $this->settingRowToRule(is_array($s) ? $s : [], $known, $types, $choices, $identifiers);
            if ($rule !== null) $out[] = $rule;
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

    private function settingRowToRule(array $s, $known, $types, $choices = null, $identifiers = null)
    {
        // Stored settings can hold surprising shapes after upgrades or manual
        // edits; for these keys only scalars are meaningful — discard anything
        // else instead of warning or letting it reach the engine.
        foreach (['rule-type', 'fields-csv', 'when', 'assert', 'message',
                  'unique-with', 'unique-scope', 'unique-surveys', 'algorithm', 'source',
                  'suggest-fix', 'pattern', 'strip',
                  'keep-chars', 'id-lengths', 'id-min-len', 'id-max-len',
                  'expected-count', 'block-save'] as $k) {
            if (isset($s[$k]) && !is_scalar($s[$k])) unset($s[$k]);
        }
        // The rule KIND decides which boxes apply and which field types are
        // eligible. single|pooled are the two types of the check mode;
        // constraint (@UVASSERT-style) and required (@UVREQUIRED-style) are
        // the added modes — their rows read only their own boxes below.
        $ruleType = !empty($s['rule-type']) ? (string) $s['rule-type'] : 'single';
        $mode = Branching::modeOfType($ruleType);
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
        // Field-type eligibility is per MODE (COR-003, mirrored from the
        // annotation channel): check-character/regex rules can only attach to
        // Text/Notes inputs; a constraint reads any scalar field's answer; a
        // required rule additionally excludes calc (the person entering data
        // cannot fill a calc, so requiring one would trap them).
        if ($types !== null && $fields) {
            if ($mode === 'constraint') {
                $allowed = AnnotationRules::CONSTRAINT_FIELD_TYPES;
                $why = 'a Constraint rule supports Text, Notes, dropdown, radio, yes/no, true/false, calc and slider fields';
            } elseif ($mode === 'required') {
                $allowed = AnnotationRules::REQUIRED_FIELD_TYPES;
                $why = 'a Required rule supports Text, Notes, dropdown, radio, yes/no, true/false and slider fields (not calc — the person entering data cannot fill it)';
            } elseif ($mode === 'unique') {
                $allowed = AnnotationRules::UNIQUE_FIELD_TYPES;
                $why = 'a Unique rule supports Text, Notes, dropdown, radio, yes/no, true/false and slider fields (not calc — the person entering data cannot fix a calc collision)';
            } else {
                $allowed = ['text', 'notes'];
                $why = 'only Text and Notes fields can be validated';
            }
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
        if ($mode === 'constraint' || $mode === 'required' || $mode === 'unique') {
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

            $errors = $csvErrors;
            foreach (AnnotationRules::checkFragment($rule) as $e) $errors[] = $e;
            // Dictionary-dependent reference checks for BOTH conditions.
            foreach (['when', 'assert'] as $condKey) {
                if (isset($rule[$condKey]) && $types !== null) {
                    $w = Logic::parse($rule[$condKey]);
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
        if (isset($s['strip']) && (string) $s['strip'] !== '')        $rule['strip']     = (string) $s['strip'];
        if (isset($s['keep-chars']) && (string) $s['keep-chars'] !== '') $rule['keepChars'] = (string) $s['keep-chars'];
        // Optional "when" condition — the rule validates only while it is true.
        // A blank box simply never sets the key (in the annotation JSON channel
        // an explicit "when":"" is a config error instead — it hides a typo).
        if (isset($s['when']) && trim((string) $s['when']) !== '')    $rule['when'] = trim((string) $s['when']);
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
        foreach (AnnotationRules::checkFragment($rule) as $e) $errors[] = $e;

        // Dictionary-dependent "when" reference checks (field exists, checkbox
        // needs a real (code), no file/descriptive refs) — only when the
        // dictionary is available, like the field-name checks above.
        if (isset($rule['when']) && $types !== null) {
            $w = Logic::parse($rule['when']);
            if (!empty($w['ok'])) {
                foreach (Logic::checkRefs($w['ast'], $types, is_array($choices) ? $choices : []) as $e) {
                    $errors[] = $e;
                }
            }
        }

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
            if (!is_array($settings) || empty($settings['rules']) || !is_array($settings['rules'])) return null;
            $pid = null;
            try { $pid = $this->getProjectId(); } catch (\Throwable $e) {}
            $known = $pid ? $this->projectFieldNames($pid) : null;
            $types = $pid ? $this->projectFieldTypes($pid) : null;
            $choices = $pid ? $this->projectFieldChoices($pid) : null;
            $identifiers = $pid ? $this->projectIdentifierFields($pid) : null;
            $errors = [];
            $clean = [];    // assembled live rules, for the cross-rule check below
            $rowNums = [];  // their 1-based dialog row numbers, for messages
            foreach (self::rowsFromFlatSettings($settings) as $i => $row) {
                $rule = $this->settingRowToRule($row, $known, $types, $choices, $identifiers);
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
    private static function rowsFromFlatSettings(array $settings)
    {
        $keys = ['rule-note', 'rule-type', 'fields', 'fields-csv', 'when', 'assert', 'message',
                 'unique-with', 'unique-scope', 'unique-surveys',
                 'algorithm', 'source',
                 'suggest-fix', 'pattern', 'strip', 'keep-chars', 'id-lengths', 'id-min-len', 'id-max-len',
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
            $frags = AnnotationRules::parseAllTags($ann);
            if ($frags === null) continue; // no module tag (e.g. @UVALIDATED)
            // Field-type eligibility is per MODE: check-character/regex still
            // needs a Text/Notes input; a constraint (@UVASSERT) reads any
            // scalar field's answer, so it accepts dropdowns/dates/etc.
            $ftype = isset($meta['field_type']) ? $meta['field_type'] : '';
            foreach ($frags as $k => $frag) {
                if (isset($frag['error'])) continue;
                $mode = isset($frag['type']) ? $frag['type'] : 'single';
                if ($mode === 'constraint') {
                    if (!in_array($ftype, AnnotationRules::CONSTRAINT_FIELD_TYPES, true)) {
                        $frags[$k] = ['error' => AnnotationRules::TAG_ASSERT . ' does not support "' . $ftype
                            . '" fields — it checks one scalar field\'s value against a condition.',
                            '_tag' => AnnotationRules::TAG_ASSERT];
                    }
                } elseif ($mode === 'required') {
                    if (!in_array($ftype, AnnotationRules::REQUIRED_FIELD_TYPES, true)) {
                        $frags[$k] = ['error' => AnnotationRules::TAG_REQUIRED . ' does not support "' . $ftype
                            . '" fields' . ($ftype === 'calc'
                                ? ' — a calc value is computed, the person entering data cannot fill it in.'
                                : ' — it requires a scalar input the person can fill in.'),
                            '_tag' => AnnotationRules::TAG_REQUIRED];
                    }
                } elseif ($mode === 'unique') {
                    if (!in_array($ftype, AnnotationRules::UNIQUE_FIELD_TYPES, true)) {
                        $frags[$k] = ['error' => AnnotationRules::TAG_UNIQUE . ' does not support "' . $ftype
                            . '" fields — it compares one scalar field\'s value across records.',
                            '_tag' => AnnotationRules::TAG_UNIQUE];
                    } elseif (!empty($frag['uniqueSurveys'])) {
                        // Refuse the survey opt-in when the primary field OR any
                        // composite "with" field is an Identifier (H-01); name the
                        // offending field so a composite hit is not mistaken for the
                        // primary one.
                        $withF = (isset($frag['uniqueWith']) && is_array($frag['uniqueWith'])) ? $frag['uniqueWith'] : [];
                        $idField = self::firstIdentifier($this->projectIdentifierFields($pid), array_merge([$name], $withF));
                        if ($idField !== null) {
                            $frags[$k] = ['error' => ($idField === $name ? '' : 'composite "with" field "' . $idField . '": ')
                                . self::SURVEY_ON_IDENTIFIER, '_tag' => AnnotationRules::TAG_UNIQUE];
                        }
                    }
                } elseif ($mode === 'choices') {
                    $grid = isset($meta['matrix_group_name']) ? trim((string) $meta['matrix_group_name']) : '';
                    if (!in_array($ftype, AnnotationRules::CHOICES_FIELD_TYPES, true)) {
                        $frags[$k] = ['error' => AnnotationRules::TAG_CHOICES . ' does not support "' . $ftype
                            . '" fields — it filters the options of a radio, dropdown or checkbox field.',
                            '_tag' => AnnotationRules::TAG_CHOICES];
                    } elseif ($grid !== '') {
                        // Matrix rows render different markup than standalone
                        // choice fields — the client cannot hide their options
                        // reliably, so refuse instead of half-working.
                        $frags[$k] = ['error' => AnnotationRules::TAG_CHOICES . ' does not support matrix fields '
                            . '(this field is in matrix "' . $grid . '") — move the field out of the matrix to filter its choices.',
                            '_tag' => AnnotationRules::TAG_CHOICES];
                    }
                } elseif (!in_array($ftype, ['text', 'notes'], true)) {
                    $frags[$k] = ['error' => 'this tag only works on Text or Notes fields (this field is "'
                        . $ftype . '").'];
                }
            }
            $perField[$name] = $frags;
        }
        if (!$perField) return [];
        // Dictionary-dependent reference checks for this channel — parseAllTags/
        // checkFragment already validated syntax; whether the referenced fields
        // exist (and checkbox codes are real) needs the dd, which is in hand
        // here. Both the "when" gate and the "assert" condition are checked.
        $types = null;
        $choices = null;
        foreach ($perField as $name => $frags) {
            foreach ($frags as $k => $frag) {
                if (isset($frag['error'])) continue;
                foreach (['when', 'assert'] as $condKey) {
                    if (!isset($frag[$condKey])) continue;
                    $w = Logic::parse($frag[$condKey]);
                    if (empty($w['ok'])) continue; // syntax error already surfaced
                    if ($types === null) {
                        $types = $this->projectFieldTypes($pid);
                        $choices = $this->projectFieldChoices($pid);
                    }
                    $errs = Logic::checkRefs($w['ast'], $types === null ? [] : $types, $choices === null ? [] : $choices);
                    if ($errs) {
                        $perField[$name][$k] = ['error' => implode(' ', $errs),
                            '_tag' => self::tagOfFrag($frag)];
                        break;
                    }
                }
                // Composite-key fields of a unique rule must exist and hold ONE
                // scalar value (checkbox is multi-valued; file/descriptive have
                // no comparable value) — and "with" naming the field itself is
                // a tautology, not a composite.
                if (!isset($perField[$name][$k]['error']) && isset($frag['uniqueWith'])) {
                    if ($types === null) {
                        $types = $this->projectFieldTypes($pid);
                        $choices = $this->projectFieldChoices($pid);
                    }
                    $errs = self::checkUniqueWith($frag['uniqueWith'], $name, $types);
                    if ($errs) {
                        $perField[$name][$k] = ['error' => implode(' ', $errs),
                            '_tag' => AnnotationRules::TAG_UNIQUE];
                    }
                }
                // @UVCHOICES codes must exist in the field's OWN choice list,
                // and the full code list travels on the rule (choicesAll) so
                // the client can compute a "show" whitelist's complement
                // without enumerating the DOM (checkbox inputs are only
                // findable by exact name, code included). choicesAll is part
                // of groupMulti's canonical key, so two fields with identical
                // tags but different choice lists never share a rule.
                if (!isset($perField[$name][$k]['error'])
                        && isset($frag['type']) && $frag['type'] === 'choices') {
                    if ($types === null) {
                        $types = $this->projectFieldTypes($pid);
                        $choices = $this->projectFieldChoices($pid);
                    }
                    $all = (is_array($choices) && isset($choices[$name]))
                        ? array_map('strval', $choices[$name]) : [];
                    if (!$all) {
                        $perField[$name][$k] = ['error' => 'this field has no parseable choice list — '
                            . AnnotationRules::TAG_CHOICES . ' has nothing to filter.',
                            '_tag' => AnnotationRules::TAG_CHOICES];
                    } else {
                        $authored = isset($frag['choicesShow']) ? $frag['choicesShow']
                            : (isset($frag['choicesHide']) ? $frag['choicesHide'] : []);
                        $missing = array_values(array_diff($authored, $all));
                        if ($missing) {
                            $perField[$name][$k] = ['error' => 'choice code(s) '
                                . implode(', ', array_map('json_encode', $missing))
                                . ' do not exist on this field — its codes are: ' . implode(', ', $all) . '.',
                                '_tag' => AnnotationRules::TAG_CHOICES];
                        } else {
                            $perField[$name][$k]['choicesAll'] = $all;
                        }
                    }
                }
            }
        }
        return AnnotationRules::groupMulti($perField);
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

    /** Whether any live (non-config-error) rule is a unique rule. */
    private static function hasUniqueRules(array $rules)
    {
        foreach ($rules as $r) {
            if (!is_array($r) || !empty($r['configError'])) continue;
            if (Branching::modeOfType(isset($r['type']) ? $r['type'] : '') === 'unique') return true;
        }
        return false;
    }

    /** The action tag a fragment came from, for config-error attribution. */
    private static function tagOfFrag(array $frag)
    {
        $type = isset($frag['type']) ? $frag['type'] : '';
        if ($type === 'constraint') return AnnotationRules::TAG_ASSERT;
        if ($type === 'required')   return AnnotationRules::TAG_REQUIRED;
        if ($type === 'unique')     return AnnotationRules::TAG_UNIQUE;
        return AnnotationRules::TAG;
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
            $mode = Branching::modeOfType(isset($r['type']) ? $r['type'] : '');
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
     * $dagFilter: a DAG unique name — only records in that DAG are scanned
     * (pass the acting user's DAG so a DAG-bound user never sees other
     * groups' record ids). null scans everything.
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
                if (self::dagOfRecordNode($node) !== $dagFilter) continue;
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
                : 'no record was in scope for Data Access Group "' . $dagFilter . '", so nothing was '
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
     *               read:?callable, rules:array, ownership:array}
     */
    public function durableScanContext($pid, array $opts = [], $dagFilter = null)
    {
        $plan = $this->scanPlan($pid, $opts, $dagFilter);
        if ($plan['fatal'] !== null) {
            return ['ok' => false, 'why' => $plan['fatal'], 'plan' => null,
                    'evaluate' => null, 'read' => null, 'rules' => [], 'ownership' => []];
        }
        if (!empty($plan['nothingToScan'])) {
            return ['ok' => false, 'why' => 'this project has no rules this scan can evaluate',
                    'plan' => null, 'evaluate' => null, 'read' => null,
                    'rules' => [], 'ownership' => []];
        }

        $key = $this->hmacKey();
        $gen = isset($opts['generation']) ? (int) $opts['generation'] : 1;
        $ids = Scan\ScanPlanner::identifyAll($plan['live'],
            isset($opts['settingsCount']) ? (int) $opts['settingsCount'] : 0);

        // Which instrument owns which field, for the fingerprint. Computed here
        // because it comes from the plan, and recomputed nowhere else.
        $ownership = [];
        foreach ($plan['hostFields'] as $i => $hosts) {
            foreach ($hosts as $form => $fields) {
                foreach ($fields as $f) $ownership[$f] = $form;
            }
        }

        $module = $this;
        $evaluate = function ($recordId, array $node) use ($module, $plan, $pid, $gen, $key, $ids) {
            return $module->durableEvaluateRecord($plan, $pid, $recordId, $node, $gen, $key, $ids);
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

        return ['ok' => true, 'why' => null, 'plan' => $plan, 'evaluate' => $evaluate,
                'read' => $read, 'rules' => $plan['live'], 'ownership' => $ownership];
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
    public function durableEvaluateRecord(array $plan, $pid, $recordId, array $node, $gen, $key, array $ids)
    {
        $found = [];
        $sink = new CallbackFindingSink(function (array $v) use (&$found) { $found[] = $v; });
        $seen = [];
        $unconf = [];
        $r = $this->scanRecord($plan, $pid, $recordId, $node, $sink, $seen, $unconf);
        if ($r['why'] !== null) {
            return ['findings' => [], 'candidates' => [], 'bytes' => 0, 'contexts' => 0,
                    'problems' => [], 'why' => $r['why']];
        }

        $recHash = Scan\Hmac::raw(Scan\Hmac::P_RECORD, $pid, (string) $recordId, $key);
        $rule = function ($ord) use ($ids) {
            $i = ((int) $ord) - 1;
            // A rule the planner could not name is still reported, under a name
            // that says so. Dropping the finding would be the silent skip.
            return isset($ids[$i]) ? $ids[$i]
                 : ['source_id' => 'unnamed:' . (int) $ord, 'revision' => str_repeat('0', 64)];
        };

        $findings = [];
        $bytes = 0;
        $seq = 0;
        foreach ($found as $v) {
            $id = $rule($v['rule']);
            $loc = ['record' => (string) $recordId, 'event_id' => $v['event_id'],
                    'instance' => $v['instance'], 'host_form' => $v['instrument'],
                    'field' => $v['field'], 'rule_source_id' => $id['source_id'],
                    'reason_code' => Scan\ReasonCode::code($v['reason'])];
            $val = empty($v['valueWithheld']) && isset($v['value']) ? $v['value'] : null;
            $blob = ($val === null) ? null : substr((string) $val, 0, 255);
            if ($blob !== null) $bytes += strlen($blob);
            $findings[] = [
                'generation_id' => $gen,
                'identity' => Scan\Hmac::findingIdentity($pid, $loc, $key),
                'seq' => ++$seq,
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
            ];
        }

        // Uniqueness produces CANDIDATES, never findings: no record is a
        // duplicate on its own evidence. The composite key built by the legacy
        // path is reused verbatim and then keyed, so the live check, the audit
        // and the scan all agree about what "the same value" means.
        $candidates = [];
        foreach ($seen as $groupKey => $rows) {
            $g = Scan\Hmac::raw(Scan\Hmac::P_UNIQUE, $pid, (string) $groupKey, $key);
            foreach ($rows as $row) {
                $id = $rule($row['rule']);
                $candidates[] = [
                    'generation_id' => $gen,
                    'rule_source_id' => $id['source_id'],
                    'rule_revision' => $id['revision'],
                    'group_hmac' => $g,
                    'scope_key' => 'project',
                    'record_hash' => $recHash,
                    'record_id_bin' => (string) $recordId,
                    'event_id' => $row['event_id'],
                    'instance' => $row['instance'],
                    'host_form' => (string) $row['instrument'],
                    'field' => (string) $row['field'],
                ];
            }
        }

        return ['findings' => $findings, 'candidates' => $candidates, 'bytes' => $bytes,
                'contexts' => $r['contexts'], 'problems' => array_values($unconf), 'why' => null];
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
     *               readSet: array, dupes: array, unconf: array}
     */
    private function scanPlan($pid, array $opts = [], $dagFilter = null)
    {
        $out = ['pid' => $pid, 'fatal' => null, 'nothingToScan' => false, 'live' => [], 'hostFields' => [],
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
            foreach ($r['fields'] as $f) $readSet[$f] = true;
            foreach (array_merge(self::ruleWhens($r), self::ruleAsserts($r)) as $cond) {
                $p = Logic::parse($cond);
                if (empty($p['ok'])) continue;
                foreach (Logic::referencedFields($p['ast']) as $ref) $readSet[$ref[0]] = true;
            }
            foreach (self::ruleUniqueWith($r) as $w) $readSet[$w] = true;
        }
        // A project-scope unique rule cannot be evaluated from a DAG-confined
        // scan: the scan reads one group, so a value duplicated ACROSS groups is
        // invisible and the rule reports nothing. The live unique-check endpoint
        // queries the whole project and WOULD flag it, so the two disagree and
        // the scan is the one issuing certificates. Every other unevaluable
        // condition in this module lands in 'unconfigurable'; this one was
        // silent, which is the one outcome the contract forbids.
        if ($dagFilter !== null) {
            foreach ($live as $i => $r) {
                if (Branching::modeOfType(isset($r['type']) ? $r['type'] : '') !== 'unique') continue;
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
            }
            $out['unconf'] = $unconf;
        }

        $out['readSet'] = $readSet;
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
            $mode = Branching::modeOfType(isset($r['type']) ? $r['type'] : '');
            foreach ($plan['hostFields'][$i] as $hostForm => $ownFields) {
                $onForm = array_fill_keys($ownFields, true);
                if (!isset($hostCache[$hostForm])) {
                    $hostCache[$hostForm] = $this->hostContextsFor($ctxAll, $hostForm, $pid);
                }
                foreach ($hostCache[$hostForm] as $ck => $ctx) {
                    if (!isset($resCache[$ck])) {
                        $resCache[$ck] = $this->contextResolution($ctx, array_keys($plan['readSet']), $pid);
                    }
                    if ($mode === 'unique') {
                        self::collectUniqueCandidates($uniqueSeen, $unconf, $r, $i, $ctx, $rec, $recDag, $plan['dupes'], $onForm, $resCache[$ck], $hostForm, $plan);
                        continue;
                    }
                    $f = $this->ruleFindings($r, $i, $ctx['values'], $plan['dupes'], $onForm, $pid, $rec, $ctx['event_id'], null, $resCache[$ck]);
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
                if (Logic::evaluate($p['ast'], $ctx['values'])) $active[] = $bi;
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
            if (!Logic::evaluate($p['ast'], $ctx['values'])) return;
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
        try {
            $svc = new Scan\ScanService($this);
            $runId = (isset($payload['run_id']) && is_scalar($payload['run_id']))
                   ? (int) $payload['run_id'] : 0;

            if ($action === 'scan-start') {
                return $svc->start($project_id);
            }
            if ($runId <= 0) {
                return ['ok' => false, 'why' => Scan\ScanService::NO_RUN];
            }
            if ($action === 'scan-work')   return $svc->work($project_id, $runId, 'browser');
            if ($action === 'scan-status') return $svc->status($project_id, $runId);
            if ($action === 'scan-cancel') return $svc->cancel($project_id, $runId);
            return ['ok' => false, 'why' => 'unknown action'];
        } catch (\Throwable $e) {
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

            $rule = $this->uniqueRuleFor($this->getRules($project_id), $field, $project_id, $record, $event_id, $instrument, $repeat_instance);
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
            }

            $with  = (isset($rule['uniqueWith']) && is_array($rule['uniqueWith'])) ? $rule['uniqueWith'] : [];
            $scope = isset($rule['uniqueScope']) ? $rule['uniqueScope'] : 'project';

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
                    if ($onForm !== null && isset($onForm[$w])) continue;   // on-page: client value is authoritative
                    if (!isset($values[$w]) || $values[$w] === '') $offPage[] = $w;
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
                    $userDag = self::dagNameOf($group_id);
                    if ($userDag === null || $col['dag'] !== $userDag) $recOut = null;
                }
            }
            return ['used' => true, 'record' => $recOut];
        } catch (\Throwable $e) {
            return ['error' => 'unique check failed']; // client fails open; no detail leaks
        }
    }

    /**
     * Sliding-window throttle for the UNAUTHENTICATED (survey) uniqueness path.
     * Two tiers, so a caller is bounded whether or not it carries a session:
     *
     *   (1) With an active session (a normal survey respondent): a per-SESSION
     *       window (30 / minute), cheap and touching no shared storage.
     *   (2) With NO session (a cookieless or cookie-rotating caller — the actual
     *       sessionless flood vector v1.4.1's session-only throttle could not
     *       count): a per-PROJECT window (F5) in a single, hard-capped,
     *       self-pruning system setting, so the flood is bounded even with
     *       nothing to key a session on. Legitimate respondents carry a session
     *       and never reach tier (2), so it adds no write load or false
     *       throttling to normal traffic.
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
        try {
            if (function_exists('session_status') && session_status() === PHP_SESSION_ACTIVE) {
                $max = 30;
                $key = 'uvalidate_unique_hits';
                $hits = (isset($_SESSION[$key]) && is_array($_SESSION[$key])) ? $_SESSION[$key] : [];
                $hits = array_values(array_filter($hits, function ($t) use ($now, $window) {
                    return is_int($t) && ($now - $t) < $window;
                }));
                if (count($hits) >= $max) { $_SESSION[$key] = $hits; return true; }
                $hits[] = $now;
                $_SESSION[$key] = $hits;
                return false;
            }
        } catch (\Throwable $e) {
            return false;
        }
        // Tier (2): no session — bound the sessionless flood per project.
        try {
            if (!$pid) return false;
            $pmax = 600;                                       // no-auth checks / project / window (>> the per-session cap)
            $skey = 'uv_noauth_hits_' . (int) $pid;
            $raw = $this->getSystemSetting($skey);
            $hits = is_array($raw) ? $raw : ((is_string($raw) && $raw !== '') ? json_decode($raw, true) : []);
            if (!is_array($hits)) $hits = [];
            $hits = array_values(array_filter($hits, function ($t) use ($now, $window) {
                return is_int($t) && ($now - $t) < $window;
            }));
            if (count($hits) >= $pmax) { $this->setSystemSetting($skey, $hits); return true; }
            $hits[] = $now;
            if (count($hits) > $pmax + 100) $hits = array_slice($hits, -$pmax);   // hard cap the stored array
            $this->setSystemSetting($skey, $hits);
            return false;
        } catch (\Throwable $e) {
            return false;
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
    private function uniqueRuleFor(array $rules, $field, $pid, $record, $event_id, $instrument, $repeat_instance)
    {
        foreach ($rules as $r) {
            if (!empty($r['configError'])) continue;
            if (Branching::modeOfType(isset($r['type']) ? $r['type'] : '') !== 'unique') continue;
            if (empty($r['fields']) || !is_array($r['fields']) || !in_array($field, $r['fields'], true)) continue;
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
            $active = [];
            foreach ($asts as $bi => $ast) {
                if (Logic::evaluate($ast, $values)) $active[] = $bi;
            }
            if (count($active) === 1) $pick = $active[0];
            elseif (!count($active) && $else !== null) $pick = $else;
            else return null;
            $b = $r['branches'][$pick];
            unset($b['when']);
            return array_merge(['type' => 'unique', 'fields' => $r['fields']], $b);
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
                $currentDag = self::dagNameOf($groupId);
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

    /** Resolve a numeric group id to its unique DAG name, or null. */
    private static function dagNameOf($groupId)
    {
        try {
            if (is_callable(['\REDCap', 'getGroupNames'])) {
                $g = \REDCap::getGroupNames(true, $groupId);
                if (is_string($g) && $g !== '') return $g;
            }
        } catch (\Throwable $e) {
        }
        return null;
    }

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
