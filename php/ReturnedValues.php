<?php
/**
 * Values a rule returns to fields: an @UVRANGE rule with a growth reference and
 * "return" works out the z-score of a measurement, the measurements at its
 * limits and the range as text, and the module writes them into Text fields of
 * the same form. The browser fills them as the page is used (QRIDRangeInit,
 * returnValues); this trait is the server's side, run for each save:
 *
 *   - returnsRange() works the values out for one record context, the twin of
 *     the browser's returnValues. ruleFindings() calls it for the modes that
 *     declare "returns" in php/modes.json, and carries the answer on its result
 *     as 'returns' (field => text; "" empties the field; a field that is not
 *     named keeps what it holds);
 *   - auditRule() queues each answer (queueReturns). redcap_save_record
 *     judges the rules that return values first and writes the queue
 *     (applyReturns) before it judges the others, one save per record
 *     context and only where a field differs, so a save the browser already
 *     filled writes nothing;
 *   - the write is itself a save, and redcap_save_record skips it
 *     (self::$returnWriting) rather than audit its own fill a second time.
 *
 * The project scan never asks for returns (ruleFindings only works them out
 * when $meta['returns'] is set, which only auditRule sets): a scan reads.
 */

namespace INSPIRE\UniversalValidator;

trait ReturnedValues
{
    /** @var array "event|instance" => ['event','instance','values' => [field => text],'fields' => measured fields] */
    private $returnQueue = [];

    /** True while this module writes returned values: that save is not audited again. */
    private static $returnWriting = false;

    /**
     * What a growth rule returns in one context, or null when it has nothing to
     * say here (its measured field is not on the form being audited).
     * 'values' is field => text, "" meaning nothing to report: an input is
     * blank or outside the reference, or the value cannot be made (no
     * measurement for the z-score, no limit for a bound). It is null when an
     * input could not be resolved in this context, with the reason in
     * 'problem': the fields keep what they hold. The bounds need no
     * measurement. A rule without a reference or without "return" owns nothing
     * and answers [] (a branch the rule did not pick empties its siblings'
     * fields). Browser twin: returnValues in QRIDRangeInit.
     *
     * @return array|null ['values' => array|null, 'problem' => string|null, 'field' => string|null]
     */
    private function returnsRange(array $rule, array $values, array $dupes, $onForm, $project_id, array $resolution, array $meta)
    {
        $targets = (isset($rule['rangeReturn']) && is_array($rule['rangeReturn'])) ? $rule['rangeReturn'] : [];
        if (!isset($rule['rangeReference']) || !$targets) {
            return ['values' => [], 'problem' => null, 'field' => null];
        }
        $field = null;
        foreach ((isset($rule['fields']) && is_array($rule['fields'])) ? $rule['fields'] : [] as $f) {
            if (isset($dupes[$f])) continue;
            if ($onForm !== null && !isset($onForm[$f])) continue;
            $field = $f;
            break;
        }
        if ($field === null) return null;
        $codes = (isset($meta['missingCodes']) && is_array($meta['missingCodes'])) ? $meta['missingCodes'] : [];
        $raw = (isset($values[$field]) && !is_array($values[$field])) ? (string) $values[$field] : '';
        if ($codes && self::isMissingCode($raw, $codes)) $raw = '';
        $scale = isset($rule['rangeMeasureScale']) ? (string) $rule['rangeMeasureScale'] : null;
        $m = GrowthReference::measure($raw, !empty($rule['decimalComma']), $scale);
        $g = $this->growthInputs($rule, $values, $resolution, $project_id);
        if (isset($g['problem'])) return ['values' => null, 'problem' => $g['problem'], 'field' => $field];
        if ($g['state'] !== 'ok') {
            return ['values' => array_fill_keys(array_values($targets), ''), 'problem' => null, 'field' => $field];
        }
        $tier = (isset($rule['rangeReturnLimits']) && $rule['rangeReturnLimits'] === 'hard') ? 'Hard' : 'Soft';
        $limits = [
            'lo' => isset($rule['range' . $tier . 'Lo']) ? (string) $rule['range' . $tier . 'Lo'] : null,
            'hi' => isset($rule['range' . $tier . 'Hi']) ? (string) $rule['range' . $tier . 'Hi'] : null,
        ];
        $decimals = isset($rule['rangeDecimals']) ? (int) $rule['rangeDecimals'] : 1;
        $marks = (isset($rule['rangeReturnComma']) && is_array($rule['rangeReturnComma'])) ? $rule['rangeReturnComma'] : [];
        $o = GrowthReference::outputs($g['entry'], $g['table'], $g['sex'], $g['x'], $m['state'] === 'ok' ? $m['y'] : null,
            $limits, $decimals, !empty($marks['range']), $scale,
            (isset($rule['rangeUnit']) && is_string($rule['rangeUnit']) && $rule['rangeUnit'] !== '') ? $rule['rangeUnit'] : null);
        $out = [];
        foreach ($targets as $k => $t) {
            $text = (isset($o[$k]) && $o[$k] !== null) ? (string) $o[$k] : '';
            // "range" was made in its field's decimal mark; a number is marked here
            if ($k !== 'range' && $text !== '') $text = GrowthReference::markText($text, !empty($marks[$k]));
            $out[$t] = $text;
        }
        return ['values' => $out, 'problem' => null, 'field' => $field];
    }

