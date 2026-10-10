<?php
/**
 * temporal_sec: protected-source existence/shape oracle.
 *
 * User "nurse" has NO ACCESS (level 0) to instrument fb; the rule lives on fa.
 * TemporalRules::compile() only marks a rule "denied" inside $member(), i.e.
 * once per resolved member. Consequences probed here:
 *   A. an aggregate over a protected instrument with ZERO members never calls
 *      $member(), so a comparison of a LIVE operand against it ships to the
 *      page (not deferred); with >=1 member it is deferred as "unauthorized".
 *   B. the deferral reason differs by the protected data's shape:
 *      absent (0 matches) / unauthorized (1 match) / ambiguous (>=2 matches),
 *      and the data-entry engine renders that reason to the user.
 * Both are observable by a user with no right to fb (data entry) and in the
 * survey payload.
 */
require __DIR__ . '/temporal_sec_harness.php';

function show($label, $p) {
    $r = ruleOf($p, 'a_val');
    printf("%-58s deferred=%-5s why=%-45s assertAst=%s\n", $label,
        json_encode(!empty($r['deferred'])), json_encode($r['deferredWhy'] ?? null),
        substr(json_encode($r['assertAst'] ?? null), 0, 90));
}

// ---- A. aggregate count over protected fb, compared with the live [a_val]
$refs = ['n' => ['field' => 'b_open', 'event' => 'baseline_arm_1', 'aggregate' => 'count']];
$m = temporal('[a_val]>{n}', $refs);
REDCap::$rights['nurse']['forms']['fb'] = '0';          // no access to fb
show('A1 data entry, fb has 2 rows', render($m, 'fa'));
show('A1 survey,     fb has 2 rows', survey_render($m, 'fa'));
$m = temporal('[a_val]>{n}', $refs);
REDCap::$rights['nurse']['forms']['fb'] = '0';
unset(REDCap::$data[1]['repeat_instances'][1]['fb']);  // fb empty for this record
show('A2 data entry, fb has 0 rows', render($m, 'fa'));
show('A2 survey,     fb has 0 rows', survey_render($m, 'fa'));

// ---- B. scalar matched lookup keyed by the user's own [key_a]
$refs = ['m' => ['field' => 'b_open', 'event' => 'baseline_arm_1', 'match' => ['key_b' => '[key_a]']]];
foreach ([['Z', 'no fb row has key_b=Z'], ['A', 'one fb row has key_b=A'], ['AA', 'two fb rows have key_b=AA']] as $case) {
    $m = temporal('[a_val]<{m}', $refs);
    REDCap::$rights['nurse']['forms']['fb'] = '0';
    REDCap::$data[1]['repeat_instances'][1]['fb'][6] = ['b_open' => '1', 'key_b' => 'AA', 'fb_complete' => '2'];
    REDCap::$data[1]['repeat_instances'][1]['fb'][8] = ['b_open' => '2', 'key_b' => 'AA', 'fb_complete' => '2'];
    REDCap::$data[1]['repeat_instances'][1]['fa'][1]['key_a'] = $case[0];   // user saved this key on fa
    show('B key_a=' . $case[0] . ' (' . $case[1] . ')', render($m, 'fa'));
}

// ---- B2. same with aggregate count + match: 0 matches ships live, >=1 defers
$refs = ['c' => ['field' => 'b_open', 'event' => 'baseline_arm_1', 'aggregate' => 'count', 'match' => ['key_b' => '[key_a]']]];
foreach (['Z', 'A'] as $k) {
    $m = temporal('[a_val]>={c}', $refs);
    REDCap::$rights['nurse']['forms']['fb'] = '0';
    REDCap::$data[1]['repeat_instances'][1]['fa'][1]['key_a'] = $k;
    show('B2 count-match key_a=' . $k, render($m, 'fa'));
}
