<?php
/**
 * namespace_lint_php.php — no unqualified reference to a GLOBAL class inside a
 * namespaced file.
 *
 * THE INCIDENT. installScanSchema() ended in `catch (Throwable $e)`. The file
 * declares `namespace INSPIRE\UniversalValidator`, so that clause names
 * INSPIRE\UniversalValidator\Throwable — a class nobody wrote. PHP does not
 * warn about a catch type that does not exist; the clause simply never matches.
 * The guard was inert from the day it was written, and everything thrown after
 * the migration returned — the log write, the policy read, the slot
 * provisioning — escaped it and failed the administrator's settings save, which
 * is the exact outcome its own docblock promises cannot happen.
 *
 * One character. No test could see it, because the test that named the
 * guarantee threw from query(), and Schema::migrate() catches internally and
 * returns ok=false before execution ever reaches the guarded block.
 *
 * WHY A LINT AND NOT ANOTHER UNIT TEST. The defect is not in a behaviour, it is
 * in a NAME, and a name that resolves to nothing costs nothing at parse time.
 * There is no input that makes it visible except one that throws from exactly
 * the right statement — which is why hosting_php.php now has that test, and why
 * this file exists as well: the next inert catch will be somewhere nobody
 * thought to throw from. Reachability is what a test proves; spelling is what a
 * lint proves, and this repository has now paid for both.
 *
 * TOKENS, NOT A REGEX. This codebase writes about Throwable constantly — the
 * comment above the very catch that was broken contains the string
 * "catch (Throwable)" — so a text search would flag the prose explaining the
 * fix. Everything below runs through PHP's own tokenizer, and L-04 asserts that
 * the literal text is present in the tree while the lint still reports clean.
 *
 * WHAT IS CHECKED, in a deliberately conservative order:
 *   catch clauses     the incident, and the only place where an unresolvable
 *                     name fails SILENTLY rather than fatally
 *   new X             a fatal at runtime, but only on the path that reaches it
 *   instanceof X      never fatal: it just answers false, forever
 *   X::method()       a fatal at runtime, on the path that reaches it
 *
 * A name is flagged only when it is written bare, inside a namespace, is not
 * imported by a `use` statement, and is a class PHP itself declares in the
 * global namespace (plus REDCap's own globals). Anything else is left alone:
 * this lint prefers to miss a defect over to cry wolf, because a lint that
 * cries wolf is one someone eventually deletes.
 *
 * Run:  php tests/namespace_lint_php.php
 */

namespace {

    $n = 0; $fail = 0;
    function check($label, $cond) {
        global $n, $fail; $n++;
        if (!$cond) { $fail++; fwrite(STDERR, "FAIL: $label\n"); }
    }

