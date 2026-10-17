<?php

namespace INSPIRE\UniversalValidator;

require_once __DIR__ . '/ModeRegistry.php';

/**
 * What a scan report shows, declared once and rendered the same way everywhere.
 *
 * ONE array of descriptors, not a class hierarchy. An earlier design had seven
 * abstractions — column providers, data requirements, enrichers, renderers,
 * codecs — for fifteen columns with one consumer and no third-party plugin
 * story. The property that actually mattered is kept:
 *
 *   THE REPORT LAYER CONTAINS NO switch ($type) AND NO HARD-CODED COLUMN LIST.
 *
 * Every dispatch goes through this array or through MessageCatalog's wildcard
 * chain, both of which have a total fallback. So a rule type added later is
 * stored, counted, exported and truthfully described on the day it appears —
 * it degrades to generic wording, never to an invisible row. A `switch` over
 * $type anywhere in this layer is a bug, because it turns "unknown type" from
 * "generic wording" into "silent hole".
 *
 * Adding a column is one entry here. The HTML table, the CSV export and any
 * later exporter all read this list, so none of them has to be touched.
 *
 * Each descriptor:
 *   key      stable machine name, used as the CSV header
 *   label    what a reader sees
 *   group    location | problem | provenance | link — display order only
 *   visible  callable(ScanDimensions): bool — a column absent on this project's
 *            shape is ABSENT, not present-and-empty. A classic project has no
 *            Event column at all; a project with no DAGs has no DAG column.
 *   render   callable(array $finding, ScanDimensions $d): string
 */
final class ScanColumns
{
    /**
     * What the Value cell says when it may not, or cannot, show the value.
     *
     * Keyed by the finding's `valueState`, which Scan\FindingRowMapper sets from
     * a stored row. Every state that is NOT here shows the value itself. They
     * are separate words because they are separate facts, and a reader acts
     * differently on each: an empty field is a finding about the data, a value
     * withheld from THIS reader is there for somebody else, one that was never
     * kept cannot be recovered by anybody, and one that has expired could have
     * been read last month.
     *
     * A finding with no `valueState` - everything the legacy sink produces -
     * renders exactly as it did before this map existed.
     */
    const VALUE_WORDS = [
        'blank'      => '',
        'not-kept'   => '[not kept by this scan]',
        'expired'    => '[no longer kept]',
        'withheld'   => '[withheld by policy]',
        'identifier' => '[identifier withheld]',
        'not-text'   => '[not valid text]',
    ];
    /** Appended to a value the scan could only keep the start of. */
    const VALUE_CUT = ' … (cut short)';

