<?php
/**
 * scan_capabilities_php.php — what an installation can support, and what a scan
 * run on it is therefore allowed to CLAIM.
 *
 * Three things the scan rebuild needs are not guaranteed to exist on a given
 * REDCap: a bounded way to walk the record list, a way to prove a record did not
 * move while it was being read, and permission to create the module's tables.
 * ScanCapabilities probes each and derives a policy from the answers.
 *
 * What this file locks:
 *
 *   C-01  every probe answers available-or-unavailable-WITH-A-REASON. There is
 *         no third answer where the module assumes, and no probe throws: a
 *         capability check that dies takes the scan with it.
 *   C-02  is_callable, never method_exists. The framework serves methods through
 *         __call(), for which method_exists() answers false — that is exactly
 *         how v1.4.0 shipped a production-inert @UVUNIQUE while every mocked
 *         test passed. Each probe is therefore driven with a proxy object.
 *   C-03  a missing capability only ever LOWERS what a run may claim. This is
 *         the property that stops "we could not check" being laundered into
 *         "there was nothing to find" (M-02), and it is asserted exhaustively
 *         over every combination of capabilities rather than by example.
 *   C-04  unbounded record enumeration STOPS a scan. The plan's words: never
 *         improvise an unbounded fallback.
 *   C-05  a table name is whitelisted by shape before it can reach SQL, because
 *         a table name can never be a bound parameter.
 *   C-06  the probes are read-only. No INSERT, UPDATE, DELETE or DDL, and no
 *         trial CREATE TABLE — a probe with side effects is not a probe.
 *
 * Run:  php tests/scan_capabilities_php.php
 */

namespace ExternalModules {
    class AbstractExternalModule {
        public $queries = [];
        /** Rows to hand back, keyed by a substring of the SQL. */
        public $canned = [];
        /** SQL substring => throw instead of answering. */
        public $throwOn = null;
        public function query($sql, $params = []) {
            $this->queries[] = [$sql, $params];
            if ($this->throwOn !== null && strpos($sql, $this->throwOn) !== false) {
                throw new \RuntimeException('simulated database failure');
            }
            foreach ($this->canned as $needle => $rows) {
                if (strpos($sql, $needle) !== false) return new FakeResult($rows);
            }
            return new FakeResult([]);
        }
    }
    class FakeResult {
        private $rows; private $i = 0;
        public function __construct($rows) { $this->rows = $rows; }
        public function fetch_row() { return isset($this->rows[$this->i]) ? $this->rows[$this->i++] : null; }
    }
    /**
     * C-02. The v1.4.0 shape: query() exists only through __call(), so
     * method_exists() answers false while is_callable() answers true. A probe
     * gated on the former would declare a perfectly capable install incapable.
     */
    class ProxyQueryModule {
        public $inner;
        public function __construct($inner) { $this->inner = $inner; }
        public function __call($name, $args) {
            if ($name === 'query') return $this->inner->query($args[0], isset($args[1]) ? $args[1] : []);
            throw new \BadMethodCallException($name);
        }
    }
    /** A framework build with no query() at all. */
    class NoQueryModule {
    }
}

namespace {
    class REDCap {
        public static $pk = 'record_id';
        public static $pkThrows = false;
        public static $hide = [];      // method names to pretend are not exposed
        public static function getRecordIdField() {
            if (self::$pkThrows) throw new \RuntimeException('simulated');
            return self::$pk;
        }
        public static function getRepeatingFormsEvents($pid = null) { return []; }
        public static function isRepeatingForm($e = null, $f = null) { return false; }
        public static function getEventNames($u = false, $x = false, $e = null) { return 'event_1_arm_1'; }
        public static function getGroupNames($u = false, $g = null) { return ''; }
        public static function getData($p) { return []; }
        public static function getDataDictionary($pid, $f = 'array') { return []; }
    }

    require_once __DIR__ . '/../php/ScanCapabilities.php';

