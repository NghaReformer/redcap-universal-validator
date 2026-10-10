<?php
/**
 * temporal_sec: unique-check AJAX with extended branch selectors.
 * The branch picked depends on a protected value ([baseline_arm_1][b_open][2]>10).
 * Branch 1 = project scope, else-branch absent: when branch 1 is inactive the
 * endpoint answers "not a checkable field"; when active it answers used/free.
 * The response therefore encodes the protected Boolean for anyone allowed to call it.
 */
require __DIR__ . '/temporal_sec_harness.php';

function setup($bOpen) {
    $m = temporal('', null, 'UVASSERT');
    REDCap::$dictionary['a_val']['field_annotation'] =
        '@UVUNIQUE={"when":"[baseline_arm_1][b_open][2]>10","surveys":true}' . "\n" .
        '@UVUNIQUE={"when":"[baseline_arm_1][b_open][2]<=10","scope":"event"}';
    REDCap::$data[1]['repeat_instances'][1]['fb'][2]['b_open'] = $bOpen;
    REDCap::$data[2] = [1 => ['record_id' => '2', 'a_val' => 'DUP']];   // another record holding "DUP" in event 1
    return $m;
}
$calls = [
    'no auth, no hash'        => [null, null],
    'survey hash, no user'    => ['hash', null],
    'staff'                   => [null, 'nurse'],
    'staff + survey hash'     => ['hash', 'nurse'],
];
foreach (['11', '5'] as $b) foreach ($calls as $label => $c) foreach (['1' => '1'] as $_) {
    $m = setup($b);
    $r = $m->redcap_module_ajax('unique-check', ['field' => 'a_val', 'values' => ['a_val' => 'DUP']], PID, '1', 'fa', 1, 1, $c[0], null, null, null, null, $c[1], null);
    printf("b_open=%-3s %-22s -> %s\n", $b, $label, json_encode($r));
}
// staff WITHOUT rights to fb
$m = setup('11'); REDCap::$rights['nurse']['forms']['fb'] = '0';
$r = $m->redcap_module_ajax('unique-check', ['field' => 'a_val', 'values' => ['a_val' => 'DUP']], PID, '1', 'fa', 1, 1, null, null, null, null, null, 'nurse', null);
echo "b_open=11  staff, fb no access       -> " . json_encode($r) . "\n";
