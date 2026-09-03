<?php
/**
 * namespace_lint_php.php — no unqualified global class inside a namespaced file.
 *
 * Every file here declares `namespace INSPIRE\UniversalValidator[\Scan]`, so an
 * unqualified `Throwable` resolves to `INSPIRE\UniversalValidator\Throwable` —
 * a class that does not exist and that nothing ever throws. A `catch` written
 * that way is DEAD: it compiles, `php -l` passes, every test passes, and the
 * exception it was written to swallow sails straight out of the hook.
 *
 * That shipped. `UniversalValidator::installScanSchema()` guarded its schema
 * migration with `catch (Throwable $e)` against 42 siblings that wrote
 * `catch (\Throwable $e)`, so a refused CREATE TABLE or a failed slot INSERT
 * during a settings save would have escaped the one handler whose docblock
 * promises it "returns nothing and throws nothing" — and failed the
 * administrator's save instead.
 *
 * `php -l` cannot see this: name resolution is a runtime concern, and the
 * broken tree lints clean. This test is the only thing that looks.
 *
 * Deliberately narrow. It checks the three positions where a global class is
 * named without `new`-ing a local one — `catch`, `new`, `instanceof` — against
 * an explicit list of PHP's own classes, and it ignores anything the file
 * `use`s. It does not inspect static receivers or type hints; claiming a
 * coverage the code does not have is how the next one gets through.
 *
 * Run:  php tests/namespace_lint_php.php
 */

$root = dirname(__DIR__);

$n = 0;
$fail = 0;

function check($label, $cond)
{
    global $n, $fail;
    $n++;
    if (!$cond) {
        $fail++;
        fwrite(STDERR, "FAIL: $label\n");
    }
}

/** PHP's own classes that this codebase names. Extend when a new one is used. */
$GLOBAL_CLASSES = [
    'Throwable', 'Exception', 'Error', 'TypeError', 'ValueError', 'ArithmeticError',
    'DivisionByZeroError', 'ErrorException', 'RuntimeException', 'LogicException',
    'InvalidArgumentException', 'OutOfRangeException', 'LengthException',
    'DomainException', 'UnexpectedValueException', 'JsonException',
    'DateTime', 'DateTimeImmutable', 'DateInterval', 'Generator', 'Closure',
    'ArrayObject', 'SplQueue', 'SplStack', 'stdClass',
];

/**
 * Every unqualified global-class reference in one namespaced source.
 *
 * Token-based, not regex: a comment or a string containing the word
 * "Throwable" is not a class reference, and the tokenizer is the only thing
 * that reliably knows the difference.
 *
 * @return array<int, array{line:int, kind:string, class:string}>
 */
function uv_unqualified_globals($src, array $globals)
{
    $tokens = token_get_all($src);
    $count  = count($tokens);

    // Namespaced at all? A plain global-namespace file needs no backslash.
    $namespaced = false;
    foreach ($tokens as $t) {
        if (is_array($t) && $t[0] === T_NAMESPACE) { $namespaced = true; break; }
    }
    if (!$namespaced) return [];

    // What the file imported: `use Foo\Bar;` makes a later bare `Bar` correct.
    $imported = [];
    for ($i = 0; $i < $count; $i++) {
        if (!is_array($tokens[$i]) || $tokens[$i][0] !== T_USE) continue;
        $last = null;
        for ($j = $i + 1; $j < $count; $j++) {
            $t = $tokens[$j];
            if (is_string($t) && ($t === ';' || $t === '{' || $t === '(')) break;
            if (!is_array($t)) continue;
            // PHP 8 hands back one T_NAME_QUALIFIED; PHP 7.4 hands back the
            // pieces. Take the trailing segment either way.
            if (defined('T_NAME_QUALIFIED') && $t[0] === T_NAME_QUALIFIED) {
                $parts = explode('\\', $t[1]);
                $last  = end($parts);
            } elseif ($t[0] === T_STRING) {
                $last = $t[1];
            } elseif ($t[0] === T_AS) {
                $last = null;   // aliased: the alias is the next T_STRING
            }
        }
        if ($last !== null) $imported[$last] = true;
    }

    $KINDS = [T_CATCH => 'catch', T_NEW => 'new', T_INSTANCEOF => 'instanceof'];
    $hits  = [];

    for ($i = 0; $i < $count; $i++) {
        $t = $tokens[$i];
        if (!is_array($t) || !isset($KINDS[$t[0]])) continue;
        $kind = $KINDS[$t[0]];

        // Collect the class-name position(s) that follow, as ARMS. `catch` may
        // list several types separated by `|` and each one has to be judged on
        // its own — reading only the first is how `catch (\Error | Throwable)`
        // slips through. `new` and `instanceof` name exactly one.
        $arms = [];        // each: ['text' => 'Foo', 'line' => n]
        $curr = '';
        $line = 0;
        for ($j = $i + 1; $j < $count; $j++) {
            $u = $tokens[$j];

            if (is_array($u) && ($u[0] === T_WHITESPACE || $u[0] === T_COMMENT
                    || $u[0] === T_DOC_COMMENT)) {
                continue;
            }
            if (is_string($u) && $u === '(' && $kind === 'catch' && $curr === '') continue;
            if (is_string($u) && $u === '|') {          // union bar: close this arm
                if ($curr !== '') { $arms[] = ['text' => $curr, 'line' => $line]; $curr = ''; }
                continue;
            }

            if (is_array($u)) {
                // PHP 8 hands back whole names; PHP 7.4 hands back the pieces.
                if (defined('T_NAME_FULLY_QUALIFIED') && $u[0] === T_NAME_FULLY_QUALIFIED) {
                    $curr .= $u[1]; $line = $u[2]; continue;
                }
                if (defined('T_NAME_QUALIFIED') && $u[0] === T_NAME_QUALIFIED) {
                    $curr .= $u[1]; $line = $u[2]; continue;
                }
                if ($u[0] === T_NS_SEPARATOR) { $curr .= '\\'; $line = $u[2]; continue; }
                if ($u[0] === T_STRING)       { $curr .= $u[1]; $line = $u[2]; continue; }
            }
            break;   // T_VARIABLE, ')', '{', a `new $class`, `new static` — the name position is over
        }
        if ($curr !== '') $arms[] = ['text' => $curr, 'line' => $line];

        foreach ($arms as $arm) {
            $cls = $arm['text'];
            if ($cls === '' || $cls[0] === '\\') continue;          // already qualified — the fix
            if (strpos($cls, '\\') !== false) continue;             // Foo\Bar — relative, deliberate
            if (in_array($cls, $globals, true) && !isset($imported[$cls])) {
                $hits[] = ['line' => $arm['line'], 'kind' => $kind, 'class' => $cls];
            }
        }
    }
    return $hits;
}

