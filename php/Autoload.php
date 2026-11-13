<?php
/**
 * Loads the module's own classes when they are first used (FRM-4).
 *
 * UniversalValidator.php opened with 63 require_once lines, 3.1 MB of source.
 * REDCap builds the module object on every project page, for the sidebar link
 * alone, so every page of every project with the module on parsed all of it:
 * 158 to 224 ms and 9.8 MB on a server without OPcache, and the durable scan's
 * 29 files on pages that never scan. A class is now read when something names
 * it.
 *
 * Only this module's namespace is answered, and only from php/ and php/Scan/:
 * INSPIRE\UniversalValidator\Logic is php/Logic.php, and
 * INSPIRE\UniversalValidator\Scan\Schema is php/Scan/Schema.php. A name with
 * any other character than a letter, a digit or an underscore is not looked
 * for, so no class name can walk out of the folder. A few files declare a
 * second class beside the one they are named after; SHARED_FILES says where
 * those live, and tests/autoload_php.php fails when a class is added that
 * this cannot find.
 *
 * A class that decides an authorisation can no longer be absent when it is
 * asked, which is why the files were loaded eagerly before: a name that is
 * not loaded yet is loaded by asking.
 */

namespace INSPIRE\UniversalValidator;

final class Autoload
{
    /** The namespace this loader answers for. */
    const PREFIX = 'INSPIRE\\UniversalValidator\\';

    /** Classes declared in a file named after another class: name under PREFIX => that other class. */
    const SHARED_FILES = [
        'CallbackFindingSink'      => 'FindingSink',
        'Scan\\ModuleDb'           => 'Scan\\ScanDb',
        'Scan\\RecordVersions'     => 'Scan\\SourceFence',
        'Scan\\ChangeLog'          => 'Scan\\SourceFence',
        'Scan\\ScopeDrift'         => 'Scan\\SourceFence',
        'Scan\\DuplicateFinalizer' => 'Scan\\UniqueFinalizer',
        'Scan\\SequencedFinalizer' => 'Scan\\UniqueFinalizer',
    ];

    /** Whether register() has run in this process. */
    private static $registered = false;

    /** Register the loader once. */
    public static function register()
    {
        if (self::$registered) return;
        self::$registered = true;
        spl_autoload_register([self::class, 'load']);
    }

    /**
     * The file that declares $class, or null when the class is not this
     * module's or has no file here.
     */
    public static function fileOf($class)
    {
        $class = ltrim((string) $class, '\\');
        if (strncmp($class, self::PREFIX, strlen(self::PREFIX)) !== 0) return null;
        $name = substr($class, strlen(self::PREFIX));
        if (preg_match('/^(?:Scan\\\\)?[A-Za-z_][A-Za-z0-9_]*$/D', $name) !== 1) return null;
        if (isset(self::SHARED_FILES[$name])) $name = self::SHARED_FILES[$name];
        $file = __DIR__ . '/' . str_replace('\\', '/', $name) . '.php';
        return is_file($file) ? $file : null;
    }

    /** The spl_autoload_register() callback. */
    public static function load($class)
    {
        $file = self::fileOf($class);
        if ($file !== null) require_once $file;
    }
}