    /**
     * ruleFindings' result with what the rule returns added: 'returns' (field
     * => text, with every other field the rule owns emptied, see
     * ownedReturns), and a rule problem as an 'unconfigurable' entry unless
     * the evaluator reported the same one for the measurement.
     */
    private function withReturned(array $out, $ret, array $rule, array $meta)
    {
        if ($ret === null) return $out;
        if (isset($ret['values']) && is_array($ret['values'])) {
            $out['returns'] = array_merge(self::ownedReturns($rule, $meta), $ret['values']);
        }
        if (!empty($ret['problem'])) {
            foreach ($out['unconfigurable'] as $u) {
                if ($u['why'] === $ret['problem']) return $out;
            }
            $out['unconfigurable'][] = ['fields' => [(string) $ret['field']], 'why' => $ret['problem']];
        }
        return $out;
    }

    /**
     * Every field the rule returns to, emptied (field => ""), less the fields
     * of the branch groups that keep what they hold: those $held names and
     * those $meta['returnKeep'] names. The rule is $meta['returnOwner'] when
     * given: the event/instance audit judges a copy of the rule that carries
     * only the branches it picked (TemporalIntegration::auditTemporalRules),
     * and a branch it did not pick still owns its fields.
     */
    private static function ownedReturns(array $rule, array $meta, array $held = [])
    {
        $owner = (isset($meta['returnOwner']) && is_array($meta['returnOwner'])) ? $meta['returnOwner'] : $rule;
        $fields = array_fill_keys(ModeRegistry::returnFields($owner), '');
        if (!$fields) return [];
        $keep = array_merge((isset($meta['returnKeep']) && is_array($meta['returnKeep'])) ? $meta['returnKeep'] : [], $held);
        foreach ($keep as $g) {
            foreach (ModeRegistry::returnFields($owner, (string) $g) as $f) unset($fields[$f]);
        }
        return $fields;
    }

    /**
     * Queue what one audited context returned (auditRule). $loose: the
     * returned fields that are not rewritten when they already hold the same
     * number spelled another way, whatever their validation (a mode whose
     * values are all numbers, php/modes.json "returns" "sameNumber":"always").
     * Any other field is compared that way only when it holds numbers
     * (numberMarkOf), so "007" in a Text field is not "7".
     */
    private function queueReturns(array $values, array $measured, $event, $instance, array $loose = [])
    {
        if (!$values) return;
        $inst = (int) ($instance ?: 1);
        $key = (string) $event . '|' . $inst;
        if (!isset($this->returnQueue[$key])) {
            $this->returnQueue[$key] = ['event' => $event, 'instance' => $inst, 'values' => [], 'fields' => [], 'loose' => []];
        }
        foreach ($values as $f => $text) $this->returnQueue[$key]['values'][(string) $f] = (string) $text;
        foreach ($measured as $f) $this->returnQueue[$key]['fields'][(string) $f] = true;
        foreach ($loose as $f) $this->returnQueue[$key]['loose'][(string) $f] = true;
    }

