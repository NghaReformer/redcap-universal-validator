<?php
/** Shared helpers for the temporal_sem_* repro scripts (red-team, not production). */
require_once __DIR__ . '/../php/AddressResolver.php';
require_once __DIR__ . '/../php/TemporalRules.php';
require_once __DIR__ . '/../php/TemporalLogic.php';
use INSPIRE\UniversalValidator\AddressResolver;
use INSPIRE\UniversalValidator\ProjectShape;
use INSPIRE\UniversalValidator\TemporalRules;
use INSPIRE\UniversalValidator\TemporalLogic;
use INSPIRE\UniversalValidator\ReferenceBudget;

/** Server (audit) verdict of one rule key: true/false, 'inert' (when false) or 'deferred:<why>'. */
function sem_server(ProjectShape $shape, array $record, array $rule, array $ctx, $key = 'assert') {
    $res = new AddressResolver($shape, $record, 10000, new ReferenceBudget());
    $res->shareAcrossContexts();
    $ctx += ['unsaved' => false, 'values' => []];
    $c = TemporalRules::compile($rule, $res, $shape, $ctx, false);
    if ($c['problems']) return 'deferred:' . implode(',', $c['problems']);
    if (isset($rule['when']) && $key === 'assert' && ($c['rule']['when'] ?? null) === '1=0') return 'inert';
    return ($c['rule'][$key] ?? null) === '1=1';
}
/** Browser compile: the AST the page receives, or 'deferred:<why>'. */
function sem_browser(ProjectShape $shape, array $record, array $rule, array $ctx, $key = 'assert') {
    $res = new AddressResolver($shape, $record, 10000, new ReferenceBudget());
    $ctx += ['unsaved' => true, 'values' => []];
    $c = TemporalRules::compile($rule, $res, $shape, $ctx, true, function () { return true; });
    if ($c['problems']) return 'deferred:' . implode(',', $c['problems']);
    return $c['rule'][$key . 'Ast'] ?? null;
}
/** PHP evaluation of a browser AST against live page values (null = unknown). */
function sem_php_eval(array $ast, array $live, $blank, $cs = false) {
    return TemporalLogic::evaluate($ast, function ($f, $c) use ($live) {
        $v = $live[$f] ?? '';
        return $c === null ? (is_array($v) ? '' : $v) : (is_array($v) && (($v[$c] ?? '0') === '1') ? '1' : '0');
    }, $blank, $cs);
}
/** JS evaluation of the same AST through engine.js (null = unknown). */
function sem_js_eval(array $ast, array $live, $blank, $cs = false) {
    $payload = json_encode(['ast' => $ast, 'values' => (object)$live, 'blank' => $blank, 'cs' => $cs]);
    $file = tempnam(sys_get_temp_dir(), 'sem');
    file_put_contents($file, $payload);
    $out = shell_exec('node ' . escapeshellarg(__DIR__ . '/temporal_sem_eval.cjs') . ' ' . escapeshellarg($file));
    unlink($file);
    return json_decode(trim((string)$out), true);
}
function sem_show($v) { return is_string($v) ? $v : json_encode($v); }
$GLOBALS['sem_fail'] = 0; $GLOBALS['sem_n'] = 0;
function sem_expect($label, $got, $want) {
    $GLOBALS['sem_n']++;
    $ok = $got === $want;
    if (!$ok) $GLOBALS['sem_fail']++;
    echo ($ok ? 'ok      ' : 'DIVERGE ') . "$label  got=" . sem_show($got) . ' want=' . sem_show($want) . "\n";
}
function sem_done($name) { echo "$name: {$GLOBALS['sem_n']} checks, {$GLOBALS['sem_fail']} divergences\n"; }
