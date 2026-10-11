<?php
/**
 * convert_php.php — data/references/convert.php, the LMS table converter.
 *
 * Runs the script on small tables written to a temporary folder and checks:
 *   - a WHO-shaped table (tab-separated, a byte-order mark, upper-case column
 *     names) and a CDC-shaped one (half months, with the extra whole-month rows
 *     at both ends that the README's command skips) convert, and
 *     GrowthReference::table() accepts what was written,
 *   - the printed SHA-256 is that of the file, and the printed index entry
 *     names the file and the x range both sexes have ("floor" up to the next
 *     key for an offset table) and is accepted by GrowthReference::table(),
 *   - other sex columns and codes, and a header row repeated before the
 *     female rows,
 *   - every refusal: a row off the grid, a gap, sexes starting apart, a sex
 *     code that is neither, M at or below 0, a second row for one x, a missing
 *     column, missing options,
 *   - --check: a z-score from a shipped reference and from a folder of extra
 *     ones, and why there is none (an unknown id, x outside the reference, a
 *     bad --sex, --y or table),
 *   - data/references/examples: every conversion and check the action tag
 *     guide prints is printed there verbatim, rebuilds the committed table
 *     byte for byte, and prints what the guide shows.
 *
 * Run:  php tests/convert_php.php
 */
namespace {
    require_once __DIR__ . '/../php/GrowthReference.php';
    use INSPIRE\UniversalValidator\GrowthReference as G;

    $n = 0; $fail = 0;
    function check($label, $cond) {
        global $n, $fail; $n++;
        if (!$cond) { $fail++; fwrite(STDERR, "FAIL: $label\n"); }
    }
    $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'uv_convert_' . bin2hex(random_bytes(6));
    mkdir($dir);
    $script = __DIR__ . '/../data/references/convert.php';
    function run($args) {
        global $script;
        // a list, not a command line: no shell, so no quoting rules (PHP 7.4+)
        $p = proc_open(array_merge([PHP_BINARY, $script], $args), [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $out = stream_get_contents($pipes[1]);
        $err = stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]);
        return ['code' => proc_close($p), 'out' => $out, 'err' => $err];
    }
    function put($name, $text) {
        global $dir;
        file_put_contents($dir . DIRECTORY_SEPARATOR . $name, $text);
        return $dir . DIRECTORY_SEPARATOR . $name;
    }

    // ---- a WHO-shaped table: tab-separated, a BOM, upper-case names, rows per day
    $who = "\xEF\xBB\xBF" . "SEX\tAGE\tL\tM\tS\n"
         . "1\t0\t0.3487\t3.3464\t0.14602\n1\t1\t0.3127\t3.3174\t0.14194\n1\t2\t0.3029\t3.3204\t0.13843\n"
         . "2\t0\t0.3809\t3.2322\t0.14171\n2\t1\t0.3259\t3.1957\t0.13892\n2\t2\t0.3101\t3.2002\t0.13654\n";
    $in = put('who.txt', $who);
    $out = $dir . DIRECTORY_SEPARATOR . 'who.json';
    $r = run(['--in', $in, '--out', $out, '--x', 'age']);
    check('WHO shape: converts (got ' . json_encode($r) . ')', $r['code'] === 0 && is_file($out));
    $raw = (string) @file_get_contents($out);
    $t = json_decode($raw, true);
    check('WHO shape: the table', is_array($t) && $t['format'] === 'uv-lms-1' && $t['scale'] === 1 && $t['first'] === 0
        && $t['male'][0] === [0.3487, 3.3464, 0.14602] && $t['female'][2] === [0.3101, 3.2002, 0.13654] && count($t['male']) === 3);
    check('WHO shape: the printed SHA-256 is the file\'s', strpos($r['out'], 'sha256 ' . hash('sha256', $raw)) !== false);
    check('WHO shape: the printed entry names the file and the x range', strpos($r['out'], '"file": "who.json"') !== false
        && strpos($r['out'], '"min": "0"') !== false && strpos($r['out'], '"max": "2"') !== false);
    $entry = ['id' => 'w', 'dir' => $dir, 'file' => 'who.json', 'sha256' => hash('sha256', $raw), 'axis' => 'age', 'axisUnit' => 'days',
              'lookup' => 'round', 'valid' => ['min' => '0', 'below' => '2.5'], 'adjust' => 'who-restricted'];
    try { $ok = G::table($entry)['male'][1] === [0.3127, 3.3174, 0.14194]; } catch (\RuntimeException $e) { $ok = false; }
    check('WHO shape: GrowthReference::table() accepts it', $ok);