    /**
     * Whether the project turned off the write on save for the values this
     * rule's mode returns (php/modes.json "returns" "noSave" names the
     * checkbox setting: "range-return-no-save", "exists-return-no-save"). The
     * page still fills the fields.
     */
    private function returnsOffOnSave(array $rule, $pid)
    {
        $rt = ModeRegistry::returns(ModeRegistry::modeOfType(isset($rule['type']) ? $rule['type'] : ''));
        $key = ($rt !== null && isset($rt['noSave']) && is_string($rt['noSave'])) ? $rt['noSave'] : null;
        if ($key === null) return false;
        $off = $this->getProjectSetting($key, $pid);
        return $off === true || $off === 1 || $off === '1';
    }

    /** @var bool whether the save being audited is a survey respondent's (redcap_save_record) */
    private $surveySave = false;

    /**
     * Whether the save being audited writes the values $rule returns: not for
     * a mode that returns nothing, not when the project turned the write off
     * (returnsOffOnSave), and not on a survey respondent's save for a mode
     * whose values come from other records (php/modes.json "returns"
     * "noSurveySave": a respondent who resumes the survey would see them).
     * The fields are then neither filled nor emptied.
     */
    private function returnsWrittenOnSave(array $rule, $pid)
    {
        $rt = ModeRegistry::returns(ModeRegistry::modeOfType(isset($rule['type']) ? $rule['type'] : ''));
        if ($rt === null) return false;
        if ($this->surveySave && !empty($rt['noSurveySave'])) return false;
        return !$this->returnsOffOnSave($rule, $pid);
    }

    /** The returned fields of $rule queueReturns compares as numbers whatever they hold ("sameNumber":"always"). */
    private static function looseReturns(array $rule, array $returned)
    {
        $rt = ModeRegistry::returns(ModeRegistry::modeOfType(isset($rule['type']) ? $rule['type'] : ''));
        return ($rt !== null && isset($rt['sameNumber']) && $rt['sameNumber'] === 'always') ? array_keys($returned) : [];
    }

    /** Whether two texts are the same number: "7.70" and "7.7", "7,7" and "7.7". Twin: QRID_sameNumber. */
    private static function sameReturnedNumber($a, $b)
    {
        $a = preg_replace('/,/', '.', trim((string) $a, " \t\r\n"), 1);
        $b = preg_replace('/,/', '.', trim((string) $b, " \t\r\n"), 1);
        return preg_match(Logic::NUM_RE, $a) && preg_match(Logic::NUM_RE, $b) && Logic::numCompare($a, $b) === 0;
    }

