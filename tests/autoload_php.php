<?php
/**
 * autoload_php.php - the module reads its classes when they are first used (FRM-4).
 *
 * UniversalValidator.php opened with a block of require_once lines that read
 * 63 files, 3.1 MB, on every project page, because REDCap builds the module
 * object for the sidebar link alone. php/Autoload.php now answers for the
 * module's namespace. This suite checks that:
 *
 *   - building the class reads no file of the durable scan and under half of
 *     the module's PHP;
 *   - every class, interface and trait under php/ is found by the loader, in
 *     the file that declares it, so a class added to a file named after
 *     another one fails here until Autoload::SHARED_FILES names it;
 *   - the loader answers for nothing outside php/ and php/Scan/.
 *
 * Run:  php tests/autoload_php.php
 */

namespace ExternalModules {
    if (PHP_SAPI !== 'cli') {
        // A test or a tool, never a page: answer a web request with 404 and stop.
        http_response_code(404);
        exit;
    }

    class AbstractExternalModule {}
}

namespace {
    $n = 0;
    $fail = 0;
    function check($label, $cond)
    {
        global $n, $fail;
        $n++;
        if (!$cond) { $fail++; fwrite(STDERR, "FAIL: $label\n"); }
    }

    $root = str_replace('\\', '/', dirname(__DIR__));
    $before = get_included_files();

    // ---- what building the class reads -------------------------------------
    require $root . '/UniversalValidator.php';
    $m = new \INSPIRE\UniversalValidator\UniversalValidator();
    $read = array_values(array_diff(get_included_files(), $before));
    $rel = array_map(function ($f) use ($root) { return substr(str_replace('\\', '/', $f), strlen($root) + 1); }, $read);
    $bytes = array_sum(array_map('filesize', $read));
    $all = array_merge([$root . '/UniversalValidator.php'], glob($root . '/php/*.php'), glob($root . '/php/Scan/*.php'));
    $allBytes = array_sum(array_map('filesize', $all));
    check('the class is built (' . count($read) . ' files, ' . $bytes . ' of ' . $allBytes . ' bytes)', $m instanceof \ExternalModules\AbstractExternalModule);
    check('building the class reads no file of the durable scan (got ' . json_encode(array_values(preg_grep('#^php/Scan/#', $rel))) . ')',
        preg_grep('#^php/Scan/#', $rel) === []);
    check('building the class reads under half of the module\'s PHP (got ' . $bytes . ' of ' . $allBytes . ')', $bytes * 2 < $allBytes);
    // Two setting names are written out in the class so that ScanService is not read for them.
    check('SCAN_DISABLED_AT and SCAN_ENABLED_AT are the scan service\'s',
        \INSPIRE\UniversalValidator\UniversalValidator::SCAN_DISABLED_AT === \INSPIRE\UniversalValidator\Scan\ScanService::DISABLED_AT
        && \INSPIRE\UniversalValidator\UniversalValidator::SCAN_ENABLED_AT === \INSPIRE\UniversalValidator\Scan\ScanService::ENABLED_AT);
    foreach (['php/GrowthReference.php', 'php/GrowthCurve.php', 'php/ScanPageView.php', 'php/MessageCatalog.php', 'php/ValueHistory.php'] as $f) {
        check('building the class does not read ' . $f, !in_array($f, $rel, true));
    }
    $src = file_get_contents($root . '/UniversalValidator.php');
    check('UniversalValidator.php requires the loader and nothing else',
        preg_match_all('/^\s*require(?:_once)?\b[^;]*;/m', $src, $mm) === 1 && strpos($mm[0][0], "'/php/Autoload.php'") !== false);
    check('the loader is registered before the class is declared',
        strpos($src, 'Autoload::register();') !== false
        && strpos($src, 'Autoload::register();') < strpos($src, 'class UniversalValidator extends'));

