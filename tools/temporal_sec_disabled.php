<?php
/** temporal_sec: with enable-event-instance-refs OFF, extended rules must read nothing and evaluate nothing. */
require __DIR__ . '/temporal_sec_harness.php';

$shapes = [
    ['[a_val]<[baseline_arm_1][b_open][2]', null, 'UVASSERT'],
    ['[a_val]<{m}', ['m' => ['field' => 'b_open', 'event' => 'baseline_arm_1', 'match' => ['key_b' => '[key_a]']]], 'UVASSERT'],
    ['', null, 'UVUNIQUE'],
];
foreach ($shapes as $s) {
    $m = temporal($s[0], $s[1], $s[2]);
    $m->projectSettings['enable-event-instance-refs'] = false;
    REDCap::$getDataCalls = 0;
    $p = render($m, 'fa'); $ps = survey_render($m, 'fa');
    $m->redcap_save_record(PID, '1', 'fb', 1, null);
    $u = $m->redcap_module_ajax('unique-check', ['field' => 'a_val', 'values' => ['a_val' => '12']], PID, '1', 'fa', 1, 1, null, null, null, null, null, 'nurse', null);
    $r = ruleOf($p, 'a_val');
    printf("%-40s getData=%d configError=%s leaks11=%s ajax=%s logs=%d\n", $s[2] . ' ' . $s[0], REDCap::$getDataCalls,
        json_encode(!empty($r['configError'])), json_encode(strpos($p['raw'] . $ps['raw'], '"11"') !== false),
        json_encode($u), count($m->logCalls));
}
