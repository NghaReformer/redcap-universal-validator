<?php

namespace INSPIRE\UniversalValidator;

require_once __DIR__ . '/ModeRegistry.php';
require_once __DIR__ . '/TemporalLogic.php';

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
 *
 * THE STATE IS PUBLIC AND build() IS ONLY ONE WAY TO FILL IT. build() reads the
 * project as it is NOW. A report on a stored run has to be labelled as the
 * project was WHEN THE RUN WAS PLANNED - a rule renamed since must not rename
 * the findings it produced - and that comes from the uv_scan_dim snapshot,
 * which has no producer and no reader yet. Wave 11 writes both and fills these
 * properties from the snapshot; there is deliberately no fromSnapshot() here
 * until there is a snapshot to read, because a reader written against an
 * imagined format is a second format.
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
    /** @var array rule ordinal (1-based) => ['label'=>, 'message'=>, 'messages'=>, 'assert'=>, 'type'=>, 'fields'=>, 'detail'=>] */
    public $rules = [];
    /**
     * @var array rule source id => rule ordinal. A STORED finding names its rule
     * by source id, which survives a rule being added above it; the ordinal it
     * also carries does not. Empty until something that knows the source ids
     * fills it (wave 11, from the run's snapshot) - ruleOf() then falls back to
     * the ordinal.
     */
    public $rulesBySource = [];
    /**
     * @var array|null field name => true for every field REDCap flags as an
     * Identifier. NULL means the flags could not be read, which is NOT the same
     * as "no field is an identifier" - Scan\FindingRowMapper withholds on null.
     */
    public $identifiers = null;
    /** What REDCap's dictionary may hold in `identifier` for a flagged field. */
    const IDENTIFIER_FLAGS = ['y', 'yes', '1', 'true'];
    /** @var bool whether this project uses Data Access Groups at all */
    public $hasDags = false;
    /** @var bool whether this project is longitudinal (more than one event) */
    public $longitudinal = false;

    /**
     * @var bool REDCap positively stated this project is classic. Distinct from
     * !$longitudinal, which is also what "we could not tell" looks like.
     */
    public $singleEventProved = false;
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

        // Identifier flags come from the same dictionary read, by the SAME RULE
        // UniversalValidator::projectIdentifierFields() applies when the scan
        // decides what to store: the same accepted words, and null only when
        // there is no dictionary. Two readings of one flag that disagree would
        // have the report show a value the scan was careful to redact, or
        // withhold one it kept. tests/scan_report_php.php holds them together.
        $flags = [];
        foreach ($dd as $name => $meta) {
            if (isset($meta['field_label'])) $d->fieldLabels[$name] = (string) $meta['field_label'];
            if (!empty($meta['form_name']))  $d->fieldForms[$name]  = (string) $meta['form_name'];
            $flag = isset($meta['identifier']) ? strtolower(trim((string) $meta['identifier'])) : '';
            if (in_array($flag, self::IDENTIFIER_FLAGS, true)) $flags[$name] = true;
        }
        $d->identifiers = $dd ? $flags : null;

        // Events. The whole map in ONE call, not one call per finding. Unique
        // names embed the arm (event_1_arm_2), which matters because two arms
        // routinely share a display label like "Baseline" — keying by id and
        // showing the unique name keeps two different visits from merging.
        // REDCap's own answer to "is this project longitudinal", asked FIRST,
        // because it decides whether an empty event map is a failure or the
        // correct answer. A classic project has no event names to return.
        $shape = self::projectIsLongitudinal($pid);

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
        // A CLASSIC project returning no event names is not a degraded read.
        //
        // 1.8.6 made the Event column survive an unreadable event map, on the
        // grounds that dropping it is the claim "every finding is in the same
        // event". Correct - but it treated an EMPTY answer as an unreadable one,
        // and on a classic project REDCap returns nothing because there is
        // nothing to return. Live on a real classic project that produced a
        // column of one repeated internal event id on every row, under a yellow
        // warning that labels could not be read. Nothing had failed.
        //
        // Only REDCap saying "not longitudinal" clears it. A null answer - no
        // project object, an older build - leaves 1.8.6's behaviour standing,
        // because "cannot tell" must not silently drop a column that may be
        // carrying the only thing separating two rows.
        if ($shape === false) {
            unset($d->degraded['events']);
            $d->singleEventProved = true;
        }
        $d->longitudinal = count($d->events) > 1;
        if ($shape === true) $d->longitudinal = true;
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
            $type = isset($r['type']) ? (string) $r['type'] : '';
            $t = self::ruleText($r, $type);
            $d->rules[$i + 1] = [
                'type'     => $type,
                'label'    => $t['label'],
                'message'  => $t['message'],
                'messages' => $t['messages'],
                'assert'   => $t['assert'],
                'fields'  => (isset($r['fields']) && is_array($r['fields'])) ? $r['fields'] : [],
                'detail'  => $t['detail'],
            ];
            // A branched rule carries its wording and limits per branch; a
            // finding names the branch that judged it (rule()).
            if (isset($r['branches']) && is_array($r['branches'])) {
                foreach ($r['branches'] as $bi => $b) {
                    if (is_array($b)) $d->rules[$i + 1]['branches'][$bi] = self::ruleText($b, $type);
                }
            }
        }
        return $d;
    }

    /**
     * The text of one rule (or one branch) a report shows: its label, message,
     * assert and the keys its mode lists under scan.detailKeys
     * (php/modes.json), for the catalog's "detail" sentence. Scalars only: a
     * snapshot is text.
     */
    private static function ruleText(array $r, $type)
    {
        $detail = [];
        foreach (ModeRegistry::detailKeys($type) as $k) {
            if (isset($r[$k]) && is_scalar($r[$k])) $detail[$k] = (string) $r[$k];
        }
        $detail += ModeRegistry::derivedDetail($type, $r);
        // reason => the rule's text for it (scan.reasonMessages), '' when the
        // rule has none: MessageCatalog then uses the catalog, not "message".
        $messages = [];
        foreach (ModeRegistry::reasonMessageKeys($type) as $reason => $k) {
            $messages[$reason] = (isset($r[$k]) && is_string($r[$k])) ? $r[$k] : '';
        }
        return [
            'label'    => isset($r['note']) ? (string) $r['note'] : '',
            'message'  => isset($r['message']) ? (string) $r['message'] : '',
            'messages' => $messages,
            'assert'   => isset($r['assert']) ? (string) $r['assert'] : '',
            'detail'   => $detail,
        ];
    }

    /**
     * Whether the Event column has to be shown.
     *
     * Dropping it is a claim - "every finding in this report is in the same
     * event, so the column would carry nothing" - and that claim needs the
     * event metadata to be readable. When it is not, all three routes to
     * $longitudinal fail and the column was dropped anyway, so two findings on
     * the same field in DIFFERENT events rendered as byte-identical rows with
     * nothing to tell them apart. The degraded note said the names were
     * unreadable; it did not put the ids back.
     *
     * So: show the column whenever the project cannot be PROVED single-event.
     * event() already falls back to the raw id, which is a worse label than a
     * name and an infinitely better one than a missing column.
     */
    public function needsEventColumn()
    {
        if ($this->longitudinal) return true;
        if ($this->singleEventProved) return false;   // REDCap says classic: nothing to show
        return isset($this->degraded['events']);
    }

    /**
     * REDCap's own answer to whether $pid is longitudinal: true, false, or NULL
     * when it cannot be established.
     *
     * $Proj is REDCap's project object for the current request and carries the
     * flag directly, which is the only source that distinguishes "classic" from
     * "the event map could not be read" - getEventNames() and
     * getInstrumentEventMappings() both return nothing in BOTH cases.
     *
     * Guarded three ways: the global must exist, be an object, and be about THIS
     * project. A $Proj left over from another pid would answer a question about
     * the wrong project, which is worse than not answering.
     */
    private static function projectIsLongitudinal($pid)
    {
        try {
            if (!isset($GLOBALS['Proj']) || !is_object($GLOBALS['Proj'])) return null;
            $p = $GLOBALS['Proj'];
            if (!isset($p->project_id) || (string) $p->project_id !== (string) $pid) return null;
            if (isset($p->longitudinal)) return (bool) $p->longitudinal;
            // Older builds expose the count but not the flag.
            if (isset($p->numEvents) && is_numeric($p->numEvents)) return ((int) $p->numEvents) > 1;
        } catch (\Throwable $e) {
        }
        return null;
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
    public function rule($ordinal, $branch = null)
    {
        if (!isset($this->rules[$ordinal])) {
            return ['type' => '', 'label' => '', 'message' => '', 'messages' => [], 'assert' => '', 'fields' => [], 'detail' => []];
        }
        $r = $this->rules[$ordinal];
        // The branch that judged a finding speaks for it: its message and its
        // limits, never another branch's. A label only the rule has stays.
        // A message does not: a branch without one shows the default wording,
        // as it does on the page (branches never inherit a rule-level message).
        if ($branch !== null && isset($r['branches'][$branch])) {
            $b = $r['branches'][$branch];
            if ($b['label'] === '') $b['label'] = $r['label'];
            $r = array_merge($r, $b);
        }
        unset($r['branches']);
        return $r;
    }

    /**
     * The rule a finding belongs to: its snapshot, and the ordinal to show.
     *
     * BY SOURCE ID FIRST, where the finding has one and this object knows it. A
     * source id names the rule itself; an ordinal names a position, and the
     * position moves when a rule is added above it. Then by the ordinal the
     * finding carries. An ordinal below 1 is NOT a rule - the duplicate findings
     * Scan\UniqueFinalizer writes carry 0 - and answers with the empty shape and
     * ordinal 0, which the Rule column renders as an empty cell.
     *
     * @return array the rule() shape, plus `ordinal` (int, 0 when there is none)
     */
    public function ruleOf(array $finding)
    {
        $ord = 0;
        $source = isset($finding['ruleSource']) ? (string) $finding['ruleSource'] : '';
        if ($source !== '' && isset($this->rulesBySource[$source])) {
            $ord = (int) $this->rulesBySource[$source];
        } elseif (isset($finding['rule']) && is_numeric($finding['rule'])) {
            $ord = (int) $finding['rule'];
        }
        if ($ord < 1) $ord = 0;
        // The branch that judged it, where the finding says (see rule()).
        $rule = $this->rule($ord, isset($finding['branch']) ? $finding['branch'] : null);
        // A rule this object does not know keeps the ordinal the finding gave:
        // "Rule 12" with no name is a worse cell than a named one and a far
        // better one than nothing.
        $rule['ordinal'] = $ord;
        return $rule;
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
