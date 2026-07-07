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

class UniversalValidator extends AbstractExternalModule
{
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
     * is a detection/audit hook, not a hard reject: the client "hard" block stops
     * human form saves, and this catches API / data-import / JavaScript-off
     * bypasses by logging them for review. Pooled fields are validated on the
     * client only in this version.
     */
    public function redcap_save_record($project_id, $record, $instrument, $event_id, $group_id, $survey_hash = null, $response_id = null, $repeat_instance = 1)
    {
        foreach ($this->getRules() as $rule) {
            if (($rule['type'] ?? 'single') !== 'single') continue;
            $algo = $rule['algorithm'] ?? 'iso7064_mod37_36';
            if ($algo === 'none') continue; // format-only rules are advisory
            $source = $rule['source'] ?? 'normalized_id';
            $strip  = $rule['strip'] ?? "-/ _|\\";
            foreach ($rule['fields'] as $field) {
                $value = $this->readValue($project_id, $record, $field, $event_id);
                if ($value === null || $value === '') continue;
                if (!CheckCharacter::validateId($algo, $source, $strip, $value)) {
                    $this->log('invalid-id-saved', [
                        'record'     => (string) $record,
                        'field'      => $field,
                        'value'      => $value,
                        'algorithm'  => $algo,
                        'instrument' => $instrument,
                    ]);
                }
            }
        }
    }

    // -- client injection ---------------------------------------------------

    private function injectClient()
    {
        $config = $this->buildClientConfig();
        if (empty($config['rules'])) return; // nothing configured for this project
        $engineUrl = $this->getUrl('js/engine.js');
        echo '<script>window.INSPIRE_VALIDATOR_CONFIG = '
            . json_encode($config, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
            . ';</script>' . "\n";
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

    /** Translate the repeatable "rules" project settings into engine rules. */
    private function getRules()
    {
        $out = [];
        $subs = $this->getSubSettings('rules');
        if (!is_array($subs)) return $out;

        foreach ($subs as $s) {
            $fields = $s['fields'] ?? [];
            if (!is_array($fields)) $fields = [$fields];
            $fields = array_values(array_filter($fields, function ($f) {
                return $f !== null && $f !== '';
            }));
            if (!$fields) continue;

            $rule = [
                'type'   => !empty($s['rule-type']) ? $s['rule-type'] : 'single',
                'fields' => $fields,
            ];
            if (!empty($s['algorithm'])) $rule['algorithm'] = $s['algorithm'];
            if (!empty($s['source']))    $rule['source']    = $s['source'];
            if (!empty($s['pattern']))   $rule['idPattern'] = $s['pattern'];
            if (!empty($s['strip']))     $rule['strip']     = $s['strip'];
            if (isset($s['expected-count']) && $s['expected-count'] !== '') {
                $rule['expectedIds'] = intval($s['expected-count']);
            }
            if (!empty($s['block-save'])) $rule['blockSave'] = $s['block-save'];
            $out[] = $rule;
        }
        return $out;
    }

    // -- server-side value read --------------------------------------------

    private function readValue($project_id, $record, $field, $event_id)
    {
        $params = [
            'project_id'    => $project_id,
            'return_format' => 'array',
            'records'       => [$record],
            'fields'        => [$field],
        ];
        if ($event_id) $params['events'] = [$event_id];
        $data = \REDCap::getData($params);
        $val = $this->digInto($data, $field);
        if ($val === null) return null;
        return is_string($val) ? $val : (string) $val;
    }

    /**
     * Pull a field value out of REDCap::getData()'s nested array. Handles the
     * common [record][event][field] shape and one extra level for repeating
     * instruments. Longitudinal/complex projects may need a tighter selector.
     */
    private function digInto($data, $field)
    {
        if (!is_array($data)) return null;
        foreach ($data as $rec) {
            if (!is_array($rec)) continue;
            foreach ($rec as $evt) {
                if (is_array($evt) && array_key_exists($field, $evt)) {
                    return $evt[$field];
                }
                if (is_array($evt)) {
                    foreach ($evt as $inner) {
                        if (is_array($inner) && array_key_exists($field, $inner)) {
                            return $inner[$field];
                        }
                    }
                }
            }
        }
        return null;
    }
}