    // ---- every declaration is found ----------------------------------------
    $A = 'INSPIRE\UniversalValidator\Autoload';
    $declared = function ($file) {
        $t = token_get_all(file_get_contents($file));
        $ns = ''; $out = []; $depth = 0; $c = count($t);
        for ($i = 0; $i < $c; $i++) {
            $k = $t[$i];
            if ($k === '{' || (is_array($k) && in_array($k[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true))) { $depth++; continue; }
            if ($k === '}') { $depth--; continue; }
            if (!is_array($k)) continue;
            if ($k[0] === T_NAMESPACE) {
                $ns = '';
                for ($j = $i + 1; $j < $c && $t[$j] !== ';' && $t[$j] !== '{'; $j++) {
                    if (is_array($t[$j]) && $t[$j][0] !== T_WHITESPACE) $ns .= $t[$j][1];
                }
                continue;
            }
            if ($depth !== 0 || !in_array($k[0], [T_CLASS, T_INTERFACE, T_TRAIT], true)) continue;
            $p = $i - 1;
            while ($p > 0 && is_array($t[$p]) && $t[$p][0] === T_WHITESPACE) $p--;
            if (is_array($t[$p]) && $t[$p][0] === T_DOUBLE_COLON) continue;
            $j = $i + 1;
            while (is_array($t[$j]) && $t[$j][0] === T_WHITESPACE) $j++;
            if (is_array($t[$j]) && $t[$j][0] === T_STRING) $out[] = $ns . '\\' . $t[$j][1];
        }
        return $out;
    };
    $names = 0;
    $lost = [];
    $elsewhere = [];
    foreach (array_merge(glob($root . '/php/*.php'), glob($root . '/php/Scan/*.php')) as $f) {
        foreach ($declared($f) as $class) {
            $names++;
            $found = $A::fileOf($class);
            if ($found === null) { $lost[] = $class; continue; }
            if (realpath($found) !== realpath($f)) $elsewhere[] = $class . ' -> ' . basename($found);
        }
    }
    check('the declarations are found by the reader (' . $names . ')', $names >= 60);
    check('the loader finds every class, interface and trait under php/ (not found: ' . json_encode($lost) . ')', $lost === []);
    check('...in the file that declares it (got ' . json_encode($elsewhere) . ')', $elsewhere === []);
    foreach (array_keys($A::SHARED_FILES) as $name) {
        $f = $A::fileOf($A::PREFIX . $name);
        check('SHARED_FILES: ' . $name . ' is declared where it says',
            $f !== null && in_array($A::PREFIX . $name, $declared($f), true));
    }

    // ---- and each loads ------------------------------------------------------
    $unloadable = [];
    foreach (array_merge(glob($root . '/php/*.php'), glob($root . '/php/Scan/*.php')) as $f) {
        foreach ($declared($f) as $class) {
            if (!class_exists($class) && !interface_exists($class) && !trait_exists($class)) $unloadable[] = $class;
        }
    }
    check('naming each one loads it (got ' . json_encode($unloadable) . ')', $unloadable === []);

    // ---- nothing else is answered -------------------------------------------
    foreach ([
        'Logic', 'Other\Space\Logic', 'INSPIRE\Logic', 'INSPIRE\UniversalValidatorX\Logic',
        'INSPIRE\UniversalValidator\UniversalValidator', 'INSPIRE\UniversalValidator\NoSuchClass',
        'INSPIRE\UniversalValidator\..\UniversalValidator', 'INSPIRE\UniversalValidator\Scan\..\Logic',
        'INSPIRE\UniversalValidator\Scan\Sub\Schema', 'INSPIRE\UniversalValidator\messages\catalog',
        'INSPIRE\UniversalValidator\Scan/Schema', 'INSPIRE\UniversalValidator\Logic.php', "INSPIRE\\UniversalValidator\\Logic\n",
        'INSPIRE\UniversalValidator\\', '',
    ] as $name) {
        check('not answered: ' . json_encode($name), $A::fileOf($name) === null);
    }
    check('a leading backslash is allowed', $A::fileOf('\INSPIRE\UniversalValidator\Logic') !== null);
    check('register() twice adds one loader',
        (function () use ($A) {
            $A::register();
            $A::register();
            $mine = array_filter(spl_autoload_functions(), function ($f) use ($A) { return is_array($f) && $f[0] === $A && $f[1] === 'load'; });
            return count($mine) === 1;
        })());

    echo "autoload_php: $n checks, $fail failure(s)\n";
    exit($fail ? 1 : 0);
}
