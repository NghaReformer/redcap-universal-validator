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
 *   - auditRule() queues each answer (queueReturns), and redcap_save_record
 *     writes the queue once (applyReturns), only where a field differs, so a
 *     save the browser already filled writes nothing;
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
        $m = GrowthReference::measure($raw, !empty($rule['decimalComma']));
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
            $limits, $decimals, !empty($marks['range']));
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
     * => text), and a rule problem as an 'unconfigurable' entry unless the
     * evaluator reported the same one for the measurement.
     */
    private function withReturned(array $out, $ret)
    {
        if ($ret === null) return $out;
        if (isset($ret['values']) && is_array($ret['values'])) $out['returns'] = $ret['values'];
        if (!empty($ret['problem'])) {
            foreach ($out['unconfigurable'] as $u) {
                if ($u['why'] === $ret['problem']) return $out;
            }
            $out['unconfigurable'][] = ['fields' => [(string) $ret['field']], 'why' => $ret['problem']];
        }
        return $out;
    }

    /**
     * The rule is inert here (its "when" is false, or no branch applies) and
     * returns nothing: the fields it owns are emptied. Only an audit that asks
     * for returns ($meta['returns']) gets them.
     */
    private function withClearedReturns(array $out, array $rule, array $meta)
    {
        if (empty($meta['returns'])) return $out;
        $fields = ModeRegistry::returnFields($rule);
        if ($fields) $out['returns'] = array_fill_keys($fields, '');
        return $out;
    }

    /** Queue what one audited context returned (auditRule). */
    private function queueReturns(array $values, array $measured, $event, $instance)
    {
        if (!$values) return;
        $inst = (int) ($instance ?: 1);
        $key = (string) $event . '|' . $inst;
        if (!isset($this->returnQueue[$key])) {
            $this->returnQueue[$key] = ['event' => $event, 'instance' => $inst, 'values' => [], 'fields' => []];
        }
        foreach ($values as $f => $text) $this->returnQueue[$key]['values'][(string) $f] = (string) $text;
        foreach ($measured as $f) $this->returnQueue[$key]['fields'][(string) $f] = true;
    }

    /** Whether two texts are the same number: "7.70" and "7.7", "7,7" and "7.7". Twin: QRID_sameNumber. */
    private static function sameReturnedNumber($a, $b)
    {
        $a = preg_replace('/,/', '.', trim((string) $a, " \t\r\n"), 1);
        $b = preg_replace('/,/', '.', trim((string) $b, " \t\r\n"), 1);
        return preg_match(Logic::NUM_RE, $a) && preg_match(Logic::NUM_RE, $b) && Logic::numCompare($a, $b) === 0;
    }

    /**
     * Write the queued values where they differ from what the record holds,
     * in one save. Returns whether anything was written (the caller then reads
     * the record again). Never throws: a failure is logged and the audit goes
     * on. The project setting "range-return-no-save" turns the write off.
     *
     * Where a value goes is read from the record itself (the same contexts the
     * audit uses): the event's own row, the instance of a repeating form, or
     * the instance of a repeating event. A form that has no row yet in that
     * context is left until it has data.
     */
    private function applyReturns($logMode, $pid, $record, $instrument = null)
    {
        $queue = $this->returnQueue;
        $this->returnQueue = [];
        if (!$queue) return false;
        try {
            $off = $this->getProjectSetting('range-return-no-save', $pid);
            if ($off === true || $off === 1 || $off === '1') return false;
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
            $data = [];
            foreach ($queue as $q) {
                $byForm = [];
                foreach ($q['values'] as $f => $text) {
                    if (!isset($dd[$f]['form_name']) || $dd[$f]['form_name'] === '') continue;
                    $byForm[(string) $dd[$f]['form_name']][$f] = $text;
                }
                foreach ($byForm as $form => $vals) {
                    $ctx = $this->returnContext($contexts, $form, $q['event'], $q['instance'], $pid);
                    if ($ctx === null) continue;
                    foreach ($vals as $f => $text) {
                        $cur = (isset($ctx['values'][$f]) && !is_array($ctx['values'][$f])) ? (string) $ctx['values'][$f] : '';
                        if ($cur === $text) continue;
                        if ($cur !== '' && $text !== '' && self::sameReturnedNumber($cur, $text)) continue;
                        if ($ctx['repeatKey'] === null) {
                            $data[$record][$ctx['event_id']][$f] = $text;
                        } else {
                            $data[$record]['repeat_instances'][$ctx['event_id']][$ctx['repeatKey']][$ctx['instance']][$f] = $text;
                        }
                    }
                }
            }
            if (!$data) return false;
            self::$returnWriting = true;
            try {
                $res = \REDCap::saveData($pid, 'array', $data, 'overwrite');
            } finally {
                self::$returnWriting = false;
            }
            if (is_array($res) && !empty($res['errors'])) {
                $msg = $res['errors'];
                if (is_array($msg)) {
                    $flat = [];
                    array_walk_recursive($msg, function ($v) use (&$flat) { $flat[] = (string) $v; });
                    $msg = implode('; ', $flat);
                }
                $this->logAuditError($logMode, $pid, $record, $instrument,
                    new \RuntimeException('the returned values could not be saved: ' . (string) $msg), 'return values');
                return false;
            }
            return true;
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
