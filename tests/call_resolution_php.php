<?php
/**
 * call_resolution_php.php — every $this->name(), self::name(), static::name()
 * and parent::name() in the shipped PHP names a method that exists.
 *
 * THE INCIDENT. The merge of main (10c1b16) kept a line in
 * ScanService::advanceRun() that called $this->aggregateTotal(), a method the
 * scan chain had already deleted. PHP says nothing about that until the line
 * runs, and the line ran on every work request that reached promotion - so
 * from that merge until wave 8 every such request ended in an Error, and the
 * endpoint answered "could not be reached". No fast suite and no database
 * case drove a request that far, and php -l is satisfied by a call to a
 * method nobody wrote.
 *
 * WHY A LINT AS WELL AS THE END-TO-END TEST. tests/scan_service_php.php now
 * drives start, work and status to a finished run, which proves the paths it
 * drives. A call to a method that does not exist is wrong on EVERY path, and
 * it is a fact about the text: this finds it wherever it is, including on the
 * paths no test reaches.
 *
 * WHAT IS RESOLVED, for each class, trait and interface under php/ and in
 * UniversalValidator.php:
 *   $this->m( self::m( static::m(   a method of the class, of a trait it uses,
 *                                   of an interface it implements, or of its
 *                                   parents
 *   parent::m(                      a method of the parents
 *   inside a trait                  a method of the trait, or of any class
 *                                   that uses it
 * A parent in this repository is followed; a PHP built-in parent is asked by
 * reflection; the one framework parent, AbstractExternalModule, cannot be
 * loaded here, so the methods the module calls on it are LISTED below - a
 * new framework call is one line in that list, and a deleted method of ours is
 * a failure. A parent outside the repository with no list fails the lint
 * rather than being waved through. A class with __call or __callStatic is
 * not checked for the calls that would reach it. Method names compare
 * case-insensitively, as PHP compares them.
 *
 * TOKENS, NOT A REGEX, as tests/namespace_lint_php.php: this tree writes about
 * deleted methods in its comments, and a text search would flag the prose.
 *
 * Run:  php tests/call_resolution_php.php
 */

namespace {

    $n = 0; $fail = 0;
    function check($label, $cond) {
        global $n, $fail; $n++;
        if (!$cond) { $fail++; fwrite(STDERR, "FAIL: $label\n"); }
    }

    /**
     * What the module calls on ExternalModules\AbstractExternalModule, which is
     * the framework and is not in this repository. Each is a method of the
     * framework's module base class.
     */
    function uv_cr_framework() {
        return ['AbstractExternalModule' => [
            'getJavascriptModuleObjectName', 'getProjectId', 'getProjectSetting',
            'getProjectStatus', 'getSubSettings', 'getSystemSetting', 'getUrl', 'getUser',
            'initializeJavascriptModuleObject', 'isModuleEnabled', 'log', 'queryLogs', 'setProjectSetting',
            'setSystemSetting',
        ]];
    }