    // ---- a CDC-shaped table: half months, plus whole-month rows at 24 and at the end
    $cdc = "Sex,Agemos,L,M,S\n"
         . "1,24,-0.216,12.741,0.108\n1,24.5,-0.216,12.741,0.108\n1,25.5,-0.239,12.885,0.108\n1,26.5,-0.262,13.027,0.108\n1,27,-0.27,13.1,0.108\n"
         . "2,24,-0.73,12.13,0.108\n2,24.5,-0.73,12.13,0.108\n2,25.5,-0.75,12.27,0.109\n2,26.5,-0.76,12.41,0.109\n2,27,-0.77,12.5,0.109\n";
    $in = put('cdc.csv', $cdc);
    $out = $dir . DIRECTORY_SEPARATOR . 'cdc.json';
    $r = run(['--in', $in, '--out', $out, '--x', 'Agemos', '--x-offset', '0.5', '--min-x', '24.5']);
    check('CDC shape without --max-x: the whole-month row at the end is refused (got ' . json_encode($r['err']) . ')',
        $r['code'] === 1 && strpos($r['err'], 'x 27 is not on the grid of --scale 1 and --x-offset 0.5') !== false);
    $r = run(['--in', $in, '--out', $out, '--x', 'Agemos', '--x-offset', '0.5', '--min-x', '24.5', '--max-x', '26.5']);
    $t = json_decode((string) @file_get_contents($out), true);
    check('CDC shape with --min-x and --max-x: converts, half month 24.5 is row 24 (got ' . json_encode($r) . ')', $r['code'] === 0
        && $t['first'] === 24 && count($t['male']) === 3 && $t['male'][1] === [-0.239, 12.885, 0.108]);
    $raw = (string) file_get_contents($out);
    $entry = ['id' => 'c', 'dir' => $dir, 'file' => 'cdc.json', 'sha256' => hash('sha256', $raw), 'axis' => 'age', 'axisUnit' => 'months',
              'lookup' => 'floor', 'valid' => ['min' => '24', 'below' => '27'], 'adjust' => 'none'];
    try { $ok = count(G::table($entry)['female']) === 3; } catch (\RuntimeException $e) { $ok = $e->getMessage(); }
    check('CDC shape: GrowthReference::table() accepts it with floor and valid 24 to under 27 (got ' . json_encode($ok) . ')', $ok === true);
    // The printed starting entry for an offset table reads each row from its key
    // up to the next one, and the table accepts it as printed.
    $printed = json_decode(substr($r['out'], (int) strpos($r['out'], "{\n")), true);
    check('CDC shape: the printed entry is floor, valid 24 to under 27 (got ' . json_encode($printed) . ')', is_array($printed)
        && $printed['lookup'] === 'floor' && $printed['valid'] === ['min' => '24', 'below' => '27']);
    try { $ok = count(G::table(['id' => 'c', 'dir' => $dir] + $printed)['male']) === 3; } catch (\Throwable $e) { $ok = $e->getMessage(); }
    check('CDC shape: GrowthReference::table() accepts the printed entry (got ' . json_encode($ok) . ')', $ok === true);

