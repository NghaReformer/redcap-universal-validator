<?php

namespace INSPIRE\UniversalValidator;

/**
 * Every LABEL a scan report needs, read once per scan.
 *
 * A finding carries keys — event id, form name, field name, rule ordinal. A
 * report has to show names. Resolving a name per finding would be one metadata
 * lookup per row, so all of it is read once here and looked up per row from
 * memory. The whole object is bounded by the PROJECT (its events, instruments,
 * fields, rules and groups), never by the data, so it costs the same on forty
 * records as on forty thousand.
 *
 * Two rules, both learned the hard way in this module:
 *
 *   - Every source is probed with is_callable and wrapped in try/catch. The
 *     framework serves methods through __call(), for which method_exists()
 *     answers false — that is how @UVUNIQUE shipped inert in v1.4.0 while every
 *     mocked test passed.
 *   - A source that cannot be read NEVER guesses and never blanks. The raw key
 *     is shown instead (event_id rather than a name), and the failure is
 *     recorded in degraded[] so the report can say a column is unreliable.
 *     Degradation nobody can see is the failure this module exists to prevent.
 */
final class ScanDimensions
{
    /** @var array event_id => display name */
    public $events = [];
    /** @var array form name => display label */
    public $forms = [];
    /** @var array field name => field label */
    public $fieldLabels = [];
    /** @var array field name => form name */
    public $fieldForms = [];
    /** @var array rule ordinal (1-based) => ['label'=>, 'message'=>, 'assert'=>, 'type'=>] */
    public $rules = [];
    /** @var bool whether this project uses Data Access Groups at all */
    public $hasDags = false;
    /** @var bool whether this project is longitudinal (more than one event) */
    public $longitudinal = false;
    /** @var array source => why it could not be read */
    public $degraded = [];

    /**
     * @param array      $dd    the data dictionary, already read by the scan
     * @param array      $rules getRules() output, already computed by the scan
     * @param array|null $dags  group id => name, or null when unavailable
     */
    public static function build($pid, array $dd, array $rules)
    {
        $d = new self();

        foreach ($dd as $name => $meta) {
            if (isset($meta['field_label'])) $d->fieldLabels[$name] = (string) $meta['field_label'];
            if (!empty($meta['form_name']))  $d->fieldForms[$name]  = (string) $meta['form_name'];
        }

        // Events. The whole map in ONE call, not one call per finding. Unique
        // names embed the arm (event_1_arm_2), which matters because two arms
        // routinely share a display label like "Baseline" — keying by id and
        // showing the unique name keeps two different visits from merging.
        $cap = ScanCapabilities::eventNames();
        if ($cap['state'] !== ScanCapabilities::OK) {
            $d->degraded['events'] = $cap['why'];
        } else {
            try {
                $map = \REDCap::getEventNames(true);
                if (is_array($map)) foreach ($map as $id => $nm) $d->events[$id] = (string) $nm;
                if (!$d->events) $d->degraded['events'] = 'no event names were returned';
            } catch (\Throwable $e) {
                $d->degraded['events'] = 'reading event names failed: ' . get_class($e);
            }
        }
        // From the project's SHAPE, not from whether the label read succeeded.
        // Deriving it from count($d->events) meant an unavailable getEventNames
        // dropped the Event column on a longitudinal project, so two findings on
        // the same field in different events rendered as identical rows with
        // nothing saying anything was lost. event() already falls back to the raw
        // id, which is the correct degradation.
        $d->longitudinal = count($d->events) > 1;
        try {
            if (is_callable(['\REDCap', 'getEventNames'])) {
                $all = \REDCap::getEventNames(true);
                if (is_array($all) && count($all) > 1) $d->longitudinal = true;
            }
            if (!$d->longitudinal && is_callable(['\REDCap', 'getInstrumentEventMappings'])) {
                $mapEv = \REDCap::getInstrumentEventMappings($pid);
                if (is_array($mapEv)) {
                    $seen = [];
                    foreach ($mapEv as $row) {
                        if (is_array($row) && isset($row['event_id'])) $seen[$row['event_id']] = true;
                    }
                    if (count($seen) > 1) $d->longitudinal = true;
                }
            }
        } catch (\Throwable $e) {
        }

        // Instrument labels.
        try {
            if (is_callable(['\REDCap', 'getInstrumentNames'])) {
                $map = \REDCap::getInstrumentNames();
                if (is_array($map)) foreach ($map as $f => $lbl) $d->forms[$f] = (string) $lbl;
            }
            if (!$d->forms) $d->degraded['forms'] = 'instrument labels are unavailable; form names are shown instead';
        } catch (\Throwable $e) {
            $d->degraded['forms'] = 'reading instrument labels failed: ' . get_class($e);
        }

        // DAG names, whole map in one call.
        $cap = ScanCapabilities::dagNames();
        if ($cap['state'] !== ScanCapabilities::OK) {
            $d->degraded['dags'] = $cap['why'];
        } else {
            try {
                $g = \REDCap::getGroupNames(true);
                if (is_array($g)) {
                    $d->hasDags = (bool) $g;          // an empty array really is "no groups"
                } else {
                    // A non-array is a FAILED read, and it must not look like a
                    // project that simply has no groups. Only a throw was caught
                    // before, so this vanished silently.
                    $d->degraded['dags'] = 'Data Access Groups could not be read, so the DAG column '
                        . 'is omitted — this is not evidence the project has no groups';
                }
            } catch (\Throwable $e) {
                $d->degraded['dags'] = 'reading Data Access Groups failed: ' . get_class($e);
            }
        }

        // Rules, keyed by the ordinal a finding cites. This is what makes
        // "Rule 12" mean something to a reader a week later.
        foreach ($rules as $i => $r) {
            $d->rules[$i + 1] = [
                'type'    => isset($r['type']) ? (string) $r['type'] : '',
                'label'   => isset($r['note']) ? (string) $r['note'] : '',
                'message' => isset($r['message']) ? (string) $r['message'] : '',
                'assert'  => isset($r['assert']) ? (string) $r['assert'] : '',
                'fields'  => (isset($r['fields']) && is_array($r['fields'])) ? $r['fields'] : [],
            ];
        }
        return $d;
    }

    /** The event's display name, or the raw id when names could not be read. */
    public function event($id)
    {
        if ($id === null || $id === '') return '';
        return isset($this->events[$id]) ? $this->events[$id] : (string) $id;
    }

    /** The instrument's label, or its machine name when labels are unavailable. */
    public function form($name)
    {
        if ($name === null || $name === '') return '';
        return isset($this->forms[$name]) ? $this->forms[$name] : (string) $name;
    }

    /** The field's label, or its machine name. */
    public function fieldLabel($name)
    {
        return isset($this->fieldLabels[$name]) ? $this->fieldLabels[$name] : (string) $name;
    }

    /** One rule's snapshot, or an empty shape — never a missing-index warning. */
    public function rule($ordinal)
    {
        return isset($this->rules[$ordinal])
            ? $this->rules[$ordinal]
            : ['type' => '', 'label' => '', 'message' => '', 'assert' => '', 'fields' => []];
    }

    /** True when any label source could not be read. */
    public function isDegraded()
    {
        return (bool) $this->degraded;
    }

    /**
     * One sentence naming every label source that could not be read.
     *
     * degraded[] was populated in four places and read in none, which made the
     * class docblock's promise — "degradation nobody can see is the failure this
     * module exists to prevent" — untrue of the class itself.
     */
    public function degradedSummary()
    {
        if (!$this->degraded) return '';
        return 'Some labels could not be read, so raw identifiers are shown instead: '
             . implode('; ', $this->degraded);
    }
}