    /** php/ and UniversalValidator.php: the shipped classes. */
    function uv_cr_files() {
        $root = dirname(__DIR__);
        $out = [$root . '/UniversalValidator.php'];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/php',
            \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if (substr($f->getFilename(), -4) === '.php') $out[] = $f->getPathname();
        }
        sort($out);
        return $out;
    }

    /** The tokens that carry meaning: no whitespace, no comments. */
    function uv_cr_tokens($src) {
        $out = [];
        foreach (token_get_all($src) as $t) {
            if (is_array($t) && in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT, T_OPEN_TAG], true)) continue;
            $out[] = $t;
        }
        return $out;
    }

    function uv_cr_id($t) { return is_array($t) ? $t[0] : $t; }
    function uv_cr_text($t) { return is_array($t) ? $t[1] : $t; }
    function uv_cr_line($t) { return is_array($t) ? $t[2] : 0; }

    /** Whether a token is part of a class name (7.4 splits names; 8.x does not). */
    function uv_cr_namePart($t) {
        $k = uv_cr_id($t);
        if ($k === T_STRING || $k === T_NS_SEPARATOR) return true;
        foreach (['T_NAME_QUALIFIED', 'T_NAME_FULLY_QUALIFIED', 'T_NAME_RELATIVE'] as $c) {
            if (defined($c) && $k === constant($c)) return true;
        }
        return false;
    }

    /** An identifier as a method name: PHP allows reserved words there. */
    function uv_cr_ident($t) {
        return preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', uv_cr_text($t)) === 1 && uv_cr_id($t) !== T_VARIABLE;
    }

    /**
     * Every class-like declared in one file, with its methods and its calls.
     *
     * @return array name => {kind, file, parent, interfaces, traits, methods, calls}
     *         calls: each {kind: this|self|static|parent, method, line}
     */
    function uv_cr_scan($src, $file) {
        $tk = uv_cr_tokens($src);
        $out = [];
        $stack = [];     // open class-likes: [name, the depth their body opens at]
        $depth = 0;
        $opens = ['{'];
        foreach (['T_CURLY_OPEN', 'T_DOLLAR_OPEN_CURLY_BRACES'] as $c) if (defined($c)) $opens[] = constant($c);
        $pendingClass = null;
        $nt = count($tk);
        for ($i = 0; $i < $nt; $i++) {
            $t = $tk[$i];
            $k = uv_cr_id($t);
            $prev = $i > 0 ? uv_cr_id($tk[$i - 1]) : null;

            if (in_array($k, [T_CLASS, T_TRAIT, T_INTERFACE], true) && $prev !== T_DOUBLE_COLON) {
                $kind = $k === T_TRAIT ? 'trait' : ($k === T_INTERFACE ? 'interface' : 'class');
                $j = $i + 1;
                if ($prev === T_NEW) {
                    $name = '{anonymous class at ' . basename($file) . ':' . uv_cr_line($t) . '}';
                } else {
                    $name = uv_cr_text($tk[$j]);
                    $j++;
                }
                $parent = null; $ifaces = [];
                $mode = null; $cur = '';
                for (; $j < $nt && uv_cr_text($tk[$j]) !== '{'; $j++) {
                    $kj = uv_cr_id($tk[$j]);
                    if ($kj === T_EXTENDS || $kj === T_IMPLEMENTS || uv_cr_text($tk[$j]) === ',') {
                        if ($cur !== '') {
                            if ($mode === T_EXTENDS && $kind === 'class') $parent = $cur; else $ifaces[] = $cur;
                        }
                        $cur = '';
                        if ($kj !== null && uv_cr_text($tk[$j]) !== ',') $mode = $kj;
                        continue;
                    }
                    if (uv_cr_namePart($tk[$j])) $cur .= uv_cr_text($tk[$j]);
                }
                if ($cur !== '') {
                    if ($mode === T_EXTENDS && $kind === 'class') $parent = $cur; else $ifaces[] = $cur;
                }
                $out[$name] = ['kind' => $kind, 'file' => $file, 'parent' => $parent,
                               'interfaces' => $ifaces, 'traits' => [], 'methods' => [], 'calls' => []];
                $pendingClass = $name;
                $i = $j - 1;
                continue;
            }

            if (in_array($k, $opens, true)) {
                $depth++;
                if ($pendingClass !== null) {
                    $stack[] = [$pendingClass, $depth];
                    $pendingClass = null;
                }
                continue;
            }
            if ($k === '}') {
                if ($stack && end($stack)[1] === $depth) array_pop($stack);
                $depth--;
                continue;
            }
            if (!$stack) continue;
            list($cls, $body) = end($stack);

            // Declarations, directly in the body.
            if ($depth === $body && $k === T_USE) {
                for ($j = $i + 1; $j < $nt && uv_cr_text($tk[$j]) !== ';' && uv_cr_text($tk[$j]) !== '{'; $j++) {
                    $cur = '';
                    while ($j < $nt && uv_cr_namePart($tk[$j])) { $cur .= uv_cr_text($tk[$j]); $j++; }
                    if ($cur !== '') $out[$cls]['traits'][] = $cur;
                    if ($j < $nt && (uv_cr_text($tk[$j]) === ';' || uv_cr_text($tk[$j]) === '{')) break;
                }
                continue;
            }
            if ($depth === $body && $k === T_FUNCTION) {
                $j = $i + 1;
                if ($j < $nt && uv_cr_text($tk[$j]) === '&') $j++;
                if ($j < $nt && uv_cr_ident($tk[$j]) && isset($tk[$j + 1]) && uv_cr_text($tk[$j + 1]) === '(') {
                    $out[$cls]['methods'][strtolower(uv_cr_text($tk[$j]))] = uv_cr_text($tk[$j]);
                }
                continue;
            }

            // Calls, anywhere in the body.
            $arrows = [T_OBJECT_OPERATOR];
            if (defined('T_NULLSAFE_OBJECT_OPERATOR')) $arrows[] = T_NULLSAFE_OBJECT_OPERATOR;
            if ($k === T_VARIABLE && uv_cr_text($t) === '$this' && isset($tk[$i + 3])
                    && in_array(uv_cr_id($tk[$i + 1]), $arrows, true) && uv_cr_ident($tk[$i + 2])
                    && uv_cr_text($tk[$i + 3]) === '(') {
                $out[$cls]['calls'][] = ['kind' => 'this', 'method' => uv_cr_text($tk[$i + 2]),
                                         'line' => uv_cr_line($t)];
                continue;
            }
            $word = strtolower(uv_cr_text($t));
            if (($k === T_STATIC || ($k === T_STRING && ($word === 'self' || $word === 'parent')))
                    && isset($tk[$i + 3]) && uv_cr_id($tk[$i + 1]) === T_DOUBLE_COLON
                    && uv_cr_ident($tk[$i + 2]) && uv_cr_id($tk[$i + 2]) !== T_CLASS
                    && uv_cr_text($tk[$i + 3]) === '(') {
                $out[$cls]['calls'][] = ['kind' => $word, 'method' => uv_cr_text($tk[$i + 2]),
                                         'line' => uv_cr_line($t)];
            }
        }
        return $out;
    }

    /** The last segment of a class name. */
    function uv_cr_short($name) {
        $p = strrpos($name, '\\');
        return $p === false ? $name : substr($name, $p + 1);
    }

    /**
     * The methods a class-like answers to, lower-cased, or a string saying why
     * that cannot be known.
     *
     * @return array|string
     */
    function uv_cr_methods(array $all, $name, array $framework, array $seen = []) {
        if (isset($seen[$name])) return [];
        $seen[$name] = true;
        if (!isset($all[$name])) return 'no declaration of ' . $name;
        $c = $all[$name];
        $m = array_fill_keys(array_keys($c['methods']), true);
        foreach (array_merge($c['traits'], $c['interfaces']) as $x) {
            $s = uv_cr_short($x);
            if (isset($all[$s])) {
                $more = uv_cr_methods($all, $s, $framework, $seen);
                if (is_string($more)) return $more;
                $m += $more;
            } elseif (interface_exists(ltrim($x, '\\'))) {
                foreach ((new \ReflectionClass(ltrim($x, '\\')))->getMethods() as $rm) {
                    $m[strtolower($rm->getName())] = true;
                }
            } else {
                return $name . ' uses or implements ' . $x . ', which is neither in this repository nor built in';
            }
        }
        if ($c['parent'] !== null) {
            $more = uv_cr_parentMethods($all, $c['parent'], $framework, $seen);
            if (is_string($more)) return $more;
            $m += $more;
        }
        return $m;
    }

    /** The methods a parent class answers to; see uv_cr_methods(). */
    function uv_cr_parentMethods(array $all, $parent, array $framework, array $seen = []) {
        $s = uv_cr_short($parent);
        if (isset($all[$s])) return uv_cr_methods($all, $s, $framework, $seen);
        $fq = ltrim($parent, '\\');
        if (class_exists($fq) && (new \ReflectionClass($fq))->isInternal()) {
            $m = [];
            foreach ((new \ReflectionClass($fq))->getMethods() as $rm) $m[strtolower($rm->getName())] = true;
            return $m;
        }
        if (isset($framework[$s])) {
            // Marked, so the lint can tell which listed names are really called.
            return array_fill_keys(array_map('strtolower', $framework[$s]), 'framework');
        }
        return 'the parent ' . $parent . ' is outside this repository and has no list in this lint';
    }

    /**
     * Every call that names a method its class-like does not have.
     *
     * @param array $sources file => PHP source
     * @return array{unresolved: string[], problems: string[], calls: int, classes: int}
     */
    function uv_cr_lint(array $sources, array $framework) {
        $all = [];
        foreach ($sources as $file => $src) {
            foreach (uv_cr_scan($src, $file) as $name => $c) $all[$name] = $c;
        }
        // A trait answers to whatever the classes that use it answer to.
        $users = [];
        foreach ($all as $name => $c) {
            foreach ($c['traits'] as $t) $users[uv_cr_short($t)][] = $name;
        }
        $unresolved = []; $problems = []; $calls = 0; $framed = [];
        foreach ($all as $name => $c) {
            $own = uv_cr_methods($all, $name, $framework);
            if (is_string($own)) { $problems[] = $own; continue; }
            $pool = $own;
            if ($c['kind'] === 'trait') {
                foreach (isset($users[$name]) ? $users[$name] : [] as $u) {
                    $um = uv_cr_methods($all, $u, $framework);
                    if (is_string($um)) { $problems[] = $um; continue 2; }
                    $pool += $um;
                }
            }
            $parent = null;
            if ($c['parent'] !== null) {
                $parent = uv_cr_parentMethods($all, $c['parent'], $framework);
                if (is_string($parent)) { $problems[] = $parent; continue; }
            }
            foreach ($c['calls'] as $call) {
                $calls++;
                $want = strtolower($call['method']);
                if ($call['kind'] === 'parent') {
                    $ok = $parent !== null && isset($parent[$want]);
                } elseif ($call['kind'] === 'this') {
                    $ok = isset($pool[$want]) || isset($pool['__call']);
                } else {
                    $ok = isset($pool[$want]) || isset($pool['__callstatic']);
                }
                if ($ok && $call['kind'] !== 'parent' && isset($pool[$want]) && $pool[$want] === 'framework') {
                    $framed[$want] = true;
                }
                if (!$ok) {
                    $unresolved[] = basename($c['file']) . ':' . $call['line'] . ' ' . $name . ' calls '
                        . ($call['kind'] === 'this' ? '$this->' : $call['kind'] . '::') . $call['method'] . '()';
                }
            }
        }
        return ['unresolved' => $unresolved, 'problems' => $problems, 'calls' => $calls,
                'classes' => count($all), 'framework' => array_keys($framed)];
    }

    // -- the shipped tree ------------------------------------------------------

    $sources = [];
    // Keyed by the path with forward slashes, so a file can be found by name
    // on Windows as well.
    foreach (uv_cr_files() as $f) $sources[str_replace('\\', '/', $f)] = file_get_contents($f);
    $framework = uv_cr_framework();
    $r = uv_cr_lint($sources, $framework);
    foreach ($r['problems'] as $p) fwrite(STDERR, "  cannot resolve: $p\n");
    foreach ($r['unresolved'] as $u) fwrite(STDERR, "  unresolved: $u\n");
    check('CR: every class-like and its parents could be resolved', $r['problems'] === []);
    check('CR: every $this->, self::, static:: and parent:: call names a method that exists', $r['unresolved'] === []);
    // Not vacuous: a tokenizer change that made this see nothing would pass
    // both checks above.
    check('CR: the lint saw the tree (' . $r['classes'] . ' class-likes, ' . $r['calls'] . ' calls)',
        $r['classes'] >= 40 && $r['calls'] >= 1000);
    // The framework list is what the module CALLS, not what the framework has:
    // a name nobody calls is a name a deleted method of ours could hide behind.
    $idle = array_diff(array_map('strtolower', $framework['AbstractExternalModule']), $r['framework']);
    check('CR: every framework method listed is one the module calls'
        . ($idle ? ' (not called: ' . implode(', ', $idle) . ')' : ''), $idle === []);

    // -- it catches what it is for -----------------------------------------------

    // THE INCIDENT, put back. The real ScanService.php with the merge's call
    // restored beside the promotion inputs. The anchor is checked first: if
    // that line is ever rewritten this must fail, not quietly stop testing.
    $svcFile = str_replace('\\', '/', dirname(__DIR__)) . '/php/Scan/ScanService.php';
    $anchor = "'uniqueDone'     => \$u['done'],";
    check('CR incident: the anchor line is still in advanceRun()',
        isset($sources[$svcFile]) && strpos($sources[$svcFile], $anchor) !== false);
    $broken = $sources;
    $broken[$svcFile] = str_replace($anchor,
        "'windowChanges'  => \$this->aggregateTotal(\$runId, 'window-changes'),\n            " . $anchor,
        $sources[$svcFile]);
    $b = uv_cr_lint($broken, $framework);
    check('CR incident: the call the merge left in advanceRun() is found',
        count($b['unresolved']) === 1 && strpos($b['unresolved'][0], 'ScanService calls $this->aggregateTotal()') !== false);

    $probe = function ($code) use ($framework) {
        return uv_cr_lint(['probe.php' => "<?php\nnamespace P;\n" . $code], $framework);
    };
    $x = $probe('final class A { private static function s() {} public function f() { self::gone(); static::s(); self::s(); } }');
    check('CR: self:: to a missing method is found, and to a present one is not',
        count($x['unresolved']) === 1 && strpos($x['unresolved'][0], 'self::gone()') !== false);
    $x = $probe('class B { public function b() {} } class C extends B { public function c() { parent::b(); parent::c(); $this->b(); } }');
    check('CR: parent:: resolves against the parent only, and $this-> through it',
        count($x['unresolved']) === 1 && strpos($x['unresolved'][0], 'parent::c()') !== false);
    $x = $probe('trait T { public function t() { $this->host(); $this->nobody(); } } class H { use T; public function host() { $this->t(); } }');
    check('CR: a trait resolves against the classes that use it, and a class against its traits',
        count($x['unresolved']) === 1 && strpos($x['unresolved'][0], 'T calls $this->nobody()') !== false);
    $x = $probe('class D { public function __call($n, $a) {} public function d() { $this->anything(); } }');
    check('CR: a class with __call is not second-guessed', $x['unresolved'] === []);
    $x = $probe('class E extends \RuntimeException { public function e() { return $this->getMessage() . $this->nope(); } }');
    check('CR: a built-in parent is asked by reflection',
        count($x['unresolved']) === 1 && strpos($x['unresolved'][0], '$this->nope()') !== false);
    $x = $probe('class F extends \Somewhere\Unknown { public function f() { $this->x(); } }');
    check('CR: a parent outside the repository with no list fails, rather than passing everything',
        $x['problems'] !== []);
    $x = $probe('use ExternalModules\AbstractExternalModule; class G extends AbstractExternalModule { public function g() { $this->getProjectSetting("k"); $this->getProjectSettingz("k"); } }');
    check('CR: the framework parent answers to its list and nothing else',
        count($x['unresolved']) === 1 && strpos($x['unresolved'][0], 'getProjectSettingz') !== false);
    $x = $probe('class H2 { public function h() { $f = function () { return $this->missing(); }; return X::class . $this->h(); } }');
    check('CR: a closure\'s $this is the class, and X::class is not a declaration',
        count($x['unresolved']) === 1 && strpos($x['unresolved'][0], '$this->missing()') !== false
        && $x['classes'] === 1);
    $x = $probe('class I { public function I1() {} public function i() { $this->i1(); SELF::I1(); } }');
    check('CR: method names compare without case, as PHP compares them', $x['unresolved'] === []);

    echo "call_resolution_php: $n checks, $fail failure(s)\n";
    exit($fail ? 1 : 0);
}