    /** @return array[] descriptors, in display order */
    public static function all(ScanDimensions $d)
    {
        $cols = [
            [
                'key' => 'issue', 'label' => 'Issue', 'group' => 'problem',
                'visible' => function () { return true; },
                // Derived, never switched on: the labels live in
                // php/modes.json, and an unknown type falls to the check
                // mode's "Wrong value", so it still lands somewhere truthful.
                // A 'choices' finding is NOT a wrong value: the code was a
                // legal option when it was saved and the rule list changed
                // under it. Filing it beside a mistyped ID sends it to the
                // wrong person - one is a data-entry error, the other a design
                // change with existing data behind it.
                'render' => function (array $f) {
                    return ModeRegistry::issueLabel(isset($f['type']) ? $f['type'] : '',
                                                    isset($f['reason']) ? $f['reason'] : '');
                },
            ],
            [
                'key' => 'record', 'label' => 'Record', 'group' => 'location',
                'visible' => function () { return true; },
                'render' => function (array $f) { return (string) $f['record']; },
            ],
            [
                'key' => 'dag', 'label' => 'Data Access Group', 'group' => 'location',
                'visible' => function (ScanDimensions $d) { return $d->hasDags; },
                'render' => function (array $f) {
                    return (isset($f['dag']) && $f['dag'] !== null && $f['dag'] !== '')
                        ? (string) $f['dag'] : '—';
                },
            ],
            [
                'key' => 'event', 'label' => 'Event', 'group' => 'location',
                // A classic project has one event and no Event column - but
                // "this project is classic" is a claim, and an unreadable event
                // map cannot support it. See needsEventColumn().
                'visible' => function (ScanDimensions $d) { return $d->needsEventColumn(); },
                'render' => function (array $f, ScanDimensions $d) { return $d->event($f['event_id']); },
            ],
            [
                'key' => 'instrument', 'label' => 'Instrument', 'group' => 'location',
                'visible' => function () { return true; },
                'render' => function (array $f, ScanDimensions $d) {
                    return isset($f['instrument']) ? $d->form($f['instrument']) : '';
                },
            ],
            [
                'key' => 'instance', 'label' => 'Instance', 'group' => 'location',
                'visible' => function () { return true; },
                // 1 on a form that does not repeat carries no information.
                'render' => function (array $f) {
                    return ((int) $f['instance']) > 1 ? (string) $f['instance'] : '';
                },
            ],
            [
                'key' => 'field', 'label' => 'Field', 'group' => 'location',
                'visible' => function () { return true; },
                'render' => function (array $f) { return (string) $f['field']; },
            ],
            [
                'key' => 'field_label', 'label' => 'Field label', 'group' => 'location',
                'visible' => function (ScanDimensions $d) { return (bool) $d->fieldLabels; },
                'render' => function (array $f, ScanDimensions $d) { return $d->fieldLabel($f['field']); },
            ],
            [
                'key' => 'value', 'label' => 'Value', 'group' => 'problem',
                'visible' => function () { return true; },
                'render' => function (array $f, ScanDimensions $d) {
                    if (isset($f['valueState'])) {
                        $state = (string) $f['valueState'];
                        if (isset(self::VALUE_WORDS[$state])) return self::VALUE_WORDS[$state];
                        $v = isset($f['value']) ? (string) $f['value'] : '';
                        return $state === 'truncated' ? $v . self::VALUE_CUT : $v;
                    }
                    if (isset($f['value']) && $f['value'] !== null) return (string) $f['value'];
                    // An empty cell is what a genuinely blank field renders, so
                    // "we are not allowed to show this" must look different from
                    // "there is nothing to show".
                    return (isset($f['valueWithheld']) && $f['valueWithheld'])
                        ? '[withheld by policy]' : '';
                },
            ],
            [
                // The rule's KIND, kept verbatim. 'issue' folds five types into
                // three words for triage; this keeps the distinction the data
                // actually carries.
                'key' => 'check', 'label' => 'Check', 'group' => 'problem',
                'visible' => function () { return true; },
                'render' => function (array $f) { return isset($f['type']) ? (string) $f['type'] : ''; },
            ],
            [
                // The reason code, verbatim. Without it a single-value rule with
                // both a pattern and a check-character algorithm, carrying an
                // authored message, produced identical rows for a format failure
                // and a mistyped check digit: the distinction exists in the data
                // and was discarded on the way out.
                'key' => 'reason', 'label' => 'Reason code', 'group' => 'problem',
                'visible' => function () { return true; },
                'render' => function (array $f) {
                    $r = isset($f['reason']) ? (string) $f['reason'] : '';
                    // 'assert:<expression>' repeats the whole rule expression on
                    // every finding; the expression belongs to the rule and is
                    // shown once, from the rule snapshot. ReasonCode is the ONE
                    // place that decides what the code of a reason is - this cut
                    // the string at the colon itself, which agreed with it until
                    // the day one of them changed. An empty reason stays an
                    // empty cell; 'unspecified' is a storage value, not a word
                    // for a reader.
                    return $r === '' ? '' : Scan\ReasonCode::code($r);
                },
            ],
            [
                'key' => 'rule', 'label' => 'Rule', 'group' => 'problem',
                'visible' => function () { return true; },
                // The ordinal ruleOf() settled on, which for a finding that
                // names its rule by source id may not be the one it carries. A
                // finding no rule can be found for shows NOTHING here: the
                // duplicate findings the finalizer writes carry ordinal 0, and
                // "Rule 0" is a rule that does not exist.
                'render' => function (array $f, ScanDimensions $d) {
                    $r = $d->ruleOf($f);
                    return $r['ordinal'] > 0 ? (string) $r['ordinal'] : '';
                },
            ],
            [
                'key' => 'rule_label', 'label' => 'Rule name', 'group' => 'problem',
                'visible' => function () { return true; },
                'render' => function (array $f, ScanDimensions $d) {
                    $r = $d->ruleOf($f);
                    return $r['label'];
                },
            ],
            [
                'key' => 'problem', 'label' => 'What is wrong', 'group' => 'problem',
                'visible' => function () { return true; },
                'render' => function (array $f, ScanDimensions $d) {
                    $r = $d->ruleOf($f);
                    $e = MessageCatalog::explain($f, $r, 'staff');
                    $detail = MessageCatalog::detail($f, $r);
                    return $detail === '' ? $e['text'] : $e['text'] . ' ' . $detail;
                },
            ],
            [
                'key' => 'wording', 'label' => 'Wording from', 'group' => 'problem',
                'visible' => function () { return true; },
                // Which tier answered. A designer scanning this column sees at a
                // glance which rules still have no authored message.
                'render' => function (array $f, ScanDimensions $d) {
                    $e = MessageCatalog::explain($f, $d->ruleOf($f), 'staff');
                    return $e['source'];
                },
            ],
        ];

        $out = [];
        foreach ($cols as $c) {
            if (call_user_func($c['visible'], $d)) $out[] = $c;
        }
        return $out;
    }