    // ---- sexes ending apart: the printed entry stops where both have rows
    $in = put('apart.csv', "sex,age,l,m,s\n1,0,1,2,0.1\n1,1,1,2,0.1\n1,2,1,2,0.1\n2,0,1,2,0.1\n2,1,1,2,0.1\n");
    $r = run(['--in', $in, '--out', $dir . '/apart.json', '--x', 'age']);
    $printed = json_decode(substr($r['out'], (int) strpos($r['out'], "{\n")), true);
    try { $ok = is_array($printed) && $printed['valid'] === ['min' => '0', 'max' => '1'] && $printed['lookup'] === 'round'
        && count(G::table(['id' => 'a', 'dir' => $dir] + $printed)['female']) === 2; } catch (\Throwable $e) { $ok = $e->getMessage(); }
    check('sexes ending apart: the printed entry covers rows 0 to 1 and is accepted (got ' . json_encode($ok) . ')', $ok === true);

    // ---- offset tables whose plain bound fails: 51.6 as a double, times 10, is
    // 516 itself, so "below" 51.6 would need row 516 of a table ending at 515
    $grid = function ($keys, $scale, $offset) {
        $csv = "sex,x,l,m,s\n";
        foreach ([1, 2] as $sex) {
            foreach ($keys as $k) $csv .= $sex . ',' . sprintf('%.9F', $k / $scale + $offset) . ",1,10,0.1\n";
        }
        return $csv;
    };
    foreach ([['ends at 51.55, scale 10, offset 0.05', range(450, 515), 10, 0.05],
              ['ends at 51.65, scale 10, offset 0.05', range(450, 516), 10, 0.05],
              ['scale 3, offset 0.5', range(1, 40), 3, 0.5],
              ['scale 7, no offset', range(1, 40), 7, 0]] as $c) {
        $in = put('grid.csv', $grid($c[1], $c[2], $c[3]));
        $args = ['--in', $in, '--out', $dir . '/grid.json', '--x', 'x', '--scale', (string) $c[2]];
        if ($c[3] != 0) $args = array_merge($args, ['--x-offset', (string) $c[3]]);
        $r = run($args);
        $printed = json_decode(substr($r['out'], (int) strpos($r['out'], "{\n")), true);
        try { $ok = is_array($printed) && $r['err'] === '' && count(G::table(['id' => 'g', 'dir' => $dir] + $printed)['male']) === count($c[1]); }
        catch (\Throwable $e) { $ok = $e->getMessage(); }
        check('printed entry accepted, ' . $c[0] . ' (got ' . json_encode([$ok, $printed['valid'] ?? null, $r['err']]) . ')', $ok === true);
    }
    // the bound moved inward is still past the last row's own key
    $in = put('grid.csv', $grid(range(450, 515), 10, 0.05));
    $r = run(['--in', $in, '--out', $dir . '/grid.json', '--x', 'x', '--scale', '10', '--x-offset', '0.05']);
    $printed = json_decode(substr($r['out'], (int) strpos($r['out'], "{\n")), true);
    check('51.55 end: "below" moves in by a millionth, to 51.599999 (got ' . json_encode($printed['valid'] ?? null) . ')',
        ($printed['valid'] ?? null) === ['min' => '45', 'below' => '51.599999']);

    // ---- other sex codes
    $in = put('mf.csv', "gender,len,lam,mu,sig\nM,45,1,2.4,0.09\nM,45.1,1,2.42,0.09\nF,45,1,2.3,0.09\nF,45.1,1,2.32,0.09\n");
    $r = run(['--in', $in, '--out', $dir . '/mf.json', '--x', 'len', '--scale', '10', '--sex', 'gender', '--male', 'M',
              '--female', 'F', '--l', 'lam', '--m', 'mu', '--s', 'sig']);
    $t = json_decode((string) @file_get_contents($dir . '/mf.json'), true);
    check('other column names and sex codes, rows per mm (got ' . json_encode($r['err']) . ')', $r['code'] === 0
        && $t['scale'] === 10 && $t['first'] === 450 && $t['female'][1] === [1, 2.32, 0.09]);