    /**
     * The SHIPPED tree, and only the shipped tree.
     *
     * tests/ is excluded because a test may legitimately construct the broken
     * shape to prove the lint catches it, and tools/ because it holds untracked
     * scratch scripts — one of which reproduces this very defect on purpose.
     * Including either would make the lint fail for the wrong reason, or worse,
     * teach the next reader to add an exclusion instead of a fix.
     */
    function uv_production_files() {
        $root = dirname(__DIR__);
        $out = [$root . '/UniversalValidator.php'];
        foreach ([$root . '/php', $root . '/pages'] as $dir) {
            if (!is_dir($dir)) continue;
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir,
                \FilesystemIterator::SKIP_DOTS));
            foreach ($it as $f) {
                if (substr($f->getFilename(), -4) === '.php') $out[] = $f->getPathname();
            }
        }
        sort($out);
        return $out;
    }

    /**
     * Every class PHP declares in the GLOBAL namespace, asked of PHP.
     *
     * A hand-maintained list of exception names would rot, and rot silently:
     * the day someone writes `catch (JsonException)` the list would not have it
     * and the lint would pass. get_declared_classes() answers for whatever
     * build is running, which is the only authority that matters. The set
     * differs slightly across the CI matrix (7.4 through 8.4) and that is fine —
     * Throwable, Exception and Error are in every one of them, and a name only
     * needs to be recognised by ONE matrix job to fail the build.
     */
    function uv_global_classes() {
        $out = [];
        foreach (array_merge(get_declared_classes(), get_declared_interfaces()) as $c) {
            if (strpos($c, '\\') === false) $out[strtolower($c)] = true;
        }
        // REDCap's own globals. They are not declared in a CLI process, and
        // they are precisely the global classes this module names most often -
        // \REDCap::getData, \REDCap::getRecordIdField, \Project.
        foreach (['redcap', 'project'] as $c) $out[$c] = true;
        return $out;
    }

    /**
     * Unqualified global-class references in one source, with line numbers.
     *
     * Takes SOURCE TEXT rather than a path, so the mutation proof below can
     * lint a modified copy of a real file without writing it to disk.
     *
     * @return array<int,array{line:int, name:string, kind:string}>
     */
    function uv_namespace_lint($source, array $globals) {
        $raw = token_get_all($source);
        // One pass to drop whitespace and comments, so "the next thing" and
        // "the previous thing" mean what they say everywhere below.
        $t = [];
        foreach ($raw as $tok) {
            if (is_array($tok)) {
                if ($tok[0] === T_WHITESPACE || $tok[0] === T_COMMENT || $tok[0] === T_DOC_COMMENT) continue;
                $t[] = [$tok[0], $tok[1], $tok[2]];
            } else {
                $line = $t ? $t[count($t) - 1][2] : 1;
                $t[] = [$tok, $tok, $line];
            }
        }

        // A written name, in whichever token shape this PHP version uses. PHP 8
        // emits one T_NAME_* token; 7.4 emits T_NS_SEPARATOR and T_STRING
        // separately. Returns [text, indexAfter] or null.
        $readName = function ($i) use ($t) {
            $c = count($t);
            $text = '';
            if ($i < $c && is_int($t[$i][0])) {
                $id = $t[$i][0];
                if ((defined('T_NAME_FULLY_QUALIFIED') && $id === T_NAME_FULLY_QUALIFIED)
                        || (defined('T_NAME_QUALIFIED') && $id === T_NAME_QUALIFIED)
                        || (defined('T_NAME_RELATIVE') && $id === T_NAME_RELATIVE)) {
                    return [$t[$i][1], $i + 1];
                }
            }
            while ($i < $c) {
                $id = $t[$i][0];
                if ($id === T_NS_SEPARATOR || $id === T_STRING) {
                    $text .= $t[$i][1];
                    $i++;
                    continue;
                }
                break;
            }
            return $text === '' ? null : [$text, $i];
        };

        $findings = [];
        $ns = '';
        $imports = [];
        $judge = function ($name, $line, $kind) use (&$findings, &$ns, &$imports, $globals) {
            if ($ns === '') return;                       // global file: bare IS the global class
            if ($name === '' || $name[0] === '\\') return; // fully qualified, which is the fix
            if (strpos($name, '\\') !== false) return;     // relative: cannot name a global class
            $low = strtolower($name);
            if ($low === 'self' || $low === 'static' || $low === 'parent') return;
            if (isset($imports[$low])) return;              // `use Exception;` makes it legal
            if (!isset($globals[$low])) return;             // not a global class: not our business
            $findings[] = ['line' => $line, 'name' => $name, 'kind' => $kind];
        };

        for ($i = 0, $c = count($t); $i < $c; $i++) {
            $id = $t[$i][0];

            if ($id === T_NAMESPACE) {
                $r = $readName($i + 1);
                $ns = $r ? $r[0] : '';
                $imports = [];                              // imports are per namespace block
                continue;
            }

            if ($id === T_USE) {
                // Not a closure's `use ($x)`, and not `use function` / `use const`.
                $j = $i + 1;
                if ($j < $c && ($t[$j][0] === '(' || $t[$j][0] === T_FUNCTION || $t[$j][0] === T_CONST)) {
                    continue;
                }
                while ($j < $c && $t[$j][0] !== ';' && $t[$j][0] !== '{') {
                    $r = $readName($j);
                    if ($r === null) { $j++; continue; }
                    $alias = $r[0];
                    $j = $r[1];
                    if ($j < $c && $t[$j][0] === T_AS && isset($t[$j + 1]) && $t[$j + 1][0] === T_STRING) {
                        $alias = $t[$j + 1][1];
                        $j += 2;
                    }
                    $parts = explode('\\', $alias);
                    $imports[strtolower(end($parts))] = true;
                }
                continue;
            }

            if ($id === T_CATCH) {
                $j = $i + 1;
                if ($j < $c && $t[$j][0] === '(') $j++;
                while ($j < $c && $t[$j][0] !== ')' && $t[$j][0] !== T_VARIABLE) {
                    if ($t[$j][0] === '|') { $j++; continue; }
                    $r = $readName($j);
                    if ($r === null) { $j++; continue; }
                    $judge($r[0], $t[$j][2], 'catch');
                    $j = $r[1];
                }
                continue;
            }

            if ($id === T_NEW) {
                $j = $i + 1;
                // `new class extends ...` and `new $var` name nothing to check.
                if ($j < $c && ($t[$j][0] === T_CLASS || $t[$j][0] === T_VARIABLE || $t[$j][0] === T_STATIC)) {
                    continue;
                }
                $r = $readName($j);
                if ($r !== null) $judge($r[0], $t[$j][2], 'new');
                continue;
            }

            if ($id === T_INSTANCEOF) {
                $j = $i + 1;
                if ($j < $c && $t[$j][0] === T_VARIABLE) continue;
                $r = $readName($j);
                if ($r !== null) $judge($r[0], $t[$j][2], 'instanceof');
                continue;
            }

            if ($id === T_DOUBLE_COLON && $i > 0) {
                // Walk back over the name so `Foo\Bar::x()` is read whole rather
                // than as a bare `Bar`.
                $j = $i - 1;
                while ($j > 0 && ($t[$j - 1][0] === T_NS_SEPARATOR || $t[$j - 1][0] === T_STRING)) $j--;
                if ($t[$j][0] === T_STRING || $t[$j][0] === T_NS_SEPARATOR
                        || (defined('T_NAME_QUALIFIED') && $t[$j][0] === T_NAME_QUALIFIED)
                        || (defined('T_NAME_FULLY_QUALIFIED') && $t[$j][0] === T_NAME_FULLY_QUALIFIED)) {
                    $r = $readName($j);
                    if ($r !== null && $r[1] === $i) $judge($r[0], $t[$j][2], 'static call');
                }
                continue;
            }
        }
        return $findings;
    }

    /**
     * A `//` comment written INSIDE a string literal, which is not a comment.
     *
     * THE INCIDENT. revokePreviews() explained its own join with five `//`
     * lines placed between `'UPDATE ... f` and `JOIN '` — inside the quotes.
     * PHP sees string content and is happy; MySQL sees query text and answers
     * ERROR 1064, because it takes `#`, `-- ` and slash-star and has never
     * taken `//`. The statement could not parse on any server, so the
     * cross-project scoping fix that those five lines describe had never once
     * run. The method has no production caller yet, which is the only reason a
     * plain syntax error survived a green suite — and is exactly why a lint,
     * not a test, is the thing that catches it.
     *
     * THE RULE IS DELIBERATELY BLUNT: any MULTI-LINE string literal with a line
     * whose first non-blank characters are `//`. No attempt is made to decide
     * whether the string is SQL. Measured against the whole shipped tree the
     * blunt rule flags nothing but the defect, so the precision a cleverer rule
     * would buy is precision nobody needs, and a cleverer rule is one that can
     * be wrong. Single-line strings are left alone, so `http://` in a URL — the
     * one shape that would otherwise cry wolf — never reaches the test.
     *
     * @return array<int,array{line:int, text:string}>
     */
    function uv_string_comment_lint($source) {
        $out = [];
        foreach (token_get_all($source) as $tok) {
            if (!is_array($tok)) continue;
            // Both halves of the quoting world: a plain literal, and the text
            // runs of a double-quoted or heredoc string that interpolates.
            if ($tok[0] !== T_CONSTANT_ENCAPSED_STRING && $tok[0] !== T_ENCAPSED_AND_WHITESPACE) continue;
            if (strpos($tok[1], "\n") === false) continue;
            if (!preg_match('~(?:^|\n)[ \t]*//~', $tok[1])) continue;
            $out[] = ['line' => $tok[2], 'text' => substr(preg_replace('/\s+/', ' ', trim($tok[1])), 0, 70)];
        }
        return $out;
    }

    $globals = uv_global_classes();
    $files   = uv_production_files();

    /* =====================================================================
     * L-01  the corpus is real
     *
     * A lint that reads nothing passes. Every assertion below is worthless
     * without this one, so it is first and it names files rather than counting.
     * ===================================================================== */
    {
        $names = array_map(function ($p) { return str_replace('\\', '/', $p); }, $files);
        $has = function ($suffix) use ($names) {
            foreach ($names as $p) if (substr($p, -strlen($suffix)) === $suffix) return true;
            return false;
        };
        check('L-01: the module class is in the corpus', $has('/UniversalValidator.php'));
        check('L-01: so is the capability probe', $has('/php/ScanCapabilities.php'));
        check('L-01: so is the durable scan', $has('/php/Scan/ScanService.php'));
        check('L-01: so are the pages', $has('/pages/scan.php'));
        check('L-01: and the corpus is the whole tree, not one directory',
            count($files) >= 25);
        check('L-01: tests are NOT linted', !$has('/tests/namespace_lint_php.php'));
        check('L-01: nor are the scratch tools', (bool) !preg_grep('~/tools/~', $names));
        check('L-01: PHP knows what Throwable is', isset($globals['throwable'])
            && isset($globals['exception']) && isset($globals['error'])
            && isset($globals['runtimeexception']));
    }

    /* =====================================================================
     * L-02  the shipped tree is clean
     * ===================================================================== */
    {
        $all = [];
        foreach ($files as $f) {
            foreach (uv_namespace_lint(file_get_contents($f), $globals) as $hit) {
                $all[] = basename($f) . ':' . $hit['line'] . '  ' . $hit['kind']
                       . ' ' . $hit['name'] . ' — write \\' . $hit['name'];
            }
        }
        if ($all) fwrite(STDERR, "  unqualified global classes inside a namespace:\n    "
            . implode("\n    ", $all) . "\n");
        check('L-02: no shipped file names a global class unqualified inside a namespace',
            $all === []);
    }

    /* =====================================================================
     * L-03  the lint reads CODE, not prose
     *
     * The comment above the repaired catch quotes the broken form, because that
     * is how this codebase records why a line looks the way it does. A text
     * search over the tree would match it. Both halves are asserted together:
     * the string IS there, and the lint IS clean.
     * ===================================================================== */
    {
        $src = file_get_contents(dirname(__DIR__) . '/UniversalValidator.php');
        check('L-03: the tree really does contain the broken form, in prose',
            strpos($src, 'catch (Throwable') !== false);
        check('L-03: and the tokenizer does not mistake prose for code',
            uv_namespace_lint($src, $globals) === []);
        $lit = "<?php\nnamespace A;\n// catch (Throwable \$e)\n\$s = 'catch (Throwable \$e)';\n";
        check('L-03: nor a string literal', uv_namespace_lint($lit, $globals) === []);
    }

    /* =====================================================================
     * L-04  REMOVE THE BACKSLASH AGAIN AND THIS FAILS
     *
     * The mutation is applied to the source TEXT of the real file, in memory.
     * Nothing is written, so this runs on every CI job rather than being a
     * procedure someone is trusted to have followed once.
     * ===================================================================== */
    {
        $src = file_get_contents(dirname(__DIR__) . '/UniversalValidator.php');
        $total = preg_match_all('/catch \(\\\\Throwable/', $src);
        check('L-04: the module class does catch Throwable, qualified, in many places',
            $total >= 20);

        $one = preg_replace('/catch \(\\\\Throwable/', 'catch (Throwable', $src, 1);
        $hits = uv_namespace_lint($one, $globals);
        check('L-04: removing ONE backslash is caught', count($hits) === 1);
        check('L-04: and the report names the clause and the class',
            isset($hits[0]) && $hits[0]['kind'] === 'catch' && $hits[0]['name'] === 'Throwable');
        check('L-04: at the line it is on',
            isset($hits[0]) && $hits[0]['line'] > 0
            && strpos(explode("\n", $one)[$hits[0]['line'] - 1], 'catch (Throwable') !== false);

        // A floor rather than an equality, deliberately. str_replace edits
        // prose as readily as code, and this codebase quotes code in its
        // comments constantly — the day someone writes `catch (\Throwable` in a
        // sentence, an equality here would go red for a reason that has nothing
        // to do with the lint. Every finding is checked to land on a mutated
        // clause, which is the property that matters.
        $all = str_replace('catch (\\Throwable', 'catch (Throwable', $src);
        $many = uv_namespace_lint($all, $globals);
        check('L-04: and removing every backslash is caught every time',
            count($many) >= 20);
        $offTarget = 0;
        $lines = explode("\n", $all);
        foreach ($many as $hit) {
            if (strpos($lines[$hit['line'] - 1], 'catch (Throwable') === false) $offTarget++;
        }
        check('L-04: every one of them on a clause, none of them on prose', $offTarget === 0);
    }

    /* =====================================================================
     * L-05  the other three shapes, and the cases that must NOT be flagged
     *
     * Synthetic sources, because the tree is clean and a lint whose positive
     * cases only exist in history is a lint that can quietly stop working.
     * ===================================================================== */
    {
        $lint = function ($body) use ($globals) {
            return uv_namespace_lint("<?php\n" . $body, $globals);
        };

        check('L-05: an unqualified catch inside a namespace is flagged',
            count($lint("namespace A;\ntry { f(); } catch (Throwable \$e) {}")) === 1);
        check('L-05: so is a second type in the same clause',
            count($lint("namespace A;\ntry { f(); } catch (\\Error | RuntimeException \$e) {}")) === 1);
        check('L-05: a PHP 8 catch with no variable is still read',
            count($lint("namespace A;\ntry { f(); } catch (Exception) {}")) === 1);
        check('L-05: new is flagged',
            count($lint("namespace A;\n\$x = new RuntimeException('x');")) === 1);
        check('L-05: instanceof is flagged — it never fatals, it just answers false forever',
            count($lint("namespace A;\nif (\$e instanceof Throwable) {}")) === 1);
        check('L-05: a static call is flagged',
            count($lint("namespace A;\nREDCap::getData([]);")) === 1);

        // And the far more important half: everything correct stays quiet.
        check('L-05 control: the global namespace may write it bare, because there it IS the class',
            $lint("try { f(); } catch (Throwable \$e) {}") === []);
        check('L-05 control: a qualified catch is the fix, not a finding',
            $lint("namespace A;\ntry { f(); } catch (\\Throwable \$e) {}") === []);
        check('L-05 control: an imported name is legal',
            $lint("namespace A;\nuse RuntimeException;\ntry { f(); } catch (RuntimeException \$e) {}") === []);
        check('L-05 control: an aliased import is legal',
            $lint("namespace A;\nuse \\Throwable as T;\ntry { f(); } catch (T \$e) {}") === []);
        check('L-05 control: the module\'s own classes are not global classes',
            $lint("namespace A;\n\$x = new ScanWorker(); if (\$x instanceof ScanStore) {} ScanPhase::next();") === []);
        check('L-05 control: a relative name resolves inside the namespace and is not a global class',
            $lint("namespace A;\n\$x = new Scan\\ModuleDb(\$m); Scan\\Schema::migrate(\$m);") === []);
        check('L-05 control: self, static and parent are not classes to qualify',
            $lint("namespace A;\nclass B { function f() { self::g(); static::h(); parent::i(); } }") === []);
        check('L-05 control: a closure\'s use() is not an import list',
            count($lint("namespace A;\n\$f = function () use (\$g) { throw new Exception('x'); };")) === 1);
        check('L-05 control: an anonymous class is not a name',
            $lint("namespace A;\n\$x = new class { public \$a = 1; };") === []);
        check('L-05 control: a variable class name cannot be judged, and is not guessed',
            $lint("namespace A;\n\$c = 'Throwable'; \$x = new \$c();") === []);
        check('L-05 control: imports do not leak into the next namespace block',
            count($lint("namespace A { use RuntimeException; }\nnamespace B { try { f(); } catch (RuntimeException \$e) {} }")) === 1);
    }


    /* =====================================================================
     * L-06  no `//` comment inside a string literal
     *
     * The second spelling defect this file has been paid for. See
     * uv_string_comment_lint() for the incident: a five-line explanation
     * written inside the quotes of a statement, which MySQL then had to parse.
     * ===================================================================== */
    {
        $hits = [];
        foreach ($files as $f) {
            foreach (uv_string_comment_lint(file_get_contents($f)) as $h) {
                $hits[] = str_replace('\\', '/', $f) . ':' . $h['line'] . '  ' . $h['text'];
            }
        }
        check('L-06: no shipped file writes a // comment inside a string literal',
            $hits === []);
        if ($hits) foreach ($hits as $h) fwrite(STDERR, "      $h\n");

        // MUTATION PROOF. The lint has to be shown to fail, or it proves
        // nothing: the corpus passing could equally mean the check is inert.
        // This is the real statement from ScanRetention::revokePreviews() with
        // the comment put back where it was.
        $broken = "<?php\n\$db->exec('UPDATE ' . T('finding') . ' f\n"
                . "    // BOTH SIDES OF THE JOIN. It matched on generation_id alone.\n"
                . "    JOIN ' . T('scan_run') . ' r ON r.generation_id = f.generation_id\n"
                . "    SET f.value_bin = NULL WHERE r.project_id = ?', [\$pid]);\n";
        $m = uv_string_comment_lint($broken);
        check('L-06: the incident shape is caught', count($m) === 1);
        check('L-06: and reported at the line the string starts on',
            isset($m[0]['line']) && $m[0]['line'] === 2);
        check('L-06: and the report quotes the offending text',
            isset($m[0]['text']) && strpos($m[0]['text'], 'BOTH SIDES OF THE JOIN') !== false);

        // CONTROLS. Each is a shape that must NOT be flagged, and each is a
        // shape this codebase actually writes.
        check('L-06 control: a URL in a single-line string is not a comment',
            uv_string_comment_lint("<?php \$u = 'https://example.org/a//b';") === []);
        check('L-06 control: nor is a URL in a multi-line string',
            uv_string_comment_lint("<?php \$u = 'see\nhttps://example.org/a\nfor more';") === []);
        check('L-06 control: a real PHP comment beside a string is untouched',
            uv_string_comment_lint("<?php\n// a genuine comment\n\$s = 'SELECT 1\n  FROM t';") === []);
        check('L-06 control: SQL comment markers MySQL does accept are legal',
            uv_string_comment_lint("<?php \$s = 'SELECT 1\n  -- a comment\n  # another\n  /* third */\n  FROM t';") === []);
        check('L-06 control: a double-quoted string is read too',
            count(uv_string_comment_lint("<?php \$s = \"UPDATE \$t\n  // nope\n  SET a = 1\";")) === 1);
        check('L-06 control: a heredoc is read too',
            count(uv_string_comment_lint("<?php \$s = <<<SQL\nUPDATE t\n  // nope\n  SET a = 1\nSQL;\n")) === 1);
        check('L-06 control: an indented // deep in a string is still caught',
            count(uv_string_comment_lint("<?php \$s = 'a\n\t\t\t// x\nb';")) === 1);
        check('L-06 control: a slash pair mid-line is not a comment',
            uv_string_comment_lint("<?php \$s = 'SELECT a\n  FROM t WHERE p = 1//2\n  AND q = 3';") === []);
    }

    echo "namespace_lint_php: $n checks, $fail failure(s)\n";
    exit($fail ? 1 : 0);
}
