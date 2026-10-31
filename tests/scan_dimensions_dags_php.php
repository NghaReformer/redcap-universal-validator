<?php
/*
 * scan_dimensions_dags_php.php - what the scan report says about a project's
 * Data Access Groups (live run 2026-10-30, LIVE-D2).
 *
 * REDCap::getGroupNames() answers false for a project with no groups, and the
 * report read every non-array as a failed read: each report of pid 149, which
 * has no groups, said "Data Access Groups could not be read". Asserts:
 *   - an array of groups turns the group column on, and an empty array leaves
 *     it off, with nothing degraded;
 *   - false with the project's own Project object listing no groups is "no
 *     groups", with nothing degraded;
 *   - false with groups in that object, with a Project object of another
 *     project, with none at all, or with one that throws, is still a read
 *     that failed, and says so.
 *
 * Run:  php tests/scan_dimensions_dags_php.php
 */

namespace {
    class REDCap {
        public static $groups = false;
        public static function getGroupNames($unique = false, $groupId = null) { return self::$groups; }
    }
    class StubProject {
        public $project_id;
        public $groups;
        public function __construct($pid, $groups) { $this->project_id = $pid; $this->groups = $groups; }
        public function getGroups()
        {
            if ($this->groups instanceof \Throwable) throw $this->groups;
            return $this->groups;
        }
    }

    require_once __DIR__ . '/../php/ScanCapabilities.php';
    require_once __DIR__ . '/../php/ScanDimensions.php';

    use INSPIRE\UniversalValidator\ScanDimensions;

    $n = 0; $fail = 0;
    function check($label, $cond) {
        global $n, $fail;
        $n++;
        if (!$cond) { $fail++; fwrite(STDERR, "FAIL: $label\n"); }
    }

    $NOT_READ = 'Data Access Groups could not be read';
    $case = function ($answer, $proj) {
        REDCap::$groups = $answer;
        if ($proj === null) unset($GLOBALS['Proj']); else $GLOBALS['Proj'] = $proj;
        return ScanDimensions::build(149, [], []);
    };

    $d = $case(['north' => 'north', 'south' => 'south'], null);
    check('groups listed: the group column is on, nothing degraded', $d->hasDags === true && !isset($d->degraded['dags']));
    $d = $case([], null);
    check('an empty list is no groups, nothing degraded', $d->hasDags === false && !isset($d->degraded['dags']));

    $d = $case(false, new StubProject(149, []));
    check('D2: false, and the project lists no groups, is no groups with nothing degraded',
        $d->hasDags === false && !isset($d->degraded['dags']));

    foreach ([
        'the project lists groups' => new StubProject(149, [7 => 'north']),
        'the Project object is another project\'s' => new StubProject(150, []),
        'there is no Project object' => null,
        'the Project object cannot list groups' => new StubProject(149, null),
        'listing the groups throws' => new StubProject(149, new \RuntimeException('down')),
    ] as $why => $proj) {
        $d = $case(false, $proj);
        check('D2: false when ' . $why . ' is still a read that failed',
            $d->hasDags === false && isset($d->degraded['dags'])
            && strpos($d->degraded['dags'], $NOT_READ) === 0);
    }

    echo "scan_dimensions_dags_php: $n checks, $fail failure(s)\n";
    exit($fail ? 1 : 0);
}