    // ---- the header repeated before the female rows, as CDC's files have it
    $in = put('rep.csv', "Sex,Agemos,L,M,S\n1,0,1,2,0.1\n1,1,1,2.1,0.1\nSex,Agemos,L,M,S\n2,0,1,1.9,0.1\n2,1,1,2,0.1\n");
    $r = run(['--in', $in, '--out', $dir . '/rep.json', '--x', 'agemos']);
    $t = json_decode((string) @file_get_contents($dir . '/rep.json'), true);
    check('a repeated header row is skipped (got ' . json_encode($r['err']) . ')', $r['code'] === 0
        && $t['female'] === [[1, 1.9, 0.1], [1, 2, 0.1]] && count($t['male']) === 2);
    check('the printed hint names axisUnit', strpos($r['out'], 'edit title, measure, unit, axis, axisUnit, lookup, valid, adjust') !== false);

    // ---- refusals
    $bad = [
        'a row off the grid'      => ["sex,age,l,m,s\n1,0,1,2,0.1\n1,0.5,1,2,0.1\n2,0,1,2,0.1\n", ['--x', 'age'], 'x 0.5 is not on the grid'],
        'a gap'                   => ["sex,age,l,m,s\n1,0,1,2,0.1\n1,2,1,2,0.1\n2,0,1,2,0.1\n2,1,1,2,0.1\n", ['--x', 'age'], 'the male rows have a gap'],
        'sexes starting apart'    => ["sex,age,l,m,s\n1,0,1,2,0.1\n2,1,1,2,0.1\n", ['--x', 'age'], 'male rows start at key 0, female rows at 1'],
        'a sex code that is neither' => ["sex,age,l,m,s\n1,0,1,2,0.1\n3,0,1,2,0.1\n", ['--x', 'age'], 'line 3: sex "3" is neither --male nor --female.'],
        'M of 0'                  => ["sex,age,l,m,s\n1,0,1,0,0.1\n", ['--x', 'age'], 'line 2: M and S must be above 0.'],
        'a second row for one x'  => ["sex,age,l,m,s\n1,0,1,2,0.1\n1,0,1,2,0.1\n", ['--x', 'age'], 'line 3: a second male row for x 0.'],
        'L not a number'          => ["sex,age,l,m,s\n1,0,x,2,0.1\n", ['--x', 'age'], 'line 2: L "x" is not a number.'],
        'S below 0.0001'          => ["sex,age,l,m,s\n1,0,1,2,0.00001\n", ['--x', 'age'], 'line 2: S must be at least 0.0001.'],
        'L of rounding noise'     => ["sex,age,l,m,s\n1,0,2.22e-16,2,0.1\n", ['--x', 'age'], 'line 2: L is not 0 but smaller than 0.000001 in size; write an L of 0 as 0.'],
        'no x column'             => ["sex,age,l,m,s\n1,0,1,2,0.1\n", ['--x', 'months'], 'no column "months" (the columns are: sex, age, l, m, s).'],
        'no female rows'          => ["sex,age,l,m,s\n1,0,1,2,0.1\n", ['--x', 'age'], 'no female rows.'],
        'the same code twice'     => ["sex,age,l,m,s\n1,0,1,2,0.1\n", ['--x', 'age', '--male', '1', '--female', '1'], '--male and --female must differ.'],
        'a scale of 2.5'          => ["sex,age,l,m,s\n1,0,1,2,0.1\n", ['--x', 'age', '--scale', '2.5'], '--scale must be a whole number above 0.'],
    ];
    foreach ($bad as $label => $c) {
        $in = put('bad.csv', $c[0]);
        $r = run(array_merge(['--in', $in, '--out', $dir . '/bad.json'], $c[1]));
        check('refused: ' . $label . ' (got ' . json_encode($r['err']) . ')', $r['code'] === 1 && strpos($r['err'], $c[2]) !== false);
    }
    $r = run(['--in', $dir . '/bad.csv']);
    check('refused: no --out', $r['code'] === 1 && strpos($r['err'], '--out is required') !== false);