    /**
     * Write the queued values where they differ from what the record holds.
     * Returns whether anything was written (the caller then reads the record
     * again). Never throws: a failure is logged and the audit goes on. A
     * mode's "noSave" project setting keeps its values out of the queue
     * (auditRule, returnsOffOnSave).
     *
     * Where a value goes is read from the record itself (the same contexts the
     * audit uses): the event's own row, the instance of a repeating form, or
     * the instance of a repeating event. A form that has no row yet in that
     * context is left until it has data.
     *
     * Each context is written by a save of its own, the saved one ($event,
     * $instance) first. A rule with event or instance references is audited
     * in every context of the record, so one save can refill the returned
     * fields of other visits too (an input they read changed). A context that
     * cannot be written (a locked form, a visit outside the user's rights) is
     * logged on its own and the others are still written.
     */
    private function applyReturns($logMode, $pid, $record, $instrument = null, $event = null, $instance = null)
    {
        $queue = $this->returnQueue;
        $this->returnQueue = [];
        if (!$queue) return false;
        try {
            $dd = $this->dataDictionary($pid);
            if (!$dd) return false;
            $read = [];
            foreach ($queue as $q) {
                foreach ($q['values'] as $f => $_) {
                    $read[$f] = true;
                    if (isset($dd[$f]['form_name']) && $dd[$f]['form_name'] !== '') $read[$dd[$f]['form_name'] . '_complete'] = true;
                }
                foreach ($q['fields'] as $f => $_) $read[$f] = true;
            }
            $pk = null;
            try {
                if (is_callable(['\REDCap', 'getRecordIdField'])) $pk = \REDCap::getRecordIdField();
            } catch (\Throwable $e) {
                $pk = null;
            }
            if (is_string($pk) && $pk !== '') $read[$pk] = true;
            $whole = \REDCap::getData([
                'project_id' => $pid, 'return_format' => 'array', 'records' => [$record], 'fields' => array_keys($read),
            ]);
            if (!is_array($whole) || !isset($whole[$record]) || !is_array($whole[$record])) return false;
            $contexts = self::recordContexts($whole[$record]);
            $writes = [];   // "event|repeatKey|instance" => ['event_id', 'instance', 'data']
            foreach ($queue as $q) {
                $byForm = [];
                foreach ($q['values'] as $f => $text) {
                    if (!isset($dd[$f]['form_name']) || $dd[$f]['form_name'] === '') continue;
                    $byForm[(string) $dd[$f]['form_name']][$f] = $text;
                }
                foreach ($byForm as $form => $vals) {
                    $ctx = $this->returnContext($contexts, $form, $q['event'], $q['instance'], $pid);
                    if ($ctx === null) continue;
                    $key = (string) $ctx['event_id'] . '|' . (string) $ctx['repeatKey'] . '|' . (int) $ctx['instance'];
                    foreach ($vals as $f => $text) {
                        $cur = (isset($ctx['values'][$f]) && !is_array($ctx['values'][$f])) ? (string) $ctx['values'][$f] : '';
                        if ($cur === $text) continue;
                        $loose = isset($q['loose'][$f]) || self::numberMarkOf($dd[$f]) !== null;
                        if ($loose && $cur !== '' && $text !== '' && self::sameReturnedNumber($cur, $text)) continue;
                        if (!isset($writes[$key])) {
                            $writes[$key] = ['event_id' => $ctx['event_id'], 'instance' => (int) $ctx['instance'], 'data' => []];
                        }
                        if ($ctx['repeatKey'] === null) {
                            $writes[$key]['data'][$record][$ctx['event_id']][$f] = $text;
                        } else {
                            $writes[$key]['data'][$record]['repeat_instances'][$ctx['event_id']][$ctx['repeatKey']][$ctx['instance']][$f] = $text;
                        }
                    }
                }
            }
            if (!$writes) return false;
            // The saved context first, the others in the order the record holds them.
            $first = [];
            foreach ($writes as $key => $w) {
                if (($event === null || (string) $w['event_id'] === (string) $event) && $w['instance'] === (int) ($instance ?: 1)) {
                    $first[$key] = $w;
                }
            }
            $wrote = false;
            foreach ($first + $writes as $w) {
                $why = null;
                self::$returnWriting = true;
                try {
                    $res = \REDCap::saveData($pid, 'array', $w['data'], 'overwrite');
                    if (is_array($res) && !empty($res['errors'])) {
                        $why = $res['errors'];
                        if (is_array($why)) {
                            $flat = [];
                            array_walk_recursive($why, function ($v) use (&$flat) { $flat[] = (string) $v; });
                            $why = implode('; ', $flat);
                        }
                    }
                } catch (\Throwable $e) {
                    $why = $e->getMessage();
                } finally {
                    self::$returnWriting = false;
                }
                if ($why === null) {
                    $wrote = true;
                    continue;
                }
                $this->logAuditError($logMode, $pid, $record, $instrument,
                    new \RuntimeException('the returned values could not be saved in event ' . (string) $w['event_id']
                        . ', instance ' . $w['instance'] . ': ' . (string) $why), 'return values');
            }
            // This write is not audited (redcap_save_record returns at once),
            // so a read kept for later lookups learns of it here.
            if ($wrote) $this->refreshExistsReads($pid, $record);
            return $wrote;
        } catch (\Throwable $e) {
            self::$returnWriting = false;
            $this->logAuditError($logMode, $pid, $record, $instrument, $e, 'return values');
            return false;
        }
    }

    /**
     * The one context of $form in $event at $instance, or null: the event's
     * base row for a form that does not repeat, the instance of a repeating
     * form, the instance of a repeating event (hostContextsFor decides which
     * rows hold the form). An unknown event is taken only when the form has
     * exactly one such row in the record.
     */
    private function returnContext(array $contexts, $form, $event, $instance, $pid)
    {
        $found = null;
        $n = 0;
        foreach ($this->hostContextsFor($contexts, $form, $pid) as $c) {
            if ($event !== null && $event !== '' && (string) $c['event_id'] !== (string) $event) continue;
            if ((int) $c['instance'] !== (int) $instance) continue;
            $found = $c;
            $n++;
        }
        return $n === 1 ? $found : null;
    }
}