// ---- the sweep ------------------------------------------------------------

$files = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,
    RecursiveDirectoryIterator::SKIP_DOTS));
foreach ($it as $f) {
    $path = str_replace('\\', '/', $f->getPathname());
    if (substr($path, -4) !== '.php') continue;
    if (strpos($path, '/.git/') !== false || strpos($path, '/.claude/') !== false) continue;
    $files[] = $path;
}
sort($files);

check('the sweep actually found the codebase', count($files) >= 30);

$offenders = [];
$scanned = 0;
foreach ($files as $path) {
    $src = file_get_contents($path);
    if ($src === false) continue;
    $scanned++;
    foreach (uv_unqualified_globals($src, $GLOBAL_CLASSES) as $h) {
        $rel = ltrim(substr($path, strlen(str_replace('\\', '/', $root))), '/');
        $offenders[] = sprintf('%s:%d  %s %s', $rel, $h['line'], $h['kind'], $h['class']);
    }
}

if ($offenders) {
    fwrite(STDERR, "Unqualified global classes inside namespaced files:\n");
    foreach ($offenders as $o) fwrite(STDERR, "  $o\n");
    fwrite(STDERR, "Write \\Throwable, not Throwable: inside a namespace the bare name\n"
                 . "resolves to a class in THAT namespace, so the catch never fires.\n");
}
check('no unqualified global class in any namespaced file', $offenders === []);

// ---- the detector itself is not inert -------------------------------------
// A lint that cannot fail is the same defect one level up, so prove each arm
// on synthetic sources rather than trusting the clean sweep above.

$ns = "<?php\nnamespace Demo\\Thing;\n";

check('detects an unqualified catch',
    count(uv_unqualified_globals($ns . 'function f(){ try {} catch (Throwable $e) {} }',
        $GLOBAL_CLASSES)) === 1);

check('accepts a qualified catch',
    uv_unqualified_globals($ns . 'function f(){ try {} catch (\\Throwable $e) {} }',
        $GLOBAL_CLASSES) === []);

check('accepts an imported class',
    uv_unqualified_globals($ns . "use Throwable;\nfunction f(){ try {} catch (Throwable \$e) {} }",
        $GLOBAL_CLASSES) === []);

check('detects the second arm of a union catch',
    count(uv_unqualified_globals($ns . 'function f(){ try {} catch (\\Error | Throwable $e) {} }',
        $GLOBAL_CLASSES)) === 1);

check('detects an unqualified new',
    count(uv_unqualified_globals($ns . 'function f(){ throw new RuntimeException("x"); }',
        $GLOBAL_CLASSES)) === 1);

check('detects an unqualified instanceof',
    count(uv_unqualified_globals($ns . 'function f($e){ return $e instanceof Exception; }',
        $GLOBAL_CLASSES)) === 1);

check('ignores a same-namespace class that is not a global',
    uv_unqualified_globals($ns . 'function f(){ return new ScanPlanner(); }',
        $GLOBAL_CLASSES) === []);

check('ignores the word in a comment or a string',
    uv_unqualified_globals($ns . '/* catch Throwable */ $s = "catch (Throwable $e)";',
        $GLOBAL_CLASSES) === []);

check('a file with no namespace is left alone',
    uv_unqualified_globals("<?php\nfunction f(){ try {} catch (Throwable \$e) {} }",
        $GLOBAL_CLASSES) === []);

echo sprintf("namespace_lint_php: %d checks, %d failure(s), %d files scanned\n",
    $n, $fail, $scanned);
exit($fail === 0 ? 0 : 1);