    // ---- --check: one z-score, read by the module's own code
    $r = run(['--check', 'who-wfa', '--sex', 'male', '--x', '365', '--y', '6.6']);
    check('check: a shipped reference, the worked example of the docs (got ' . json_encode($r) . ')', $r['code'] === 0
        && strpos($r['out'], 'age 365 days: L 0.0645, M 9.646, S 0.10925') !== false && strpos($r['out'], "z-score -3.40 for 6.6 kg\n") !== false);
    $r = run(['--check', 'who-wfh', '--sex', 'female', '--x', '80', '--y', '10']);
    check('check: a height axis (got ' . json_encode($r) . ')', $r['code'] === 0 && strpos($r['out'], "z-score -0.26 for 10 kg\n") !== false);
    // the CDC-shaped table converted above, in a folder of extra references
    $cdcEntry = ['title' => 'Test weight-for-age', 'file' => 'cdc.json', 'sha256' => hash('sha256', (string) file_get_contents($dir . '/cdc.json')),
                 'measure' => 'weight', 'unit' => 'kg', 'axis' => 'age', 'axisUnit' => 'months', 'lookup' => 'floor',
                 'valid' => ['min' => '24', 'below' => '27'], 'adjust' => 'none'];
    put('index.json', json_encode(['format' => 'uv-references-1', 'references' => [
        'test-wfa' => $cdcEntry,
        'test-badsha' => ['sha256' => str_repeat('0', 64)] + $cdcEntry,
        'test-nounit' => array_diff_key($cdcEntry, ['unit' => 1]),
    ]]));
    // row 25 (25.5 months in the CDC file): L -0.239, M 12.885, S 0.108; ((13/12.885)^-0.239 - 1) / (-0.239 * 0.108) = 0.0822
    $r = run(['--check', 'test-wfa', '--folder', $dir, '--sex', 'male', '--x', '25.9', '--y', '13']);
    check('check: a reference from a folder, a floor lookup (got ' . json_encode($r) . ')', $r['code'] === 0
        && strpos($r['out'], 'Test weight-for-age, male, age 25.9 months: L -0.239, M 12.885, S 0.108') !== false
        && strpos($r['out'], "z-score 0.08 for 13 kg\n") !== false);
    $r = run(['--check', 'who-wfa', '--folder', $dir, '--sex', 'male', '--x', '365', '--y', '6.6']);
    check('check: the shipped references stay beside a folder, whose broken entries are listed (got ' . json_encode($r) . ')', $r['code'] === 0
        && strpos($r['out'], 'z-score -3.40') !== false && strpos($r['err'], 'reference "test-nounit" needs "unit".') !== false);
    $fails = [
        'an unknown id'            => [['--check', 'nope', '--sex', 'male', '--x', '1', '--y', '1'], 'no usable reference "nope" among the shipped references.'],
        'an unknown id, a folder'  => [['--check', 'nope', '--folder', $dir, '--sex', 'male', '--x', '1', '--y', '1'], 'no usable reference "nope" among the shipped references or in ' . $dir . '.'],
        'a broken entry'           => [['--check', 'test-nounit', '--folder', $dir, '--sex', 'male', '--x', '25', '--y', '13'], 'no usable reference "test-nounit": the reference folder in the module settings: reference "test-nounit" needs "unit".'],
        'a table that is not its SHA-256' => [['--check', 'test-badsha', '--folder', $dir, '--sex', 'male', '--x', '25', '--y', '13'], 'cdc.json'],
        'x outside the reference'  => [['--check', 'who-wfa', '--sex', 'male', '--x', '2000', '--y', '6.6'], 'the age is outside the reference (0 to under 1826.25 days).'],
        'a sex code, not a sex'    => [['--check', 'who-wfa', '--sex', '1', '--x', '365', '--y', '6.6'], '--check needs --sex male or --sex female.'],
        'no --y'                   => [['--check', 'who-wfa', '--sex', 'male', '--x', '365'], '--check needs --x'],
        '--x not a number'         => [['--check', 'who-wfa', '--sex', 'male', '--x', 'a year', '--y', '6.6'], '--check needs --x'],
        '--y of 0'                 => [['--check', 'who-wfa', '--sex', 'male', '--x', '365', '--y', '0'], '--y must be a number above 0.'],
    ];
    foreach ($fails as $label => $c) {
        $r = run($c[0]);
        check('check refused: ' . $label . ' (got ' . json_encode($r['err']) . ')', $r['code'] === 1 && $r['out'] === '' && strpos($r['err'], $c[1]) !== false);
    }

