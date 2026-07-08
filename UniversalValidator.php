<?php
/**
 * Universal ID Validator — REDCap external module.
 *
 * Injects the verified check-character engine on data-entry forms and surveys,
 * configured entirely through the module's project settings (no code pasting,
 * no JavaScript Injector). Also validates on the server in redcap_save_record so
 * an invalid ID is caught even when it arrives via the API or data import.
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
        $this->injectClient();
    }

    public function redcap_survey_page_top($project_id, $record, $instrument, $event_id, $group_id, $survey_hash, $response_id, $repeat_instance = 1)
    {
        $this->injectClient();
    }

    /**
     * Server-side safety net. redcap_save_record fires AFTER the write, so this
     * is a detection/audit hook, not a hard reject: the client "Compulsory" block
     * stops human form saves, and this catches API / data-import / JavaScript-off
     * bypasses by logging them for review. It mirrors the FULL client rule
     * semantics — single and pooled fields, check character, format pattern, and
     * regex-only (algorithm "none" + pattern) — so the server audit no longer has
     * blind spots the client covers (UV-003).
     */
    public function redcap_save_record($project_id, $record, $instrument, $event_id, $group_id, $survey_hash = null, $response_id = null, $repeat_instance = 1)
    {
        $rules = $this->getRules();
        if (!$rules) return;

        // Read every configured field for this exact record/event/instance in ONE
        // getData call instead of one call per field (UV-007).
        $fields = [];
        foreach ($rules as $r) {
            foreach ($r['fields'] as $f) $fields[$f] = true;
        }
        $values = $this->readValues($project_id, $record, array_keys($fields), $event_id, $instrument, $repeat_instance);

        foreach ($rules as $rule) {
            if (!empty($rule['configError'])) continue; // misconfigured -> client shows the error
            $algo    = $rule['algorithm'] ?? 'iso7064_mod37_36';
            $source  = $rule['source'] ?? 'normalized_id';
            $strip   = $rule['strip'] ?? "-/ _|\\";
            $pattern = $rule['idPattern'] ?? null;
            $type    = $rule['type'] ?? 'single';
            foreach ($rule['fields'] as $field) {
                $value = $values[$field] ?? null;
                if ($value === null || $value === '') continue;
                if ($type === 'pooled') {
                    $res = CheckCharacter::validatePooledField([
                        'algorithm'   => $algo, 'source' => $source, 'strip' => $strip,
                        'idPattern'   => $pattern, 'keepChars' => $rule['keepChars'] ?? '',
                        'idLengths'   => $rule['idLengths'] ?? null,
                        'idMinLen'    => $rule['idMinLen'] ?? null,
                        'idMaxLen'    => $rule['idMaxLen'] ?? null,
                        'expectedIds' => $rule['expectedIds'] ?? null,
                    ], $value);
                } else {
                    $res = CheckCharacter::validateSingleField($algo, $source, $strip, $pattern, $value);
                }
                if (empty($res['ok'])) {
                    $this->logInvalid($record, $field, $value, $algo, $type, $instrument, $event_id, $repeat_instance, $res['reason'] ?? '');
                }
            }
        }
    }

    /**
     * Record an invalid value found on the server (API / import / JS-off path).
     * The "log-values" project setting controls how much identifying material the
     * entry carries (UV-005 + final-review record-ID finding):
     *   hashed (default) — value as SHA-256, record ID raw (staff can fix the record)
     *   none   (strict)  — value omitted AND record ID as SHA-256, for sites where
     *                      record IDs are themselves participant identifiers
     *   raw              — value and record ID raw (explicit opt-in)
     *   off              — no server-side detection logging at all
     * Field / instrument / event / instance are logged in every mode except off.
     */
    private function logInvalid($record, $field, $value, $algo, $type, $instrument, $event_id, $repeat_instance, $reason)
    {
        $mode = $this->getProjectSetting('log-values');
        if ($mode === null || $mode === '') $mode = 'hashed';
        if ($mode === 'off') return; // logging disabled entirely
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
            $entry['record_sha256'] = hash('sha256', (string) $record);
        } else {
            $entry['record'] = (string) $record;
        }
        if ($mode === 'raw') {
            $entry['value'] = $value;
        } elseif ($mode !== 'none') {
            $entry['value_sha256'] = hash('sha256', (string) $value);
        }
        $this->log('invalid-id-saved', $entry);
    }

    // -- client injection ---------------------------------------------------

    private function injectClient()
    {
        $config = $this->buildClientConfig();
        if (empty($config['rules'])) return; // nothing configured for this project
        $engineUrl = $this->getUrl('js/engine.js');
        // Embed the config as INERT JSON (not executable JS) and parse it on the
        // client. The hex flags escape < > & ' " to \uXXXX and the default slash
        // escaping is kept (JSON_UNESCAPED_SLASHES is deliberately NOT used), so
        // no project setting — pattern, strip, keepChars — can close the <script>
        // element or inject markup. Fixes the stored-XSS breakout (UV-001).
        $json = json_encode(
            $config,
            JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE
        );
        if ($json === false) return; // never inject malformed config
        echo '<script type="application/json" id="inspire-validator-config">'
            . $json . '</script>' . "\n";
        echo '<script>try{window.INSPIRE_VALIDATOR_CONFIG='
            . 'JSON.parse(document.getElementById("inspire-validator-config").textContent);'
            . '}catch(e){if(window.console&&console.error)console.error('
            . '"Universal ID Validator: could not parse config",e);}</script>' . "\n";
        echo '<script src="' . htmlspecialchars($engineUrl, ENT_QUOTES) . '"></script>' . "\n";
    }

    /** Build the engine's QRID_COMBINED_CONFIG object from module settings. */
    private function buildClientConfig()
    {
        return array_merge($this->defaults(), [
            'singleFields' => [],
            'pooledFields' => [],
            'rules'        => $this->getRules(),
        ]);
    }

    /**
     * All active rules, from BOTH configuration channels:
     *   1. the repeatable "rules" project settings (module Configure dialog),
     *   2. @UVALIDATE field annotations (Online Designer / data dictionary CSV).
     * A field claimed by more than one rule gets a duplicate-rule config error on
     * the client, so the two channels cannot silently fight over a field.
     */
    private function getRules()
    {
        $out = $this->getSettingRules();
        foreach ($this->getAnnotationRules() as $r) $out[] = $r;
        return $out;
    }

    /** Translate the repeatable "rules" project settings into engine rules. */
    private function getSettingRules()
    {
        $out = [];
        $subs = $this->getSubSettings('rules');
        if (!is_array($subs)) return $out;
        $known = $this->projectFieldNames();

        foreach ($subs as $s) {
            $fields = $s['fields'] ?? [];
            if (!is_array($fields)) $fields = [$fields];
            $fields = array_values(array_filter($fields, function ($f) {
                return $f !== null && $f !== '';
            }));

            // Fast entry: comma/space-separated field names typed into one box —
            // the quick way to put many fields under one rule. Merged with (and
            // deduplicated against) the field pickers.
            $csvErrors = [];
            if (!empty($s['fields-csv'])) {
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
            if ($known !== null) {
                $bad = array_values(array_diff($fields, $known));
                if ($bad) {
                    $csvErrors[] = 'field(s) not in this project: ' . implode(', ', $bad)
                        . ' — check spelling against the data dictionary.';
                    $fields = array_values(array_intersect($fields, $known));
                }
            }
            if (!$fields) continue;

            $rule = [
                'type'   => !empty($s['rule-type']) ? $s['rule-type'] : 'single',
                'fields' => $fields,
            ];
            if (!empty($s['algorithm']))  $rule['algorithm'] = $s['algorithm'];
            if (!empty($s['source']))     $rule['source']    = $s['source'];
            if (!empty($s['pattern']))    $rule['idPattern'] = $s['pattern'];
            if (!empty($s['strip']))      $rule['strip']     = $s['strip'];
            if (!empty($s['keep-chars'])) $rule['keepChars'] = $s['keep-chars'];
            if (!empty($s['block-save'])) $rule['blockSave'] = $s['block-save'];

            // Strict validation of the numeric controls: a bad value becomes a
            // visible per-rule config error instead of being silently coerced
            // (intval("abc") == 0 used to disable the check quietly) — UV-008.
            $errors = $csvErrors;

            // A catastrophic-backtracking pattern is rejected on BOTH sides: the
            // client already refuses it, and flagging it here as a config error
            // makes the server skip the rule too (instead of compiling and running
            // it, which risked false invalid-ID logs on PCRE backtrack limits).
            if (!empty($s['pattern']) && CheckCharacter::riskyPattern($s['pattern'])) {
                $errors[] = 'The format pattern looks catastrophically backtracking (nested or adjacent unbounded quantifiers, e.g. (a+)+). Rewrite it without nested quantifiers.';
            }

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

            if ($errors) $rule['configError'] = implode(' ', $errors);

            $out[] = $rule;
        }
        return $out;
    }

    /**
     * Rules declared as @UVALIDATE field annotations. Parsing and validation live
     * in AnnotationRules (pure, unit-tested); this is only the REDCap glue. Tags
     * on non-text fields become a visible config error rather than a silent no-op.
     */
    private function getAnnotationRules()
    {
        $dd = $this->dataDictionary();
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

    /** Data dictionary for the current project (cached per request), or null. */
    private function dataDictionary()
    {
        if ($this->dd !== false) return $this->dd;
        $this->dd = null;
        try {
            $pid = $this->getProjectId();
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

    /** Field names of the current project, or null when the dictionary is unavailable. */
    private function projectFieldNames()
    {
        $dd = $this->dataDictionary();
        return $dd ? array_keys($dd) : null;
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

            // non-repeating, classic single-event project (no event filter)
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