    $n = 0; $fail = 0;
    function check($label, $cond) {
        global $n, $fail; $n++;
        if (!$cond) { $fail++; fwrite(STDERR, "FAIL: $label\n"); }
    }

    $C  = '\INSPIRE\UniversalValidator\ScanCapabilities';
    $OK = $C::OK;
    $NO = $C::NO;
    const PID = 700;

    /** A module whose probes all succeed. */
    function fullyCapable() {
        $m = new \ExternalModules\AbstractExternalModule();
        $m->canned = [
            'SHOW TABLES'  => [['redcap_record_list']],
            'log_event_table' => [['redcap_log_event7']],
            'SHOW GRANTS'  => [['GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, DROP ON `rc`.* TO `u`@`h`']],
        ];
        return $m;
    }

    /* =====================================================================
     * C-01  every probe answers, in a fixed shape, and never throws
     * ===================================================================== */
    {
        $m = fullyCapable();
        $caps = $C::all($m, PID);
        check('C-01: every capability is reported', count($caps) === 6);
        foreach ($caps as $name => $c) {
            check("C-01: $name has a state", isset($c['state']) && ($c['state'] === $OK || $c['state'] === $NO));
            check("C-01: $name explains itself",
                $c['state'] === $OK ? ($c['via'] !== null) : ($c['why'] !== null && $c['why'] !== ''));
        }

        // A database that throws on every statement must not take the scan with
        // it: each probe reports unavailable and says why.
        $t = fullyCapable();
        $t->throwOn = 'SELECT';          // the fence probe
        $caps = $C::all($t, PID);
        check('C-01: a throwing fence query degrades rather than escaping',
            $caps['sourceFence']['state'] === $NO && strpos($caps['sourceFence']['why'], 'log-event') !== false);

        $t2 = fullyCapable();
        $t2->throwOn = 'SHOW GRANTS';
        $caps2 = $C::all($t2, PID);
        check('C-01: a throwing grant query degrades rather than escaping',
            $caps2['schemaPrivilege']['state'] === $NO);

        \REDCap::$pkThrows = true;
        $noList = fullyCapable();
        $noList->canned['SHOW TABLES'] = [];       // no redcap_record_list
        $caps3 = $C::all($noList, PID);
        \REDCap::$pkThrows = false;
        check('C-01: a throwing record-id lookup degrades rather than escaping',
            $caps3['recordEnumeration']['state'] === $NO);
    }

    /* =====================================================================
     * C-02  is_callable, never method_exists
     * ===================================================================== */
    {
        $inner = fullyCapable();
        $proxy = new \ExternalModules\ProxyQueryModule($inner);
        check('C-02: method_exists() genuinely cannot see the proxied query()',
            method_exists($proxy, 'query') === false);
        check('C-02: a __call-proxied query() is still detected as usable',
            $C::recordEnumeration($proxy, PID)['state'] === $OK);
        check('C-02: and the fence probe works through the proxy too',
            $C::sourceFence($proxy, PID)['state'] === $OK);
        check('C-02: and the grant probe works through the proxy too',
            $C::schemaPrivilege($proxy)['state'] === $OK);

        // CONTRAST: a build with genuinely no query() is reported unavailable,
        // not quietly treated as capable.
        $none = new \ExternalModules\NoQueryModule();
        $r = $C::recordEnumeration($none, PID);
        check('C-02 contrast: a build with no query() is reported unavailable',
            $r['state'] === $NO && strpos($r['why'], 'query()') !== false);
        check('C-02 contrast: and so is its fence',
            $C::sourceFence($none, PID)['state'] === $NO);
    }

