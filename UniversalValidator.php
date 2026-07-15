<?php
/**
 * Universal Regex & Check-Character Validator — REDCap external module.
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

    /** Validate one rule's fields against the values read for this save. */
    private function auditRule(array $rule, $ruleIndex, array $values, array $dupes, $onForm, $logMode, $project_id, $record, $instrument, $event_id, $repeat_instance, $whenAst = null)
    {
        // Branched rule (several conditional rules share this field): pick the
        // branch whose condition is true for THIS save and audit under its
        // configuration. Semantics mirror the client and are specified in
        // php/Branching.php: one active -> validate; none -> the else branch
        // if present, otherwise inert; more than one -> a branch conflict is
        // an auditable configuration problem, never a silent pass and never a
        // guessed algorithm.
        if (isset($rule['branches']) && is_array($rule['branches'])) {
            $asts = (is_array($whenAst) && isset($whenAst['branches'])) ? $whenAst['branches'] : [];
            $active = [];
            $else = null;
            foreach ($rule['branches'] as $bi => $b) {
                if (!isset($b['when']) || !is_string($b['when']) || $b['when'] === '') {
                    $else = $bi;
                    continue;
                }
                $ast = isset($asts[$bi]) ? $asts[$bi] : false;
                if (!is_array($ast)) {
                    $this->logUnconfigurable($ruleIndex, $rule['fields'], 'a branch "when" condition cannot be evaluated — field skipped', $instrument, $event_id, $repeat_instance);
                    return;
                }
                if (Logic::evaluate($ast, $values)) $active[] = $bi;
            }
            if (count($active) > 1) {
                $this->logUnconfigurable($ruleIndex, $rule['fields'],
                    'more than one "when" condition is true for this field (branch conflict) — field skipped: "'
                    . $rule['branches'][$active[0]]['when'] . '" | "' . $rule['branches'][$active[1]]['when'] . '"',
                    $instrument, $event_id, $repeat_instance);
                return;
            }
            if (count($active) === 1) $pick = $active[0];
            elseif ($else !== null) $pick = $else;
            else return; // no branch applies to this save — the field is inert

            $branch = $rule['branches'][$pick];
            unset($branch['when']);
            $flat = array_merge([
                'type'   => isset($rule['type']) ? $rule['type'] : 'single',
                'fields' => $rule['fields'],
            ], $branch);
            $this->auditRule($flat, $ruleIndex, $values, $dupes, $onForm, $logMode, $project_id, $record, $instrument, $event_id, $repeat_instance, null);
            return;
        }

        $algo    = isset($rule['algorithm']) && $rule['algorithm'] !== '' ? $rule['algorithm'] : 'iso7064_mod37_36';
        $source  = isset($rule['source']) && $rule['source'] !== '' ? $rule['source'] : 'normalized_id';
        $strip   = isset($rule['strip']) ? $rule['strip'] : "-/ _|\\";
        $pattern = isset($rule['idPattern']) ? $rule['idPattern'] : null;
        $type    = isset($rule['type']) && $rule['type'] !== '' ? $rule['type'] : 'single';

        // An algorithm outside the whitelist would make CheckCharacter::compute
        // throw inside validateId, which reads as "invalid ID" — a config
        // problem must never be logged as a data problem.
        if (!in_array($algo, AnnotationRules::ALGORITHMS, true)) {
            $this->logUnconfigurable($ruleIndex, $rule['fields'], 'unknown algorithm "' . $algo . '"', $instrument, $event_id, $repeat_instance);
            return;
        }

        // Conditional rule: evaluate the pre-parsed "when" against this save's
        // values (missing/empty ref => ''). False => the rule is inert for this
        // save, mirroring the client gate — no detection logs. A "when" the
        // caller could not parse should be unreachable (every configuration
        // channel validates it via checkFragment, which turns it into a
        // configError rule the audit skips), but if the two parse sites ever
        // drift it must surface as unconfigurable, never as a silent pass.
        if (isset($rule['when']) && $rule['when'] !== '') {
            if (!is_array($whenAst)) {
                $this->logUnconfigurable($ruleIndex, $rule['fields'], 'the "when" condition cannot be evaluated — rule skipped', $instrument, $event_id, $repeat_instance);
                return;
            }
            if (!Logic::evaluate($whenAst, $values)) return;
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
                $this->logInvalid($logMode, $project_id, $record, $field, $value, $algo, $type, $instrument, $event_id, $repeat_instance, isset($res['reason']) ? $res['reason'] : '');
            }
        }
        if ($unconfigurable) {
            $this->logUnconfigurable($ruleIndex, $unconfigurable, 'rule cannot be evaluated server-side (unsafe or uncompilable configuration)', $instrument, $event_id, $repeat_instance);
        }
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
            foreach (self::ruleWhens($r) as $w) {
                if (isset($asts[$w])) continue;
                $p = Logic::parse($w);
                if (empty($p['ok'])) continue; // a bad "when" is already a configError rule
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
            if (isset($r['branches']) && is_array($r['branches'])) {
                foreach ($r['branches'] as $bi => $b) {
                    if (isset($b['when']) && isset($folded[$b['when']])) {
                        $rules[$i]['branches'][$bi]['whenAst'] = $folded[$b['when']];
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

        foreach ($subs as $s) {
            $rule = $this->settingRowToRule(is_array($s) ? $s : [], $known, $types, $choices);
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
    private function settingRowToRule(array $s, $known, $types, $choices = null)
    {
        // Stored settings can hold surprising shapes after upgrades or manual
        // edits; for these keys only scalars are meaningful — discard anything
        // else instead of warning or letting it reach the engine.
        foreach (['rule-type', 'fields-csv', 'when', 'algorithm', 'source', 'suggest-fix', 'pattern', 'strip',
                  'keep-chars', 'id-lengths', 'id-min-len', 'id-max-len',
                  'expected-count', 'block-save'] as $k) {
            if (isset($s[$k]) && !is_scalar($s[$k])) unset($s[$k]);
        }
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
        // The engine can only attach to Text/Notes inputs, and the server audit
        // of e.g. a radio's stored code would silently diverge from what the
        // form shows — reject unsupported field types instead (COR-003). The
        // annotation channel already enforces the same rule.
        if ($types !== null && $fields) {
            $wrong = [];
            foreach ($fields as $f) {
                if (isset($types[$f]) && !in_array($types[$f], ['text', 'notes'], true)) $wrong[] = $f . ' (' . $types[$f] . ')';
            }
            if ($wrong) {
                $csvErrors[] = 'only Text and Notes fields can be validated — remove: ' . implode(', ', $wrong) . '.';
                $fields = array_values(array_filter($fields, function ($f) use ($types) {
                    return !isset($types[$f]) || in_array($types[$f], ['text', 'notes'], true);
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
            $errors = [];
            $clean = [];    // assembled live rules, for the cross-rule check below
            $rowNums = [];  // their 1-based dialog row numbers, for messages
            foreach (self::rowsFromFlatSettings($settings) as $i => $row) {
                $rule = $this->settingRowToRule($row, $known, $types, $choices);
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
        $keys = ['rule-note', 'rule-type', 'fields', 'fields-csv', 'when', 'algorithm', 'source',
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
            if ($ann === '' || stripos($ann, AnnotationRules::TAG) === false) continue;
            $frags = AnnotationRules::parseFieldAll($ann);
            if ($frags === null) continue; // e.g. @UVALIDATED — a different tag
            $ftype = isset($meta['field_type']) ? $meta['field_type'] : '';
            if (!in_array($ftype, ['text', 'notes'], true)) {
                $frags = [['error' => 'this tag only works on Text or Notes fields (this field is "'
                    . $ftype . '").']];
            }
            $perField[$name] = $frags;
        }
        if (!$perField) return [];
        // Dictionary-dependent "when" reference checks for this channel —
        // parseFieldAll/checkFragment already validated the syntax; whether the
        // referenced fields exist (and checkbox codes are real) needs the dd,
        // which is in hand right here.
        $types = null;
        $choices = null;
        foreach ($perField as $name => $frags) {
            foreach ($frags as $k => $frag) {
                if (isset($frag['error']) || !isset($frag['when'])) continue;
                $w = Logic::parse($frag['when']);
                if (empty($w['ok'])) continue; // parseFieldAll already surfaced the syntax error
                if ($types === null) {
                    $types = $this->projectFieldTypes($pid);
                    $choices = $this->projectFieldChoices($pid);
                }
                $errs = Logic::checkRefs($w['ast'], $types === null ? [] : $types, $choices === null ? [] : $choices);
                if ($errs) $perField[$name][$k] = ['error' => implode(' ', $errs)];
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
     * Checkbox field => [choice codes] map for validating "when" references,
     * or null when the dictionary is unavailable. Only checkbox rows are
     * parsed — for calc fields select_choices_or_calculations holds an
     * equation, not choices.
     */
    private function projectFieldChoices($pid = null)
    {
        $dd = $this->dataDictionary($pid);
        if (!$dd) return null;
        $choices = [];
        foreach ($dd as $name => $meta) {
            if (!isset($meta['field_type']) || $meta['field_type'] !== 'checkbox') continue;
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

    /** Fields claimed by more than one live (non-config-error) rule. */
    private static function duplicateFields(array $rules)
    {
        $counts = [];
        foreach ($rules as $r) {
            if (!empty($r['configError'])) continue;
            if (empty($r['fields']) || !is_array($r['fields'])) continue;
            foreach ($r['fields'] as $f) {
                $counts[$f] = isset($counts[$f]) ? $counts[$f] + 1 : 1;
            }
        }
        $dupes = [];
        foreach ($counts as $f => $c) {
            if ($c > 1) $dupes[] = $f;
        }
        return $dupes;
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
