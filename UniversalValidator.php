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
            'suggestFix'  => true,
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
        $this->injectClient($project_id, 'form');
    }

    public function redcap_survey_page_top($project_id, $record, $instrument, $event_id, $group_id, $survey_hash, $response_id, $repeat_instance = 1)
    {
        // The context flag makes the client suppress technical configuration
        // detail in front of survey respondents (who cannot act on it); the
        // same problems stay fully visible on staff data-entry forms and in
        // the module log.
        $this->injectClient($project_id, 'survey');
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

            // Read every audited field for this exact record/event/instance in ONE
            // getData call instead of one call per field (UV-007).
            $values = $this->readValues($project_id, $record, array_keys($fields), $event_id, $instrument, $repeat_instance);

            foreach ($rules as $ruleIndex => $rule) {
                if (!empty($rule['configError'])) continue; // misconfigured -> client/dialog shows the error
                // Each rule is isolated: one rule blowing up must not silently
                // abort the audit of every later rule (COR-002).
                try {
                    $this->auditRule($rule, $ruleIndex, $values, $dupes, $onForm, $logMode, $project_id, $record, $instrument, $event_id, $repeat_instance);
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
    private function auditRule(array $rule, $ruleIndex, array $values, array $dupes, $onForm, $logMode, $project_id, $record, $instrument, $event_id, $repeat_instance)
    {
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

    private function injectClient($pid = null, $context = 'form')
    {
        $config = $this->buildClientConfig($pid, $context);
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
    private function buildClientConfig($pid = null, $context = 'form')
    {
        return array_merge($this->defaults(), [
            'singleFields' => [],
            'pooledFields' => [],
            'context'      => $context,
            'rules'        => $this->getRules($pid),
        ]);
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
        return $out;
    }

    /** Translate the repeatable "rules" project settings into engine rules. */
    private function getSettingRules($pid = null)
    {
        $out = [];
        $subs = $this->getSubSettings('rules', $pid);
        if (!is_array($subs)) return $out;
        $known = $this->projectFieldNames($pid);
        $types = $this->projectFieldTypes($pid);

        foreach ($subs as $s) {
            $rule = $this->settingRowToRule(is_array($s) ? $s : [], $known, $types);
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
    private function settingRowToRule(array $s, $known, $types)
    {
        // Stored settings can hold surprising shapes after upgrades or manual
        // edits; for these keys only scalars are meaningful — discard anything
        // else instead of warning or letting it reach the engine.
        foreach (['rule-type', 'fields-csv', 'algorithm', 'source', 'pattern', 'strip',
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
        if (!empty($s['algorithm']))  $rule['algorithm'] = $s['algorithm'];
        if (!empty($s['source']))     $rule['source']    = $s['source'];
        if (!empty($s['block-save'])) $rule['blockSave'] = $s['block-save'];
        // Presence checks, not empty(): a pattern/strip/keep of the string "0"
        // is legitimate configuration, not an unset box (UX-002).
        if (isset($s['pattern']) && (string) $s['pattern'] !== '')    $rule['idPattern'] = (string) $s['pattern'];
        if (isset($s['strip']) && (string) $s['strip'] !== '')        $rule['strip']     = (string) $s['strip'];
        if (isset($s['keep-chars']) && (string) $s['keep-chars'] !== '') $rule['keepChars'] = (string) $s['keep-chars'];

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
        // ASCII subset, compilability), none-needs-pattern, and the hard caps
        // that bound the pooled parser's work (COR-002/PER-002/COR-004).
        foreach (AnnotationRules::checkFragment($rule) as $e) $errors[] = $e;

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
            $errors = [];
            foreach (self::rowsFromFlatSettings($settings) as $i => $row) {
                $rule = $this->settingRowToRule($row, $known, $types);
                if ($rule !== null && !empty($rule['configError'])) {
                    $errors[] = 'Rule ' . ($i + 1) . ': ' . $rule['configError'];
                }
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
        $keys = ['rule-note', 'rule-type', 'fields', 'fields-csv', 'algorithm', 'source',
                 'pattern', 'strip', 'keep-chars', 'id-lengths', 'id-min-len', 'id-max-len',
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
            $frag = AnnotationRules::parseField($ann);
            if ($frag === null) continue; // e.g. @UVALIDATED — a different tag
            $ftype = isset($meta['field_type']) ? $meta['field_type'] : '';
            if (!in_array($ftype, ['text', 'notes'], true)) {
                $frag = ['error' => 'this tag only works on Text or Notes fields (this field is "'
                    . $ftype . '").'];
            }
            $perField[$name] = $frag;
        }
        return $perField ? AnnotationRules::group($perField) : [];
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
    private function readValues($project_id, $record, array $fields, $event_id, $instrument, $repeat_instance)
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
                $out[$f] = is_string($val) ? $val : (string) $val;
            }
        }
        return $out;
    }
}