    /* =====================================================================
     * C-03  a missing capability only ever LOWERS the claim
     *
     * Asserted over every combination rather than by example: 64 subsets, each
     * compared against the fully-capable policy. This is the invariant that
     * stops "not checked" becoming "nothing found".
     * ===================================================================== */
    {
        $names = ['recordEnumeration', 'sourceFence', 'schemaPrivilege',
                  'repeatMetadata', 'eventNames', 'dagNames'];
        $rank = ['partial' => 0, 'manifest-complete' => 1, 'complete-through-fence' => 2];

        $mk = function (array $present) use ($names) {
            $caps = [];
            foreach ($names as $nm) {
                $caps[$nm] = in_array($nm, $present, true)
                    ? ['state' => \INSPIRE\UniversalValidator\ScanCapabilities::OK, 'via' => 'x', 'why' => null]
                    : ['state' => \INSPIRE\UniversalValidator\ScanCapabilities::NO, 'via' => null, 'why' => 'absent'];
            }
            return $caps;
        };

        $best = $C::policy($mk($names));
        check('C-03: a fully capable install may scan', $best['mayScan'] === true);
        check('C-03: and may claim completion through a fence',
            $best['maxCompletion'] === 'complete-through-fence');
        check('C-03: and may run incrementally', $best['incremental'] === true);
        check('C-03: and reports no limitations', $best['limits'] === []);

        $violations = 0; $subsets = 0;
        for ($mask = 0; $mask < (1 << count($names)); $mask++) {
            $present = [];
            foreach ($names as $bit => $nm) if ($mask & (1 << $bit)) $present[] = $nm;
            $p = $C::policy($mk($present));
            $subsets++;
            // Never higher than the best possible.
            if ($rank[$p['maxCompletion']] > $rank[$best['maxCompletion']]) $violations++;
            if ($p['incremental'] && !$best['incremental']) $violations++;
            // Dropping any one capability from this subset must not RAISE it.
            foreach ($present as $drop) {
                $less = array_values(array_diff($present, [$drop]));
                $q = $C::policy($mk($less));
                if ($rank[$q['maxCompletion']] > $rank[$p['maxCompletion']]) $violations++;
                if ($q['incremental'] && !$p['incremental']) $violations++;
                if ($q['mayScan'] && !$p['mayScan']) $violations++;
            }
        }
        check('C-03: all 64 capability subsets were exercised', $subsets === 64);
        check('C-03: removing a capability NEVER raises what a run may claim', $violations === 0);

        // The specific consequences, named so a reword has to be deliberate.
        $noFence = $mk(array_diff($names, ['sourceFence']));
        check('C-03: without a fence, completion is capped at manifest-complete',
            $C::policy($noFence)['maxCompletion'] === 'manifest-complete');
        check('C-03: and incremental mode is refused',
            $C::policy($noFence)['incremental'] === false);
        check('C-03: and the limitation is stated, not merely applied',
            (bool) array_filter($C::policy($noFence)['limits'], function ($s) {
                return strpos($s, 'proved unchanged') !== false;
            }));
        $noSchema = $mk(array_diff($names, ['schemaPrivilege']));
        check('C-03: no CREATE grant does not stop a scan, it is reported',
            $C::policy($noSchema)['mayScan'] === true
            && (bool) array_filter($C::policy($noSchema)['limits'], function ($s) {
                return strpos($s, 'administrator') !== false;
            }));
    }

    /* =====================================================================
     * C-04  unbounded enumeration stops the scan
     * ===================================================================== */
    {
        $names = ['sourceFence', 'schemaPrivilege', 'repeatMetadata', 'eventNames', 'dagNames'];
        $caps = ['recordEnumeration' => ['state' => $NO, 'via' => null, 'why' => 'no bounded source']];
        foreach ($names as $nm) $caps[$nm] = ['state' => $OK, 'via' => 'x', 'why' => null];
        $p = $C::policy($caps);
        check('C-04: without bounded enumeration a scan may NOT run', $p['mayScan'] === false);
        check('C-04: and nothing else it can do raises the claim',
            $p['maxCompletion'] === 'partial' && $p['incremental'] === false);
        check('C-04: and the reason names the record list',
            (bool) array_filter($p['limits'], function ($s) {
                return strpos($s, 'record list') !== false;
            }));

        // The keyset fallback IS bounded, so it must be accepted rather than
        // treated as absence — refusing every install without redcap_record_list
        // would be the opposite failure.
        $m = fullyCapable();
        $m->canned['SHOW TABLES'] = [];
        $r = $C::recordEnumeration($m, PID);
        check('C-04: a keyset walk counts as bounded enumeration',
            $r['state'] === $OK && strpos($r['via'], 'keyset') !== false);
        check('C-04: but only when the record-id field is known',
            (function () use ($C, $m) {
                \REDCap::$pk = '';
                $x = $C::recordEnumeration($m, PID);
                \REDCap::$pk = 'record_id';
                return $x['state'] === \INSPIRE\UniversalValidator\ScanCapabilities::NO;
            })());
    }