    // ---- data/references/examples: the guide's walkthrough of a reference of your own.
    // Each command the guide prints, run from data/references, must rebuild the
    // committed table byte for byte (its SHA-256 is the one in examples/index.json),
    // and each --check the guide prints must print what the guide shows.
    $refs = realpath(__DIR__ . '/../data/references');
    $guide = (string) file_get_contents(__DIR__ . '/../docs/action_tag_validation_examples.md');
    $exIndex = json_decode((string) file_get_contents($refs . '/examples/index.json'), true);
    $converts = [
        'example-hfa'  => ['--in', 'examples/hfa-national.csv', '--out', 'examples/example-hfa.json', '--x', 'agemos'],
        'example-wfl'  => ['--in', 'examples/wfl-national.csv', '--out', 'examples/example-wfl.json', '--x', 'Length', '--scale', '2'],
        'example-bwga' => ['--in', 'examples/bwga-daily.csv', '--out', 'examples/example-bwga.json', '--x', 'ga_days'],
    ];
    foreach ($converts as $id => $args) {
        $shown = 'php convert.php ' . implode(' ', $args);
        check('examples: the guide prints the command for ' . $id, strpos($guide, $shown) !== false);
        $tmp = $dir . DIRECTORY_SEPARATOR . $id . '.json';
        $a = $args;
        foreach ($a as $i => $v) {
            if ($i > 0 && $a[$i - 1] === '--in') $a[$i] = $refs . DIRECTORY_SEPARATOR . $v;
            if ($i > 0 && $a[$i - 1] === '--out') $a[$i] = $tmp;
        }
        $r = run($a);
        $sha = is_file($tmp) ? hash_file('sha256', $tmp) : '';
        check('examples: ' . $id . ' rebuilds as committed (got ' . json_encode($r['err']) . ')', $r['code'] === 0
            && $sha === ($exIndex['references'][$id]['sha256'] ?? null)
            && $sha === hash_file('sha256', $refs . '/examples/' . $exIndex['references'][$id]['file']));
    }
    $exChecks = [
        ['example-hfa', 'male', '66', '115'],
        ['example-wfl', 'female', '47.3', '2.5'],
        ['example-bwga', 'male', '286', '2.2'],
    ];
    foreach ($exChecks as $c) {
        $args = ['--check', $c[0], '--folder', 'examples', '--sex', $c[1], '--x', $c[2], '--y', $c[3]];
        check('examples: the guide prints the check of ' . $c[0], strpos($guide, 'php convert.php ' . implode(' ', $args)) !== false);
        $args[3] = $refs . DIRECTORY_SEPARATOR . 'examples';
        $r = run($args);
        $lines = array_filter(explode("\n", str_replace("\r", '', $r['out'])));
        $shownAll = (bool) $lines;
        foreach ($lines as $l) $shownAll = $shownAll && strpos($guide, $l) !== false;
        check('examples: the check of ' . $c[0] . ' prints what the guide shows (got ' . json_encode($r) . ')', $r['code'] === 0 && $shownAll);
    }

    foreach (glob($dir . DIRECTORY_SEPARATOR . '*') ?: [] as $f) unlink($f);
    rmdir($dir);
    fwrite(STDOUT, "convert_php: $n checks, $fail failure(s)\n");
    exit($fail ? 1 : 0);
}
