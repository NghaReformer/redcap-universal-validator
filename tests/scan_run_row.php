<?php
/**
 * A scan_run row for a stubbed database, built BY NAME.
 *
 * SqlScanStore::run() selects ScanStore::RUN_COLUMNS and refuses a row of any
 * other length. Three suites used to carry that row as a bare list of values
 * in column order, and it drifted: run_seq went into the projection and into
 * none of them. A fixture written by name cannot drift that way - a column
 * added to RUN_COLUMNS gets its default here, and a key that is not a column
 * is refused out loud.
 *
 * Not a suite: nothing here ends in _php.php, so no runner picks it up.
 */

if (!function_exists('uv_run_row')) {
    /**
     * @param array $keyed column => value, for the columns the test cares about
     * @return array the values in RUN_COLUMNS order, as a database driver hands them over
     */
    function uv_run_row(array $keyed)
    {
        $columns = \INSPIRE\UniversalValidator\Scan\ScanStore::RUN_COLUMNS;
        $unknown = array_diff(array_keys($keyed), $columns);
        if ($unknown) {
            throw new \InvalidArgumentException('uv_run_row: not a run column: '
                . implode(', ', $unknown));
        }
        $now = gmdate('Y-m-d H:i:s');
        $defaults = [
            'run_id' => '1', 'project_id' => '1', 'scope_dag' => null,
            'phase' => 'planning', 'terminal' => null, 'coverage' => 'partial',
            'detail' => 'complete', 'values_state' => 'none', 'policy_revision' => '1',
            'fingerprint' => str_repeat('0', 64), 'manifest_total' => '0',
            'manifest_done' => '0', 'cursor_ordinal' => '0', 'lease_epoch' => '0',
            'generation_id' => '1', 'run_seq' => '1', 'created_by' => 'tester',
            'detail_rows' => '0', 'detail_bytes' => '0', 'fence_open' => null,
            'fence_target' => null, 'cancel_requested_at' => null,
            'terminal_reason' => null, 'created_at' => $now, 'updated_at' => $now,
            'progress_at' => null, 'clean' => null, 'gap_count' => '0',
            'rule_problem_count' => '0', 'not_examined' => '0',
        ];
        $missing = array_diff($columns, array_keys($defaults));
        if ($missing) {
            throw new \LogicException('uv_run_row: no default for the run column(s) '
                . implode(', ', $missing) . ' - add one beside the others');
        }
        $row = [];
        foreach ($columns as $c) {
            $row[] = array_key_exists($c, $keyed) ? $keyed[$c] : $defaults[$c];
        }
        return $row;
    }
}