    /**
     * One finding as a flat row of strings, keyed by column key.
     *
     * EVERY CELL LEAVES AS UTF-8. Only the Value cell was checked, by the
     * mapper; a field label, rule label or assert expression carrying one
     * Latin-1 byte reached the row as it was, json_encode() of the page's rows
     * returned false, and ScanPageView::h() rendered the cell blank. One check
     * here covers every column, including one added later. A byte that is not
     * text becomes U+FFFD, so the rest of the label survives and the reader
     * can see where a byte was lost.
     */
    public static function row(array $finding, ScanDimensions $d, ?array $cols = null)
    {
        if ($cols === null) $cols = self::all($d);
        $row = [];
        foreach ($cols as $c) {
            $s = (string) call_user_func($c['render'], $finding, $d);
            $row[$c['key']] = mb_check_encoding($s, 'UTF-8') ? $s : self::scrub($s);
        }
        return $row;
    }

    /**
     * Invalid UTF-8 sequences replaced by U+FFFD. mb_scrub() substitutes the
     * process-wide substitute character, '?' by default, which reads as a real
     * question mark; it is set for this call and put back.
     */
    private static function scrub($s)
    {
        $was = mb_substitute_character();
        mb_substitute_character(0xFFFD);
        try {
            return (string) mb_scrub($s, 'UTF-8');
        } finally {
            mb_substitute_character($was);
        }
    }

    /**
     * The CSV header row: stable machine KEYS, not labels.
     *
     * The header is a contract with whatever parses the file. Emitting labels
     * would mean any wording improvement silently breaks every downstream
     * consumer — in the one class whose stated job is to stop the screen and the
     * file drifting apart. Labels are for the screen; a `# columns:` comment
     * line carries the mapping for a human reading the raw file.
     *
     * @return string[]
     */
    public static function headers(array $cols)
    {
        $h = [];
        foreach ($cols as $c) $h[] = $c['key'];
        return $h;
    }

    /** `key=Label` pairs, for the export's comment header. */
    public static function keyLegend(array $cols)
    {
        $out = [];
        foreach ($cols as $c) $out[] = $c['key'] . '=' . $c['label'];
        return implode(', ', $out);
    }
}
