<?php
/**
 * temporal_sec: what an extended rule puts in a SURVEY page payload.
 * Prints the raw inspire-validator-config JSON for several rule shapes so the
 * reviewer can see exactly which saved values / derived facts reach a public page.
 */
require __DIR__ . '/temporal_sec_harness.php';

function dump($label, $p) {
    echo str_pad($label, 44) . ' ' . substr($p['raw'], 0, 400) . "\n";
    $leak = [];
    foreach (['"11"', '"9"', '"12"', '"8"'] as $v) if (strpos($p['raw'], '["lit",' . $v) !== false || strpos($p['raw'], '["lit",' . $v) !== false) $leak[] = $v;
    echo str_pad('', 44) . ' protected literals present: ' . ($leak ? implode(',', $leak) : 'none') . "\n";
}

// 1. record-local uniqueness on a survey: other entries' tuples must not ship
$m = temporal('', null, 'UVUNIQUE');
dump('record uniqueness (survey)', survey_render($m, 'fa'));

// 2. protected-only comparison: server Boolean ships (documented)
$m = temporal('[baseline_arm_1][b_open][2]>10');
dump('protected-only Boolean (survey)', survey_render($m, 'fa'));

// 3. aggregate over the survey instrument's OTHER instances, excludeCurrent, only self saved
$m = temporal('[a_val]>={n}', ['n' => ['field' => 'a_val', 'aggregate' => 'count', 'excludeCurrent' => true]]);
unset(REDCap::$data[1]['repeat_instances'][1]['fa'][3], REDCap::$data[1]['repeat_instances'][2]);
dump('count(other instances)=0 (survey)', survey_render($m, 'fa'));
$m = temporal('[a_val]>={n}', ['n' => ['field' => 'a_val', 'aggregate' => 'count', 'excludeCurrent' => true]]);
dump('count(other instances)>0 (survey)', survey_render($m, 'fa'));

// 4. a match keyed by a saved current-instrument field: the saved key ships as a guard literal
$m = temporal('[a_val]>={c}', ['c' => ['field' => 'b_open', 'event' => 'baseline_arm_1', 'aggregate' => 'count', 'match' => ['key_b' => '[key_a]']]]);
REDCap::$data[1]['repeat_instances'][1]['fa'][1]['key_a'] = 'SECRET-KEY-42';
$p = survey_render($m, 'fa');
dump('guard literal of saved key (survey)', $p);
echo str_pad('', 44) . ' assertAst=' . json_encode(ruleOf($p, 'a_val')['assertAst'] ?? null) . "\n";
