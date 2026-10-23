<?php
/**
 * The server half of the message parity fixture (W7-R8).
 *
 * tests/messages_fixture.json holds, for each catalogue key, one rule in the
 * shape the page hands js/engine.js, one finding of that rule, and for each
 * audience two sentences: the one php/messages/catalog.json renders and the
 * one the form shows. tests/messages_fixture_js.cjs boots the engine on the
 * same rule and holds the form to the second; this file holds the server to
 * the first:
 *
 *   - staff: the report's "What is wrong" cell, rendered by the report's own
 *     column (ScanColumns) over a ScanDimensions built from the case's rule,
 *     so the detail sentence and the rule's own message come the way the
 *     panel and the CSV get them. And the same cell the way a STORED run
 *     renders it (review rv11b2-4): the finding written as the scan writes
 *     a row, read back through FindingRowMapper::toFinding(), over the
 *     labels' snapshot round trip (ruleFieldsOnly, toSnapshot, fromSnapshot)
 *     that the panel and the CSV of a stored run read;
 *   - survey: MessageCatalog::explain() for a respondent.
 *
 * And it holds the fixture to itself: every key of catalog.json has a case,
 * a case's key is the catalogue line that answered it, a case marked "same"
 * carries one sentence on both sides and one marked "differs" two, and a case
 * the form never shows says why.
 *
 * Run:  php tests/messages_fixture_php.php
 */

namespace {
    require_once __DIR__ . '/../php/ModeRegistry.php';
    require_once __DIR__ . '/../php/ScanCapabilities.php';
    require_once __DIR__ . '/../php/MessageCatalog.php';
    require_once __DIR__ . '/../php/ScanDimensions.php';
    require_once __DIR__ . '/../php/ScanColumns.php';
    require_once __DIR__ . '/../php/Scan/ScanPolicy.php';
    require_once __DIR__ . '/../php/Scan/ReasonCode.php';
    require_once __DIR__ . '/../php/Scan/FindingRowMapper.php';

    use INSPIRE\UniversalValidator\MessageCatalog;
    use INSPIRE\UniversalValidator\ScanColumns;
    use INSPIRE\UniversalValidator\ScanDimensions;
    use INSPIRE\UniversalValidator\Scan\FindingRowMapper;
    use INSPIRE\UniversalValidator\Scan\ReasonCode;

    $n = 0;
    $fail = 0;
    function check($label, $cond)
    {
        global $n, $fail;
        $n++;
        if (!$cond) {
            $fail++;
            fwrite(STDERR, "FAIL: $label\n");
        }
    }

    // A notice while rendering is a sentence built from a missing value.
    $notices = [];
    set_error_handler(function ($no, $str, $file, $line) use (&$notices) {
        $notices[] = $str . ' (' . basename($file) . ':' . $line . ')';
        return true;
    });

    $fixture = json_decode(file_get_contents(__DIR__ . '/messages_fixture.json'), true);
    $catalog = json_decode(file_get_contents(__DIR__ . '/../php/messages/catalog.json'), true);
    check('fixture: it parses and has cases', is_array($fixture) && !empty($fixture['cases']) && is_array($fixture['cases']));
    check('catalogue: it parses', is_array($catalog));
    $cases = is_array($fixture) && isset($fixture['cases']) && is_array($fixture['cases']) ? $fixture['cases'] : [];

    /** The report's "What is wrong" cell for one finding, through the report's own column. */
    function problemCell(array $finding, ScanDimensions $d)
    {
        $cols = array_values(array_filter(ScanColumns::all($d), function ($c) { return $c['key'] === 'problem'; }));
        $row = ScanColumns::row($finding, $d, $cols);
        return isset($row['problem']) ? $row['problem'] : null;
    }

    /**
     * The same cell for a STORED run (rv11b2-4). The labels go through the
     * snapshot as ScanService::planningLabels() writes it and runDimensions()
     * reads it back, with the store's string columns; the finding is written
     * as UniversalValidator::durableFindings() writes a row (the reason split
     * once into a code and its bits) and read back by the mapper the report
     * uses.
     */
    function storedProblemCell(array $finding, ScanDimensions $d)
    {
        $rows = [];
        foreach ($d->ruleFieldsOnly()->toSnapshot() as $r) {
            $rows[] = ['kind' => (string) $r['kind'], 'key' => (string) $r['key'],
                       'label' => $r['label'] === null ? null : (string) $r['label'],
                       'meta' => $r['meta'] === null ? null : (string) $r['meta']];
        }
        $stored = ScanDimensions::fromSnapshot($rows);
        if ($stored === null) return null;
        $split = ReasonCode::split($finding['reason'], isset($finding['type']) ? (string) $finding['type'] : null);
        $row = [
            'finding_id' => 1, 'project_id' => 149, 'record_id_bin' => 'R-1', 'event_id' => null,
            'instance' => 1, 'host_form' => isset($finding['instrument']) ? (string) $finding['instrument'] : 'fa',
            'field' => (string) $finding['field'], 'rule_source_id' => 'src-1', 'rule_ord' => (int) $finding['rule'],
            'check_type' => (string) $finding['type'], 'reason_code' => $split['code'],
            'reason_bits' => ReasonCode::bits($split, isset($finding['branch']) ? $finding['branch'] : null),
            'dag_key' => null, 'value_bin' => null, 'value_len' => null, 'value_truncated' => 0,
            'value_expires_at' => null, 'as_of' => null, 'z_score' => null, 'growth_reference' => null,
        ];
        $map = new FindingRowMapper(['pid' => 149, 'now' => '2026-10-23 00:00:00', 'recordIds' => 'raw',
                                     'valuesState' => 'none']);
        return problemCell($map->toFinding($row), $stored);
    }

