<?php
/**
 * parity_php.php — proves the PHP port matches the Python-generated fixture.
 *
 * Recomputes every row of check_fixture.json across all three sections, not just
 * `compute`, so the server engine is verified over the SAME runtime path the
 * module actually uses:
 *   - compute    : the raw check-character primitive (per algorithm)
 *   - normalize  : Unicode dash folding / case / strip / keep_only (mb-safe)
 *   - scheme_ops : append + validate (normalize -> source -> compute -> compare)
 * If the PHP port ever drifts from the canonical Python engine, CI turns red here.
 *
 * Run:  php tests/parity_php.php
 */

require_once __DIR__ . '/../php/CheckCharacter.php';

use INSPIRE\UniversalValidator\CheckCharacter;

$fx = json_decode(file_get_contents(__DIR__ . '/check_fixture.json'), true);
if (!$fx || !isset($fx['compute'])) {
    fwrite(STDERR, "could not read compute rows from check_fixture.json\n");
    exit(2);
}

$total = 0;
$fail = 0;

// ---- 1) compute: raw check-character primitive ----
$byAlgo = [];
foreach ($fx['compute'] as $r) {
    $total++;
    $name = $r['name'];
    $byAlgo[$name] = ($byAlgo[$name] ?? 0) + 1;
    try { $got = CheckCharacter::compute($name, $r['payload']); }
    catch (\Throwable $e) { $got = '<<error: ' . $e->getMessage() . '>>'; }
    if ($got !== $r['check']) {
        $fail++;
        fwrite(STDERR, sprintf("compute   MISMATCH %-18s payload=%-14s expected=%s got=%s\n",
            $name, $r['payload'], var_export($r['check'], true), var_export($got, true)));
    }
}
echo "compute algorithms covered: " . implode(', ', array_keys($byAlgo)) . "\n";

// ---- 2) normalize: dash folding / case / strip / keep_only ----
foreach (($fx['normalize'] ?? []) as $r) {
    $total++;
    $rules = $r['rules'];
    try {
        $got = CheckCharacter::normalize(
            $r['value'],
            $rules['strip_delimiters'],
            $rules['uppercase'],
            $rules['unify_unicode_dashes'],
            $rules['keep_only']
        );
    } catch (\Throwable $e) { $got = '<<error: ' . $e->getMessage() . '>>'; }
    if ($got !== $r['result']) {
        $fail++;
        fwrite(STDERR, sprintf("normalize MISMATCH value=%s expected=%s got=%s\n",
            var_export($r['value'], true), var_export($r['result'], true), var_export($got, true)));
    }
}

// ---- 3) scheme_ops: append + validate over the full runtime path ----
/** Resolve a scheme row to [algorithm, source, strip, enabled, placement, delimiter]. */
function schemeParams(array $r)
{
    if (isset($r['config'])) {
        $c = $r['config'];
        return [
            $c['algorithm'], $c['source'], $c['normalize_rules']['strip_delimiters'], !empty($c['enabled']),
            $c['placement'] ?? 'append', $c['delimiter'] ?? '-',
        ];
    }
    $name = $r['scheme'] ?? null;
    if ($name === 'inspire_default') return ['iso7064_mod37_36', 'normalized_id', '-/ ', true, 'append', '-'];
    return ['none', 'normalized_id', '-/ ', false, 'append', '-']; // 'none' / unknown -> inactive
}
foreach (($fx['scheme_ops'] ?? []) as $r) {
    $total++;
    list($algo, $source, $strip, $enabled, $placement, $delimiter) = schemeParams($r);
    $active = ($enabled && $algo !== 'none');
    try {
        if ($r['op'] === 'append') {
            $got = $active ? CheckCharacter::appendCheck($algo, $source, $strip, $r['id'], $placement, $delimiter) : (string) $r['id'];
        } elseif ($r['op'] === 'validate') {
            $got = $active ? CheckCharacter::validateId($algo, $source, $strip, $r['id']) : true;
        } else {
            $got = '<<unknown op: ' . $r['op'] . '>>';
        }
    } catch (\Throwable $e) { $got = '<<error: ' . $e->getMessage() . '>>'; }
    if ($got !== $r['result']) {
        $fail++;
        fwrite(STDERR, sprintf("scheme_op MISMATCH %-8s id=%s expected=%s got=%s\n",
            $r['op'], var_export($r['id'], true), var_export($r['result'], true), var_export($got, true)));
    }
}

echo sprintf("parity_php: %d rows checked (compute + normalize + scheme_ops), %d mismatch(es)\n", $total, $fail);
exit($fail === 0 ? 0 : 1);
