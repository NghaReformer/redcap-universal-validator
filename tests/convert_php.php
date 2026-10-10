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
 *   - other sex columns and codes,
 *   - every refusal: a row off the grid, a gap, sexes starting apart, a sex
 *     code that is neither, M at or below 0, a second row for one x, a missing
 *     column, missing options.
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

    // ---- other sex codes
    $in = put('mf.csv', "gender,len,lam,mu,sig\nM,45,1,2.4,0.09\nM,45.1,1,2.42,0.09\nF,45,1,2.3,0.09\nF,45.1,1,2.32,0.09\n");
    $r = run(['--in', $in, '--out', $dir . '/mf.json', '--x', 'len', '--scale', '10', '--sex', 'gender', '--male', 'M',
              '--female', 'F', '--l', 'lam', '--m', 'mu', '--s', 'sig']);
    $t = json_decode((string) @file_get_contents($dir . '/mf.json'), true);
    check('other column names and sex codes, rows per mm (got ' . json_encode($r['err']) . ')', $r['code'] === 0
        && $t['scale'] === 10 && $t['first'] === 450 && $t['female'][1] === [1, 2.32, 0.09]);

    // ---- refusals
    $bad = [
        'a row off the grid'      => ["sex,age,l,m,s\n1,0,1,2,0.1\n1,0.5,1,2,0.1\n2,0,1,2,0.1\n", ['--x', 'age'], 'x 0.5 is not on the grid'],
        'a gap'                   => ["sex,age,l,m,s\n1,0,1,2,0.1\n1,2,1,2,0.1\n2,0,1,2,0.1\n2,1,1,2,0.1\n", ['--x', 'age'], 'the male rows have a gap'],
        'sexes starting apart'    => ["sex,age,l,m,s\n1,0,1,2,0.1\n2,1,1,2,0.1\n", ['--x', 'age'], 'male rows start at key 0, female rows at 1'],
        'a sex code that is neither' => ["sex,age,l,m,s\n1,0,1,2,0.1\n3,0,1,2,0.1\n", ['--x', 'age'], 'line 3: sex "3" is neither --male nor --female.'],
        'M of 0'                  => ["sex,age,l,m,s\n1,0,1,0,0.1\n", ['--x', 'age'], 'line 2: M and S must be above 0.'],
        'a second row for one x'  => ["sex,age,l,m,s\n1,0,1,2,0.1\n1,0,1,2,0.1\n", ['--x', 'age'], 'line 3: a second male row for x 0.'],
        'L not a number'          => ["sex,age,l,m,s\n1,0,x,2,0.1\n", ['--x', 'age'], 'line 2: L "x" is not a number.'],
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

    foreach (glob($dir . DIRECTORY_SEPARATOR . '*') ?: [] as $f) unlink($f);
    rmdir($dir);
    fwrite(STDOUT, "convert_php: $n checks, $fail failure(s)\n");
    exit($fail ? 1 : 0);
}
