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

class UniversalValidator extends AbstractExternalModule
{
    /** Per-request data dictionary cache (field name => metadata row), or null. */
    private $dd = false;

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
            $values = $this->readValues($project_id, $record, array_keys($readSet), $event_id, $instrument, $repeat_instance, true);

            foreach ($rules as $ruleIndex => $rule) {
                if (!empty($rule['configError'])) continue; // misconfigured -> client/dialog shows the error
                // Each rule is isolated: one rule blowing up must not silently
                // abort the audit of every later rule (COR-002).
                try {
                    $this->auditRule($rule, $ruleIndex, $values, $dupes, $onForm, $logMode, $project_id, $record, $instrument, $event_id, $repeat_instance,
                        isset($whenAst[$ruleIndex]) ? $whenAst[$ruleIndex] : null);
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
    private function auditRule(array $rule, $ruleIndex, array $values, array $dupes, $onForm, $logMode, $project_id, $record, $instrument, $event_id, $repeat_instance, $whenAst = null)
    {
        $f = $this->ruleFindings($rule, $ruleIndex, $values, $dupes, $onForm, $project_id, $record, $event_id, $whenAst);
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
    private function ruleFindings(array $rule, $ruleIndex, array $values, array $dupes, $onForm, $project_id, $record, $event_id, $whenAst = null)
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
            return $this->ruleFindings($flat, $ruleIndex, $values, $dupes, $onForm, $project_id, $record, $event_id, null);
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
            foreach ($rule['fields'] as $field) {
                if (isset($dupes[$field])) continue;
                if ($onForm !== null && !isset($onForm[$field])) continue;
                $value = isset($values[$field]) ? $values[$field] : null;
                if ($value === null || $value === '' || is_array($value)) continue; // inert when empty (or non-scalar)
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
        $config = array_merge($this->defaults(), [
            'singleFields' => [],
            'pooledFields' => [],
            'context'      => $context,
            'rules'        => $rules,
        ]);

        $config['rules'] = $this->foldRuleConditions($rules, $pid, $record, $instrument, $event_id, $repeat_instance);
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
    private function foldRuleConditions(array $rules, $pid, $record, $instrument, $event_id, $repeat_instance)
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

        // Fields the browser can read on this page. Unknown instrument (should
        // not happen on the page hooks) => fold nothing rather than fold a
        // field the user is about to edit; off-page refs then read '' in the
        // browser and the server audit stays the backstop.
        $live = $this->fieldsOnInstrument($pid, $instrument);
        if ($live === null) $live = $refs;

        $values = [];
        if ($refs && $record !== null && $record !== '') {
            try {
                $values = $this->readValues($pid, $record, array_keys($refs), $event_id, $instrument, $repeat_instance, true);
            } catch (\Throwable $e) {
                // no values: every off-page comparison folds against '' — the
                // conservative direction (a rule that does not fire never traps
                // a save) and the audit still sees the truth.
            }
        }

        $folded = [];
        foreach ($asts as $w => $ast) {
            $folded[$w] = Logic::fold($ast, $values, $live);
        }
        foreach ($rules as $i => $r) {
            if (!empty($r['configError'])) continue;
            if (isset($r['when']) && isset($folded[$r['when']])) {
                $rules[$i]['whenAst'] = $folded[$r['when']];
            }
            if (isset($r['assert']) && isset($folded[$r['assert']])) {
                $rules[$i]['assertAst'] = $folded[$r['assert']];
            }
            if (isset($r['branches']) && is_array($r['branches'])) {
                foreach ($r['branches'] as $bi => $b) {
                    if (isset($b['when']) && isset($folded[$b['when']])) {
                        $rules[$i]['branches'][$bi]['whenAst'] = $folded[$b['when']];
                    }
                    if (isset($b['assert']) && isset($folded[$b['assert']])) {
                        $rules[$i]['branches'][$bi]['assertAst'] = $folded[$b['assert']];
                    }
                }
            }
        }
        return $rules;
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
    private function getRules($pid = null)
    {
        $out = $this->getSettingRules($pid);
        foreach ($this->getAnnotationRules($pid) as $r) $out[] = $r;
        // Shared fields become explicit per-field branch rules (or config
        // errors when the sharing is illegal), so the client engine, the
        // audit, and the snapshot all consume one resolved structure.
        return Branching::resolve($out);
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
            if (isset($s['message']) && trim((string) $s['message']) !== '') {
                $rule['message'] = trim((string) $s['message']);
            }
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
                foreach ($fields as $f) {
                    if (self::isIdentifier($identifiers, $f)) { $errors[] = 'field "' . $f . '": ' . self::SURVEY_ON_IDENTIFIER; break; }
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
            }
            if ($errors) $rule['configError'] = implode(' ', $errors);
            return $rule;
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

        return $rule;
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
                    } elseif (!empty($frag['uniqueSurveys']) && self::isIdentifier($this->projectIdentifierFields($pid), $name)) {
                        $frags[$k] = ['error' => self::SURVEY_ON_IDENTIFIER, '_tag' => AnnotationRules::TAG_UNIQUE];
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
        if ($this->dd !== false) return $this->dd;
        $this->dd = null;
        try {
            if (!$pid) $pid = $this->getProjectId();
            if ($pid) {
                $dd = \REDCap::getDataDictionary($pid, 'array');
                if (is_array($dd) && $dd) $this->dd = $dd;
            }
        } catch (\Throwable $e) {
            // outside project context (or dictionary unavailable): no annotation
            // rules and no field-name checking, never a fatal error
        }
        return $this->dd;
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
            if ($user && method_exists($user, 'hasDesignRights') && $user->hasDesignRights()) return $link;
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
     * Stored VALUES are deliberately not returned: the report names where the
     * problem is; the value itself stays behind REDCap's own access control.
     */
    public function scanProject($pid, $dagFilter = null, $chunkSize = 200)
    {
        $result = ['violations' => [], 'unconfigurable' => [], 'stats' => ['records' => 0, 'contexts' => 0, 'rules' => 0]];
        $rules = $this->getRules($pid);
        if (!$rules) return $result;
        $live = [];
        foreach ($rules as $i => $r) {
            if (empty($r['configError'])) $live[$i] = $r;
        }
        if (!$live) return $result;
        $result['stats']['rules'] = count($live);

        $dupes = [];
        foreach (self::duplicateFields($rules) as $f) $dupes[$f] = true;

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

        // Record list first (ids only), then chunked full reads.
        $pk = null;
        try {
            if (is_callable(['\REDCap', 'getRecordIdField'])) $pk = \REDCap::getRecordIdField();
        } catch (\Throwable $e) {
        }
        $idData = \REDCap::getData([
            'project_id' => $pid, 'return_format' => 'array',
            'fields' => $pk ? [$pk] : array_keys($readSet),
            'exportDataAccessGroups' => true,
        ]);
        if (!is_array($idData)) return $result;
        $ids = [];
        foreach ($idData as $rec => $node) {
            if ($dagFilter !== null && is_array($node) && self::dagOfRecordNode($node) !== $dagFilter) continue;
            $ids[] = $rec;
        }
        $result['stats']['records'] = count($ids);
        if (!$ids) return $result;

        $uniqueSeen = [];   // aggregate pass: groupKey => [ [record,event,instance,field,rule], ... ]
        $unconf = [];       // dedupe unconfigurable notes by rule+why

        foreach (array_chunk($ids, max(1, (int) $chunkSize)) as $chunk) {
            $data = \REDCap::getData([
                'project_id' => $pid, 'return_format' => 'array',
                'records' => $chunk, 'fields' => array_keys($readSet),
                'exportDataAccessGroups' => true,
            ]);
            if (!is_array($data)) continue;
            foreach ($chunk as $rec) {
                if (!isset($data[$rec]) || !is_array($data[$rec])) continue;
                $recDag = self::dagOfRecordNode($data[$rec]);
                foreach (self::recordContexts($data[$rec]) as $ctx) {
                    $result['stats']['contexts']++;
                    foreach ($live as $i => $r) {
                        $mode = Branching::modeOfType(isset($r['type']) ? $r['type'] : '');
                        if ($mode === 'unique') {
                            self::collectUniqueCandidates($uniqueSeen, $r, $i, $ctx, $rec, $recDag, $dupes);
                            continue;
                        }
                        $f = $this->ruleFindings($r, $i, $ctx['values'], $dupes, null, $pid, $rec, $ctx['event_id'], null);
                        foreach ($f['invalid'] as $v) {
                            $result['violations'][] = [
                                'record' => (string) $rec, 'event_id' => $ctx['event_id'],
                                'instance' => $ctx['instance'], 'field' => $v['field'],
                                'type' => $v['type'], 'reason' => $v['reason'], 'rule' => $i + 1,
                            ];
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
        }

        // Aggregate duplicate detection: a group is a violation when TWO OR
        // MORE DISTINCT RECORDS share the key (same-record repeats mirror the
        // endpoint/audit, which only compare against OTHER records).
        foreach ($uniqueSeen as $entries) {
            $records = [];
            foreach ($entries as $e) $records[$e['record']] = true;
            if (count($records) < 2) continue;
            foreach ($entries as $e) {
                $result['violations'][] = [
                    'record' => $e['record'], 'event_id' => $e['event_id'],
                    'instance' => $e['instance'], 'field' => $e['field'],
                    'type' => 'unique', 'reason' => 'duplicate-value', 'rule' => $e['rule'],
                ];
            }
        }
        $result['unconfigurable'] = array_values($unconf);
        return $result;
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
            $out[] = ['event_id' => $k, 'instance' => 1, 'values' => self::cleanRow($node)];
        }
        if (isset($recordNode['repeat_instances']) && is_array($recordNode['repeat_instances'])) {
            foreach ($recordNode['repeat_instances'] as $evt => $byInstr) {
                if (!is_array($byInstr)) continue;
                $base = (isset($recordNode[$evt]) && is_array($recordNode[$evt])) ? self::cleanRow($recordNode[$evt]) : [];
                foreach ($byInstr as $byInst) {
                    if (!is_array($byInst)) continue;
                    foreach ($byInst as $inst => $row) {
                        if (!is_array($row)) continue;
                        $out[] = ['event_id' => $evt, 'instance' => $inst,
                                  'values' => array_merge($base, self::cleanRow($row))];
                    }
                }
            }
        }
        return $out;
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
    private static function collectUniqueCandidates(array &$seen, array $rule, $ruleIndex, array $ctx, $rec, $recDag, array $dupes)
    {
        $cfg = $rule;
        if (isset($rule['branches']) && is_array($rule['branches'])) {
            $active = [];
            $else = null;
            foreach ($rule['branches'] as $bi => $b) {
                if (!isset($b['when']) || !is_string($b['when']) || $b['when'] === '') { $else = $bi; continue; }
                $p = Logic::parse($b['when']);
                if (empty($p['ok'])) return; // unev.: the non-scan audit surfaces it
                if (Logic::evaluate($p['ast'], $ctx['values'])) $active[] = $bi;
            }
            if (count($active) === 1) $pick = $active[0];
            elseif (!count($active) && $else !== null) $pick = $else;
            else return;
            $b = $rule['branches'][$pick];
            unset($b['when']);
            $cfg = array_merge(['type' => 'unique', 'fields' => $rule['fields']], $b);
        }
        if (isset($cfg['when']) && is_string($cfg['when']) && $cfg['when'] !== '') {
            $p = Logic::parse($cfg['when']);
            if (empty($p['ok']) || !Logic::evaluate($p['ast'], $ctx['values'])) return;
        }
        $with  = (isset($cfg['uniqueWith']) && is_array($cfg['uniqueWith'])) ? $cfg['uniqueWith'] : [];
        $scope = isset($cfg['uniqueScope']) ? $cfg['uniqueScope'] : 'project';
        foreach ($rule['fields'] as $field) {
            if (isset($dupes[$field])) continue;
            $v = isset($ctx['values'][$field]) ? $ctx['values'][$field] : null;
            if ($v === null || is_array($v) || trim((string) $v) === '') continue;
            $key = $ruleIndex . "\x1F" . $field . "\x1F" . trim((string) $v);
            foreach ($with as $w) {
                $wv = (isset($ctx['values'][$w]) && !is_array($ctx['values'][$w])) ? trim((string) $ctx['values'][$w]) : '';
                $key .= "\x1F" . $wv;
            }
            if ($scope === 'event') $key .= "\x1Fevt:" . $ctx['event_id'];
            elseif ($scope === 'dag') $key .= "\x1Fdag:" . (string) $recDag;
            $seen[$key][] = ['record' => (string) $rec, 'event_id' => $ctx['event_id'],
                             'instance' => $ctx['instance'], 'field' => $field, 'rule' => $ruleIndex + 1];
        }
    }

    // -- uniqueness (@UVUNIQUE): live endpoint + shared lookup ---------------

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
                if (self::isIdentifier($this->projectIdentifierFields($project_id), $field)) {
                    return ['error' => 'not enabled on surveys'];
                }
                // ...and never faster than the throttle allows.
                if ($this->surveyRateLimited()) return ['error' => 'too many checks — slow down'];
            }

            $with  = (isset($rule['uniqueWith']) && is_array($rule['uniqueWith'])) ? $rule['uniqueWith'] : [];
            $scope = isset($rule['uniqueScope']) ? $rule['uniqueScope'] : 'project';
            $col = $this->findCollision($project_id, $field, $with, $scope, $values, $record, $event_id, $group_id);
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
     *
     * Honest about what this is and is not: it stops a script walking an ID
     * space through one survey session. It does NOT stop a determined attacker
     * who clears cookies, and it does nothing about a single TARGETED probe —
     * that exposure is inherent to answering "is this value already used?" at
     * all, which is why the survey opt-in is refused outright on Identifier
     * fields and is off by default everywhere else. Defence in depth, not the
     * defence. Fails OPEN when there is no session to count in: the check is a
     * convenience, never a gate on data entry.
     */
    private function surveyRateLimited()
    {
        $max = 30;          // checks ...
        $window = 60;       // ... per rolling minute, per session
        try {
            if (!function_exists('session_status') || session_status() !== PHP_SESSION_ACTIVE) return false;
            $now = time();
            $key = 'uvalidate_unique_hits';
            $hits = (isset($_SESSION[$key]) && is_array($_SESSION[$key])) ? $_SESSION[$key] : [];
            $hits = array_values(array_filter($hits, function ($t) use ($now, $window) {
                return is_int($t) && ($now - $t) < $window;
            }));
            if (count($hits) >= $max) { $_SESSION[$key] = $hits; return true; }
            $hits[] = $now;
            $_SESSION[$key] = $hits;
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
     * Scan every OTHER record for the candidate value(s). Comparison is exact
     * string equality after ASCII trimming — raw stored values (dropdown/radio
     * codes, canonical Y-M-D dates) on both sides, deliberately no
     * normalization: uniqueness is about what is stored. A blank primary value
     * never collides; composite "with" components match blank-to-blank.
     * Scopes: project (default), event (same event only), dag (records in the
     * same Data Access Group — resolved from the current record's saved rows,
     * falling back to the acting user's group; unresolvable DAG degrades to
     * project scope, the conservative direction for finding duplicates).
     * Returns null or ['record' => id, 'dag' => nameOrNull].
     */
    private function findCollision($pid, $field, array $with, $scope, array $values, $excludeRecord, $event_id, $groupId = null)
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
        $data = \REDCap::getData($params);
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
            foreach (self::rowNodes($node) as $row) {
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
    private function readValues($project_id, $record, array $fields, $event_id, $instrument, $repeat_instance, $keepArrays = false)
    {
        if (!$fields) return [];
        $params = [
            'project_id'    => $project_id,
            'return_format' => 'array',
            'records'       => [$record],
            'fields'        => $fields,
        ];
        if ($event_id) $params['events'] = [$event_id];
        $data = \REDCap::getData($params);
        $rec = (is_array($data) && isset($data[$record])) ? $data[$record] : null;
        if (!is_array($rec)) return [];

        $inst = (int) ($repeat_instance ?: 1);
        $out = [];
        foreach ($fields as $f) {
            $val = null;

            // repeating instrument / repeating event
            if (isset($rec['repeat_instances']) && is_array($rec['repeat_instances'])) {
                $ri = $rec['repeat_instances'];
                $byEvent = null;
                if ($event_id && isset($ri[$event_id])) $byEvent = $ri[$event_id];
                elseif (!$event_id && count($ri)) $byEvent = reset($ri);
                if (is_array($byEvent)) {
                    // key is the instrument name (repeating instrument) or "" (repeating event)
                    foreach ([$instrument, ''] as $ik) {
                        if (isset($byEvent[$ik][$inst]) && is_array($byEvent[$ik][$inst])
                            && array_key_exists($f, $byEvent[$ik][$inst])) {
                            $val = $byEvent[$ik][$inst][$f];
                            break;
                        }
                    }
                }
            }

            // non-repeating, specific event
            if ($val === null && $event_id && isset($rec[$event_id])
                && is_array($rec[$event_id]) && array_key_exists($f, $rec[$event_id])) {
                $val = $rec[$event_id][$f];
            }

            // No-event-context fallback ONLY: scan the record's nodes when the
            // hook gave us no event ID to scope by. With an event ID present, a
            // miss means "no value on this event" — auditing another event's
            // value here logged the wrong event's data (COR-001).
            if ($val === null && !$event_id) {
                foreach ($rec as $k => $node) {
                    if ($k === 'repeat_instances') continue;
                    if (is_array($node) && array_key_exists($f, $node)) { $val = $node[$f]; break; }
                }
            }

            if ($val !== null && $val !== '') {
                if (is_array($val)) {
                    // Checkbox fields arrive as code => '0'/'1' maps. Kept only
                    // when the caller asked for them ("when" condition refs);
                    // validated fields are Text/Notes, so an array can never
                    // reach the per-field audit loop.
                    if ($keepArrays) $out[$f] = $val;
                } else {
                    $out[$f] = is_string($val) ? $val : (string) $val;
                }
            }
        }
        return $out;
    }
}