    $covered = [];
    $same = 0;
    foreach ($cases as $i => $c) {
        $id = (isset($c['key']) ? $c['key'] : '#' . $i) . (isset($c['variant']) ? ' (' . $c['variant'] . ')' : '');
        if (!isset($c['key'], $c['rule'], $c['finding'], $c['staff'], $c['survey']) || !array_key_exists('form', $c)) {
            check($id . ': the case has a key, a rule, a finding, a form and both audiences', false);
            continue;
        }
        $d = ScanDimensions::build(149, [], [$c['rule']]);
        $finding = $c['finding'] + ['rule' => 1];
        $rule = $d->ruleOf($finding);
        $rendered = [
            'staff'  => problemCell($finding, $d),
            'survey' => MessageCatalog::explain($finding, $rule, 'survey')['text'],
        ];
        $storedCell = storedProblemCell($finding, $d);
        check($id . ' staff: a stored run\'s report renders the same ' . json_encode($c['staff']['catalogue'])
            . ' (got ' . json_encode($storedCell) . ')', $storedCell === $c['staff']['catalogue']);
        foreach (['staff', 'survey'] as $a) {
            $s = $c[$a];
            check($id . ' ' . $a . ': the catalogue renders ' . json_encode($s['catalogue'])
                . ' (got ' . json_encode($rendered[$a]) . ')', $rendered[$a] === $s['catalogue']);
            if ($c['form'] === null) {
                check($id . ' ' . $a . ': a case the form never shows has no engine sentence', array_key_exists('engine', $s) && $s['engine'] === null);
                continue;
            }
            $parity = isset($s['parity']) ? $s['parity'] : '';
            if ($parity === 'same') {
                $same++;
                check($id . ' ' . $a . ': marked "same", it carries one sentence on both sides', $s['engine'] === $s['catalogue']);
            } else {
                check($id . ' ' . $a . ': marked "differs", the two sentences differ',
                    $parity === 'differs' && is_string($s['engine']) && $s['engine'] !== $s['catalogue']);
            }
        }
        check($id . ': a case the form never shows says why',
            $c['form'] !== null || (isset($c['formAbsent']) && is_string($c['formAbsent']) && $c['formAbsent'] !== ''));

        // The key is the line that answered: the case without a variant uses
        // the catalogue's own words, a variant the rule's.
        $staffExplain = MessageCatalog::explain($finding, $rule, 'staff');
        if (isset($c['variant'])) {
            check($id . ': a variant is worded by its rule, not the catalogue', $staffExplain['source'] === 'rule-message');
            continue;
        }
        $covered[$c['key']] = true;
        $line = isset($catalog[$c['key']]) ? $catalog[$c['key']] : null;
        check($id . ': the key is a line of the catalogue', is_array($line));
        if (!is_array($line)) continue;
        foreach (['staff', 'survey'] as $a) {
            $text = isset($line[$a]) ? $line[$a] : '';
            $e = MessageCatalog::explain($finding, $rule, $a);
            if (strpos($text, '{') === false) {
                check($id . ' ' . $a . ': the line that answered is the key\'s', $e['text'] === $text);
            } else {
                $head = substr($text, 0, strpos($text, '{'));
                check($id . ' ' . $a . ': the line that answered is the key\'s', $head === '' || strpos($e['text'], $head) === 0);
            }
        }
    }

    // Every line of the catalogue has a case, so a line added there without
    // a case here fails.
    foreach ($catalog as $key => $line) {
        if ($key === '_comment' || !is_array($line)) continue;
        check('coverage: catalogue line ' . $key . ' has a case', isset($covered[$key]));
    }
    check('coverage (control): a key the catalogue does not have is not counted', !isset($covered['nothing/here']));
    check('fixture: some sentences are the same on both sides', $same > 0);
    check('rendering raised no notice' . ($notices ? ': ' . implode('; ', array_slice($notices, 0, 3)) : ''), !$notices);

    restore_error_handler();
    echo "$n checks, $fail failed ($same sentences the same on both sides)\n";
    exit($fail === 0 ? 0 : 1);
}
