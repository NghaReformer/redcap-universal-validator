<?php
/**
 * lock_return_targets(): the data dictionary $dd with every field that a
 * "return" (@UVEXISTS, @UVRANGE) writes to marked @READONLY, as the module
 * requires (EXS-13) and as a designer sets such a field up. The suites build
 * their dictionaries without action tags and lock them here. A check of that
 * refusal leaves its field unlocked.
 */
function lock_return_targets(array $dd)
{
    $targets = [];
    foreach ($dd as $meta) {
        $note = is_array($meta) && isset($meta['field_annotation']) ? (string) $meta['field_annotation'] : '';
        if (!preg_match_all('/"return"\s*:\s*\{([^}]*)\}/', $note, $mm)) continue;
        foreach ($mm[1] as $map) {
            if (preg_match_all('/\[([A-Za-z0-9_]+)\]/', $map, $tt)) {
                foreach ($tt[1] as $t) $targets[$t] = true;
            }
        }
    }
    foreach (array_keys($targets) as $t) {
        if (!isset($dd[$t]) || !is_array($dd[$t])) continue;
        $note = isset($dd[$t]['field_annotation']) ? (string) $dd[$t]['field_annotation'] : '';
        if (preg_match('/@(?:READONLY|HIDDEN)(?![-\w])/i', $note)) continue;
        $dd[$t]['field_annotation'] = trim($note . ' @READONLY');
    }
    return $dd;
}
