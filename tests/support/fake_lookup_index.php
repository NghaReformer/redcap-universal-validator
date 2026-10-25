<?php
/**
 * tests/support/fake_lookup_index.php - Scan\LookupIndex for the mock suites.
 *
 * The real index needs MySQL (tests/mysql/cases/lookup_index.php). The mock
 * suites run their lookups a second time with this one in its place, to show
 * that the module answers the same through the index as through the read of
 * the whole field. It names every record whose row of a searched field has
 * the value's key, as the real index does, plus one record that holds no such
 * value: the index is a superset, and the module must drop that record when
 * it confirms the candidates against the data table.
 *
 * Both suites' REDCap stand-ins offer tableRows($sql, $params) for a read of
 * one field, which is what this reads, and uv_among_rows() answers the
 * module's read of the named records (FieldValues::recordsAmong) from it.
 */

final class FakeLookupIndex
{
    /** @var array each [pid, fieldMarks, value] asked */
    public $asked = [];

    public function candidates($pid, array $fieldMarks, $value)
    {
        $this->asked[] = [(int) $pid, $fieldMarks, (string) $value];
        $hit = [];
        $all = [];
        foreach ($fieldMarks as $f => $mark) {
            $key = \INSPIRE\UniversalValidator\Logic::lookupKey((string) $value, true, $mark);
            $rows = \REDCap::tableRows('SELECT record, field_name, `value` FROM redcap_data WHERE project_id = ? AND field_name = ?',
                [(int) $pid, (string) $f]);
            foreach ($rows as $r) {
                $all[(string) $r[0]] = true;
                if (\INSPIRE\UniversalValidator\Logic::lookupKey((string) $r[2], true, $mark) === $key) $hit[(string) $r[0]] = true;
            }
        }
        foreach ($all as $rec => $_) {
            if (!isset($hit[$rec])) { $hit[$rec] = true; break; }
        }
        return array_map('strval', array_keys($hit));
    }
}

/** Put a FakeLookupIndex in $m in place of the real one; returns it. */
function uv_use_fake_index($m)
{
    $fake = new FakeLookupIndex();
    (function () use ($fake) { $this->lookupIndexer = $fake; })->call($m);
    return $fake;
}

/**
 * The rows FieldValues::recordsAmong reads: the named records' rows of the
 * named fields (of one event when the statement names one), from tableRows.
 */
function uv_among_rows($sql, array $params)
{
    if (!preg_match('/record IN \(([?, ]*)\) AND field_name IN \(([?, ]*)\)/', $sql, $m)) return [];
    $nr = substr_count($m[1], '?');
    $nf = substr_count($m[2], '?');
    $pid = $params[0];
    $want = array_flip(array_map('strval', array_slice($params, 1, $nr)));
    $fields = array_slice($params, 1 + $nr, $nf);
    $event = strpos($sql, 'AND event_id = ?') !== false ? $params[count($params) - 1] : null;
    $out = [];
    foreach ($fields as $f) {
        $q = 'SELECT record, field_name, `value` FROM redcap_data WHERE project_id = ? AND field_name = ?';
        $p = [$pid, $f];
        if ($event !== null) {
            $q .= ' AND event_id = ?';
            $p[] = $event;
        }
        foreach (\REDCap::tableRows($q, $p) as $r) {
            if (isset($want[(string) $r[0]])) $out[] = [(string) $r[0], (string) $f, (string) $r[2]];
        }
    }
    return $out;
}