    /* =====================================================================
     * C-05  a table name is whitelisted by shape before it reaches SQL
     * ===================================================================== */
    {
        $m = fullyCapable();
        // A shard name is legitimate...
        $m->canned['log_event_table'] = [['redcap_log_event12']];
        check('C-05: a sharded log table is accepted',
            $C::sourceFence($m, PID)['via'] === 'redcap_log_event12');
        // ...anything else is not, however it got there.
        // An INTERNAL newline is the one that matters: a purely trailing one is
        // removed by trim() and the remaining name is legitimate, but a newline
        // in the middle survives trimming and would sail past a '$' anchor,
        // which is exactly why the pattern ends in \z.
        // The TRAILING-newline case is the one that discriminates a correct
        // pattern from a subtly wrong one: PHP's '$' matches immediately before
        // a trailing newline, so a pattern anchored with '$' accepts
        // "redcap_log_event\n" — and this value is interpolated into SQL,
        // because a table name can never be a bound parameter. Only \z refuses
        // it. Mutating the anchor back to '$' must turn this check red.
        foreach (['redcap_log_event; DROP TABLE x', 'users', '', 'redcap_log_event-1',
                  "redcap_log_event\n", "redcap_log_event\nDROP",
                  "redcap_log_event\n; DROP TABLE x", ' redcap_log_event',
                  'redcap_log_event7 ; DROP', 'REDCAP_LOG_EVENT'] as $bad) {
            $m2 = fullyCapable();
            $m2->canned['log_event_table'] = [[$bad]];
            check('C-05: a log table named ' . json_encode($bad) . ' is refused',
                $C::sourceFence($m2, PID)['state'] === $NO);
        }
    }

    /* =====================================================================
     * C-06  the probes are read-only
     * ===================================================================== */
    {
        $m = fullyCapable();
        $C::all($m, PID);
        $bad = 0;
        foreach ($m->queries as $q) {
            if (preg_match('~\b(INSERT|UPDATE|DELETE|CREATE|DROP|ALTER|TRUNCATE|REPLACE)\b~i', $q[0])) $bad++;
        }
        check('C-06: probing issues no write or DDL statement', $bad === 0);
        check('C-06: and it did issue the reads it claims to',
            count($m->queries) >= 3);
        // Strip comments first: this file EXPLAINS why it avoids method_exists
        // and a trial CREATE TABLE, so scanning the raw text would match its own
        // reasoning and pass or fail for the wrong reason.
        $src  = file_get_contents(__DIR__ . '/../php/ScanCapabilities.php');
        $code = preg_replace(['~/\*.*?\*/~s', '~//.*~'], '', $src);
        check('C-06: the CODE contains no trial CREATE TABLE',
            stripos($code, 'CREATE TABLE') === false);
        check('C-06: and probes with is_callable, never method_exists',
            strpos($code, 'method_exists') === false && strpos($code, 'is_callable') !== false);
        check('C-06: the comment-stripper actually stripped something',
            strlen($code) < strlen($src) - 500);
    }

    echo "scan_capabilities_php: $n checks, $fail failure(s)\n";
    exit($fail ? 1 : 0);
}
