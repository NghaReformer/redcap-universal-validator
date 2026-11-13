<?php
/**
 * cli_guard_php.php - no test or tool answers a web request (FRM-1).
 *
 * The 2.4.0 archive shipped tests/ inside the module folder, so a browser
 * could run any of 122 scripts at /modules/universal_validator_vX/tests/*.php;
 * tests/mysql/run.php connects to a database as root. The archive no longer
 * holds tests/ (tests/package_contents_js.cjs), and each PHP file under
 * tests/, tools/ and data/ also opens with a guard, for the server that holds
 * a git checkout rather than the archive.
 *
 * The guard must be the FIRST statement that runs: only the open tag, comments,
 * a declare() and the namespace line may stand before it.
 *
 * Run:  php tests/cli_guard_php.php
 */

if (PHP_SAPI !== 'cli') {
    // A test or a tool, never a page: answer a web request with 404 and stop.
    http_response_code(404);
    exit;
}

$n = 0;
$fail = 0;
function check($label, $cond)
{
    global $n, $fail;
    $n++;
    if (!$cond) { $fail++; fwrite(STDERR, "FAIL: $label\n"); }
}

/** The code of a file from its first statement on, comments and white space dropped. */
function firstCode($source)
{
    $out = '';
    foreach (token_get_all($source) as $t) {
        if (is_array($t)) {
            if (in_array($t[0], [T_OPEN_TAG, T_WHITESPACE, T_COMMENT, T_DOC_COMMENT, T_INLINE_HTML], true)) continue;
            $out .= $t[1];
        } else {
            $out .= $t;
        }
        if (strlen($out) > 400) break;
    }
    // What may stand before the guard: declare(...); and one namespace header.
    $out = preg_replace('/^declare\([^)]*\);/', '', $out);
    $out = preg_replace('/^namespace[A-Za-z0-9_\\\\]*[;{]/', '', $out);
    return $out;
}

const GUARD = "if(PHP_SAPI!=='cli'){http_response_code(404);exit;}";

$root = dirname(__DIR__);
$files = [];
foreach (['tests', 'tools', 'data'] as $dir) {
    if (!is_dir($root . '/' . $dir)) continue;
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/' . $dir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        if (substr($f->getFilename(), -4) === '.php') $files[] = str_replace('\\', '/', $f->getPathname());
    }
}
sort($files);
check('the scripts are found (got ' . count($files) . ')', count($files) > 100);

$bare = [];
foreach ($files as $f) {
    if (strpos(firstCode(file_get_contents($f)), GUARD) !== 0) $bare[] = substr($f, strlen($root) + 1);
}
check('every PHP file under tests/, tools/ and data/ opens with the CLI guard (without it: ' . json_encode($bare) . ')', $bare === []);

// The reader itself: a guard that is not first, or is commented out, does not count.
check('a guard after another statement is not first',
    strpos(firstCode("<?php\nrequire 'x.php';\nif (PHP_SAPI !== 'cli') { http_response_code(404); exit; }\n"), GUARD) !== 0);
check('a guard in a comment is no guard',
    strpos(firstCode("<?php\n// if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }\necho 1;\n"), GUARD) !== 0);
check('a guard that does not stop is no guard',
    strpos(firstCode("<?php\nif (PHP_SAPI !== 'cli') { http_response_code(404); }\necho 1;\n"), GUARD) !== 0);
check('the guard may follow a docblock and a namespace line',
    strpos(firstCode("<?php\n/** doc */\nnamespace A\\B {\n    if (PHP_SAPI !== 'cli') {\n        // why\n        http_response_code(404);\n        exit;\n    }\n}\n"), GUARD) === 0);
check('...and a declare',
    strpos(firstCode("<?php\ndeclare(strict_types=1);\nnamespace A;\nif (PHP_SAPI !== 'cli') { http_response_code(404); exit; }\n"), GUARD) === 0);

echo "cli_guard_php: $n checks, $fail failure(s)\n";
exit($fail ? 1 : 0);
