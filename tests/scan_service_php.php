<?php
/**
 * scan_service_php.php — the gate in front of the durable scan.
 *
 * ScanService is the composition root: it builds fourteen classes over the
 * framework and exposes four verbs. Most of what it does needs a database and
 * is checked in the matrix. What is checked HERE is the part that decides
 * whether any of it runs at all, because that decision is made before any
 * database is touched and it is the one this release depends on:
 *
 *   BOTH FLAGS OFF IS THE DEFAULT, and the plan requires a real-server pilot
 *   before either is turned on. A flag that defaults on is not a flag.
 *
 *   A REFUSAL MUST NOT DESCRIBE THE INSTALLATION. Someone who has just been
 *   told the answer is no should not learn from the same sentence whether the
 *   tables exist, which database user the module runs as, or what a class here
 *   is called.
 *
 * Run:  php tests/scan_service_php.php
 */

namespace {
    require_once __DIR__ . '/../php/ScanPageView.php';
    require_once __DIR__ . '/../php/ScanCapabilities.php';
    require_once __DIR__ . '/../php/ScanDimensions.php';
    require_once __DIR__ . '/../php/MessageCatalog.php';
    require_once __DIR__ . '/../php/ScanColumns.php';
    require_once __DIR__ . '/../php/Scan/ScanReport.php';
    require_once __DIR__ . '/../php/Scan/Schema.php';
    require_once __DIR__ . '/../php/Scan/ScanDb.php';
    require_once __DIR__ . '/../php/Scan/DbError.php';
    require_once __DIR__ . '/../php/Scan/ScanStore.php';
    require_once __DIR__ . '/../php/Scan/ArrayScanStore.php';
    require_once __DIR__ . '/../php/Scan/FindingRowMapper.php';
    require_once __DIR__ . '/../php/Scan/ScanOutcome.php';
    require_once __DIR__ . '/../php/Scan/ScanPhase.php';
    require_once __DIR__ . '/../php/Scan/ScanPolicy.php';
    require_once __DIR__ . '/../php/Scan/ScanAuthorization.php';
    require_once __DIR__ . '/../php/Scan/Hmac.php';
    require_once __DIR__ . '/../php/Scan/ReasonCode.php';
    require_once __DIR__ . '/../php/Scan/SqlScanStore.php';
    require_once __DIR__ . '/../php/Scan/WorkerSlots.php';
    require_once __DIR__ . '/../php/Scan/ScanRetention.php';
    require_once __DIR__ . '/../php/Scan/RecordManifestSource.php';
    require_once __DIR__ . '/../php/Scan/SourceFence.php';
    require_once __DIR__ . '/../php/Scan/ScanPlanner.php';
    require_once __DIR__ . '/../php/Scan/WorkBudget.php';
    require_once __DIR__ . '/../php/Scan/UniqueFinalizer.php';
    require_once __DIR__ . '/../php/Scan/CatchUp.php';
    require_once __DIR__ . '/../php/Scan/RollupBuilder.php';
    require_once __DIR__ . '/../php/Scan/ScanPromotion.php';
    require_once __DIR__ . '/../php/Scan/ScanWorker.php';
    require_once __DIR__ . '/../php/Scan/ScanStoreUnavailable.php';
    require_once __DIR__ . '/../php/Scan/ScanService.php';
    require_once __DIR__ . '/../php/Scan/ScanMaintenance.php';
    // The finalizer's re-read compares values as Logic::lookupKey (W7-R4 block).
    require_once __DIR__ . '/../php/Logic.php';
    require_once __DIR__ . '/scan_fault_support.php';

    $n = 0; $fail = 0;
    function check($label, $cond) {
        global $n, $fail; $n++;
        if (!$cond) { $fail++; fwrite(STDERR, "FAIL: $label\n"); }
    }

    /**
     * A uniqueness phase with nothing to decide. The worker refuses to pass the
     * phase without a finalizer at all, and the real one needs a database; the
     * runs below are about what happens to records, not to duplicate groups.
     */
    class SvcNoDuplicates implements \INSPIRE\UniversalValidator\Scan\DuplicateFinalizer
    {
        public function step($generationId, $limit = 500, $readLimit = null)
        {
            return ['done' => true, 'groups' => 0, 'verified' => 0, 'emitted' => 0,
                    'published' => 0, 'collisions' => 0, 'read' => 0, 'why' => null];
        }
    }

    /**
     * A module with settings and, optionally, a database.
     *
     * No query() at all is a real REDCap build: the framework has not always
     * given modules their own handle, and a module that assumed one would fatal
     * on the page rather than explain itself.
     */
    class FakeModule
    {
        public $sys = [];
        public $proj = [];
        public $tables = [];       // which of our tables "exist"
        public $version = null;    // schema_version answer, null = table missing
        public $queries = [];
        private $hasQuery;

        public function __construct($hasQuery = true) { $this->hasQuery = $hasQuery; }

        public function getSystemSetting($k)
        {
            return isset($this->sys[$k]) ? $this->sys[$k] : null;
        }
        public function getProjectSetting($k, $pid = null)
        {
            return isset($this->proj[$k]) ? $this->proj[$k] : null;
        }
        public function query($sql, $params = [])
        {
            if (!$this->hasQuery) throw new \Exception('no query');
            $this->queries[] = $sql;
            if (strpos($sql, 'MAX(version)') !== false) {
                if ($this->version === null) throw new \Exception("Table doesn't exist");
                return [[$this->version]];
            }
            // Schema::health() asks about every table in one read (M11) and is
            // answered with the ones that exist; a capability probe still asks
            // COUNT(*) of one.
            if (strpos($sql, 'SELECT table_name FROM information_schema.tables') === 0) {
                $out = [];
                foreach ($params as $t) if (in_array($t, $this->tables, true)) $out[] = [$t];
                return $out;
            }
            if (strpos($sql, 'information_schema.tables') !== false) {
                $t = isset($params[0]) ? $params[0] : '';
                return [[in_array($t, $this->tables, true) ? 1 : 0]];
            }
            return [];
        }
    }


    /**
     * A module healthy enough to reach the store, and a database that fails.
     *
     * The two halves matter equally. Every earlier check in this file stops at
     * available(), so nothing here had ever driven a verb past its gate; the
     * fixture below passes the gate and then breaks the ONE statement the test
     * names, which is how a storage failure arrives in production - not as a
     * broken installation, but as a working one whose server stopped answering
     * halfway through a request.
     */
    class StorageFaultModule extends FakeModule
    {
        /** @var ?string statements containing this fail */
        public $failOn = null;
        /** @var array list of [event, context] */
        public $logs = [];

        public function query($sql, $params = [])
        {
            if ($this->failOn !== null && strpos($sql, $this->failOn) !== false) {
                throw new \INSPIRE\UniversalValidator\Scan\ScanStoreUnavailable(
                    '[1213] Deadlock found when trying to get lock; the work was rolled back');
            }
            // The record index answers for this project, so the capability gate
            // passes and the refusal that follows is the one under test.
            if (strpos($sql, 'FROM redcap_record_list') !== false) return [['R1']];
            return parent::query($sql, $params);
        }

        public function log($event, $context = [])
        {
            $this->logs[] = [$event, $context];
            return 1;
        }

        /** Everything logged under one event name, as a flat list of contexts. */
        public function logged($event)
        {
            $out = [];
            foreach ($this->logs as $l) {
                if ($l[0] === $event) $out[] = $l[1];
            }
            return $out;
        }

        /**
         * A reader with design rights. Since L8 every verb decides the reader
         * before it reads the run row, so a fixture with no user is refused
         * before the store it means to break is reached.
         */
        public function getUser()
        {
            $u = new \VerbUser();
            $u->name = 'alice';
            return $u;
        }
    }

    // A build with no query() method at all. is_callable must answer false, so
    // it is a separate class rather than a flag on the one above.
    class NoDbModule
    {
        public $sys = [];
        public $proj = [];
        public function getSystemSetting($k) { return isset($this->sys[$k]) ? $this->sys[$k] : null; }
        public function getProjectSetting($k, $pid = null) { return isset($this->proj[$k]) ? $this->proj[$k] : null; }
    }

    /**
     * A ScanDb serving redcap_record_list for one project, and nothing else.
     *
     * DISPATCHED ON THE SQL, because the point of this fixture is that the REAL
     * RecordManifestSource issues the queries. A hand-written source would
     * prove the fake agrees with the planner.
     *
     * THREE RECORDS IN TWO GROUPS, and the middle one is elsewhere: 7, 31, 7.
     * The assertion that matters is appended === 2, which no single-group
     * fixture could distinguish from a filter that matched everything.
     */
    class FakeSourceDb implements \INSPIRE\UniversalValidator\Scan\ScanDb
    {
        public $rows = [['R001', '7'], ['R002', '31'], ['R003', '7']];

        public function select($sql, array $params = [])
        {
            if (strpos($sql, 'information_schema.columns') !== false) {
                if (isset($params[0]) && $params[0] === 'redcap_record_list') {
                    return [['project_id'], ['record'], ['dag_id']];
                }
                // The data table exists, because exist() confirms an absence
                // from the record-list cache against it before it will call a
                // record gone (review CL-1). It holds what the index holds.
                if (isset($params[0]) && $params[0] === 'redcap_data') {
                    return [['project_id'], ['record'], ['field_name']];
                }
                return [];
            }
            if (strpos($sql, 'redcap_projects') !== false) return [];
            if (strpos($sql, 'FROM redcap_data') !== false && strpos($sql, ' IN (') !== false) {
                $asked = array_map('strval', array_slice($params, 1));
                $out = [];
                foreach ($this->rows as $r) if (in_array($r[0], $asked, true)) $out[] = [$r[0]];
                return $out;
            }
            if (strpos($sql, 'FROM redcap_record_list') === false) return [];

            // boundaryGroup(): every id equal to one id.
            if (strpos($sql, 'record = ?') !== false) {
                $want = (string) $params[count($params) - 1];
                $out = [];
                foreach ($this->rows as $r) if ($r[0] === $want) $out[] = [$r[0]];
                return $out;
            }
            // exist(): which of these ids the project still holds. Every
            // parameter after the project id is one of the ids asked about.
            // dagsOf() and scopeOf() ask the same rows for their group as
            // well, and get it: this answered the id alone, so every record
            // read as in no group (review rv5b-1 made that answer matter).
            if (strpos($sql, ' IN (') !== false) {
                $asked = array_map('strval', array_slice($params, 1));
                $withDag = strpos($sql, 'dag_id') !== false;
                $out = [];
                foreach ($this->rows as $r) {
                    if (in_array($r[0], $asked, true)) $out[] = $withDag ? [$r[0], $r[1]] : [$r[0]];
                }
                return $out;
            }
            $wantsDag = strpos($sql, 'dag_id') !== false;
            $after = null;
            if (strpos($sql, 'record >= ?') !== false) $after = (string) $params[1];
            $out = [];
            foreach ($this->rows as $r) {
                if ($after !== null && strcmp($r[0], $after) < 0) continue;
                $out[] = $wantsDag ? [$r[0], $r[1]] : [$r[0]];
            }
            if (preg_match('/LIMIT (\d+)/', $sql, $m)) $out = array_slice($out, 0, (int) $m[1]);
            return $out;
        }

        public function exec($sql, array $params = []) { return 0; }
        public function execNoCount($sql, array $params = []) {}
        public function affected() { return 0; }
        public function begin() {}
        public function commit() {}
        public function rollback() {}
    }

    /**
     * The record index, slow on its first real page and able to fail every
     * page after. `slowFor` seconds is spent once, inside the first page
     * SELECT (not open()'s one-row probe); `broken` makes every page SELECT
     * throw, as a server that cannot answer the walk does.
     */
    class MoodySourceDb extends FakeSourceDb
    {
        public $slowFor = 0.0;
        public $broken = false;
        public $pages = 0;
        /** @var ?callable(int $page): void, called as each page is asked for */
        public $onPage = null;

        public function select($sql, array $params = [])
        {
            $page = strpos($sql, 'FROM redcap_record_list') !== false && strpos($sql, 'ORDER BY') !== false
                    && !preg_match('/LIMIT 1\s*$/', $sql);
            if ($page) {
                $this->pages++;
                if (is_callable($this->onPage)) call_user_func($this->onPage, $this->pages);
                if ($this->broken) throw new \RuntimeException('the record list could not be read');
                if ($this->slowFor > 0) { usleep((int) ($this->slowFor * 1e6)); $this->slowFor = 0.0; }
            }
            return parent::select($sql, $params);
        }
    }

    /**
     * One installation slot, for WorkerSlots to lease and renew. Renewal
     * number `loseAt` finds the slot taken by somebody else.
     */
    class SlotCounterDb implements \INSPIRE\UniversalValidator\Scan\ScanDb
    {
        public $renewals = 0;
        public $loseAt = PHP_INT_MAX;
        private $lost = false;
        private $aff = 0;

        public function select($sql, array $params = [])
        {
            if (strpos($sql, 'SELECT slot_no, epoch') !== false) return [[1, 0]];
            if (strpos(ltrim($sql), 'SELECT 1') === 0) return $this->lost ? [] : [[1]];
            if (strpos($sql, 'COUNT(*)') !== false) return [[1, 0]];
            return [];
        }
        public function exec($sql, array $params = [])
        {
            $this->aff = 1;
            if (strpos($sql, 'SET expires_at = ? WHERE slot_no') !== false) {
                $this->renewals++;
                if ($this->renewals >= $this->loseAt) { $this->lost = true; $this->aff = 0; }
            }
        }
        public function execNoCount($sql, array $params = []) {}
        public function affected() { return $this->aff; }
        public function begin() {}
        public function commit() {}
        public function rollback() {}
    }

    /**
     * A module whose database is the record index FakeSourceDb serves, and
     * nothing else: no change log, so no fence, and no scan tables.
     *
     * Through the module's query(), because that is how ScanService reaches a
     * database - it wraps the module in ModuleDb - so the record source the
     * service opens for its existence probe is the real one, over this.
     */
    class SourceModule
    {
        public $src;
        public function __construct() { $this->src = new FakeSourceDb(); }
        public function query($sql, $params = [])
        {
            if (strpos($sql, 'information_schema.columns') !== false
                    || strpos($sql, 'redcap_record_list') !== false
                    || strpos($sql, 'redcap_projects') !== false) {
                return $this->src->select($sql, $params);
            }
            return [];
        }
    }

    /** A module whose user is a designer confined to group 7. */
    class DagUserModule
    {
        public function getUser() { return new \DagUser(); }
    }

    /**
     * AN OBJECT, NOT AN ARRAY. scanScope() calls hasDesignRights() and
     * getRights($pid) on whatever getUser() returns, through is_callable - so a
     * fixture that handed back an array would exercise the refusal path and
     * prove nothing about the scope.
     */
    class DagUser
    {
        public function hasDesignRights() { return true; }
        public function getRights($pid = null)
        {
            return ['design' => true, 'data_export_tool' => '1', 'group_id' => 7,
                    'forms' => ['fa' => '1']];
        }
    }

    /** Only what dagNameOf() reads. */
    class REDCap
    {
        public static $groupNames = [];
        public static function getGroupNames($unique = false, $groupId = null)
        {
            if ($groupId === null) return self::$groupNames;
            return isset(self::$groupNames[(int) $groupId]) ? self::$groupNames[(int) $groupId] : '';
        }
    }

    /** A designer with full export and the one instrument the rules live on. */
    class VerbUser
    {
        public $rights = ['design' => true, 'data_export_tool' => '1', 'group_id' => null,
                          'forms' => ['fa' => '1']];
        public $design = true;
        /** @var ?string who this is; the request memo keys on it */
        public $name = null;
        public function hasDesignRights() { return $this->design; }
        public function getRights($pid = null) { return $this->rights; }
        public function getUsername() { return $this->name; }
    }

    /**
     * A module that gets status() and cancel() PAST THEIR GATES, over the real
     * SqlScanStore (reviews R2O-3, R2O-4, R2O-5).
     *
     * status() and cancel() are reachable only through a user, an entitlement
     * and a database, which is why what they ANSWER had no test here: the
     * flags and the stall readings were all asked of static helpers. This
     * serves the handful of statements the two verbs issue, dispatched on the
     * SQL as FakeSourceDb is, so the store, the entitlement and the
     * authorisation that run are the real ones.
     *
     * THE RUN ROW IS A PLAIN ARRAY the test sets, so a clock can be put hours
     * in the past - which no store that stamps its own times allows. cancel()
     * and finish() are applied to it, the way the real statements would, and
     * every write is kept in $writes so a test can see which ones were sent.
     */
    class VerbModule
    {
        public $sys = [];
        public $proj = [];
        /** @var ?array keyed by ScanStore::RUN_COLUMNS; null is "no such run" */
        public $runRow = null;
        public $lease = null;
        public $census = [];
        /** @var mixed a VerbUser, or 'throw' */
        public $user;
        /** @var array what durableScanContext() answers */
        public $ctx;
        public $writes = [];
        private $count = 0;

        public function __construct()
        {
            $this->user = new \VerbUser();
            $this->ctx = ['ok' => true, 'why' => null, 'rules' => [], 'structure' => [],
                          'ownership' => ['a_val' => 'fa'],
                          'plan' => ['hostFields' => ['a_val' => ['fa' => true]]]];
            $this->sys = [\INSPIRE\UniversalValidator\Scan\ScanService::SYS_FLAG => '1'];
            $this->proj = [\INSPIRE\UniversalValidator\Scan\ScanService::PROJ_FLAG => '1'];
        }

        public function getUser()
        {
            if ($this->user === 'throw') throw new \RuntimeException('rights table unreadable');
            return $this->user;
        }
        public function durableScanContext($pid, array $opts = [], $dag = null) { return $this->ctx; }
        /** @var mixed what crossLookupRefusals() answers, or 'throw' */
        public $refusals = [];
        /** @var array the lookups each crossLookupRefusals() call was asked about */
        public $asked = [];
        public function crossLookupRefusals($pid, array $lookups)
        {
            $this->asked[] = $lookups;
            if ($this->refusals === 'throw') throw new \RuntimeException('rights table unreadable');
            return $this->refusals;
        }
        public function getSystemSetting($k) { return isset($this->sys[$k]) ? $this->sys[$k] : null; }
        public function getProjectSetting($k, $pid = null) { return isset($this->proj[$k]) ? $this->proj[$k] : null; }
        public function log($event, $context = []) {}

        /** A run row with every RUN_COLUMN, these values over plain defaults. */
        public static function row(array $over)
        {
            $row = [];
            foreach (\INSPIRE\UniversalValidator\Scan\ScanStore::RUN_COLUMNS as $k) $row[$k] = null;
            return array_merge($row, ['run_id' => 77, 'project_id' => 149, 'phase' => 'scanning',
                'fingerprint' => 'fp', 'manifest_total' => 10, 'manifest_done' => 3,
                'cursor_ordinal' => 3, 'lease_epoch' => 1, 'generation_id' => 5, 'run_seq' => 5,
                'detail_rows' => 0, 'detail_bytes' => 0, 'policy_revision' => 1,
                'values_state' => 'none', 'coverage' => 'partial', 'detail' => 'complete',
                'policy_json' => '{"crossLookups":[]}',
                'created_at' => gmdate('Y-m-d H:i:s'), 'updated_at' => gmdate('Y-m-d H:i:s')], $over);
        }

        public function query($sql, $params = [])
        {
            if (strpos($sql, 'SELECT ROW_COUNT()') === 0) return [[$this->count]];
            $this->count = 0;
            if (strpos($sql, 'SELECT run_id, project_id, scope_dag') === 0) {
                if ($this->runRow === null || (int) $params[0] !== (int) $this->runRow['run_id']
                        || (int) $params[1] !== (int) $this->runRow['project_id']) return [];
                $out = [];
                foreach (\INSPIRE\UniversalValidator\Scan\ScanStore::RUN_COLUMNS as $k) {
                    $out[] = $this->runRow[$k];
                }
                return [$out];
            }
            if (strpos($sql, 'SELECT lease_expires_at') === 0) {
                return $this->lease === null ? [] : [[$this->lease]];
            }
            if (strpos($sql, 'SELECT state, COUNT(*)') === 0) {
                $out = [];
                foreach ($this->census as $s => $c) $out[] = [$s, $c];
                return $out;
            }
            if (strpos($sql, 'SELECT EXISTS(SELECT 1') === 0) return [[1]];
            if (strpos(ltrim($sql), 'UPDATE') === 0 || strpos(ltrim($sql), 'INSERT') === 0) {
                $this->writes[] = [preg_replace('/\s+/', ' ', trim($sql)), $params];
                $live = $this->runRow !== null && empty($this->runRow['terminal']);
                if (strpos($sql, 'SET cancel_requested_at') !== false && $live) {
                    $this->runRow['cancel_requested_at'] = $params[0];
                    $this->runRow['phase'] = 'cancelling';
                    $this->runRow['lease_epoch'] = (int) $this->runRow['lease_epoch'] + 1;
                    $this->count = 1;
                } elseif (strpos($sql, 'SET phase = ?, terminal = ?') !== false && $live) {
                    $this->runRow['phase'] = $params[0];
                    $this->runRow['terminal'] = $params[1];
                    $this->runRow['coverage'] = $params[2];
                    $this->count = 1;
                }
                return true;
            }
            return [];
        }
    }

    /**
     * VerbModule on an installation that also passes available(), with a
     * count of everything asked (P1, M11, M12).
     *
     * The work a request does is observed from OUTSIDE the service - the
     * statements that reach the module and the plans it is asked for - rather
     * than through a counter the service keeps for a test, so what is counted
     * is what production would send.
     */
    class CountingVerbModule extends VerbModule
    {
        /** @var string[] every statement */
        public $log = [];
        /** @var int how many times a plan was asked for */
        public $plans = 0;
        /** @var array the options the last plan was asked with */
        public $lastOpts = [];

        public function durableScanContext($pid, array $opts = [], $dag = null)
        {
            $this->plans++;
            $this->lastOpts = $opts;
            return parent::durableScanContext($pid, $opts, $dag);
        }

        public function query($sql, $params = [])
        {
            $this->log[] = $sql;
            if (strpos($sql, 'MAX(version)') !== false) {
                return [[(string) \INSPIRE\UniversalValidator\Scan\Schema::VERSION]];
            }
            if (strpos($sql, 'SELECT table_name FROM information_schema.tables') === 0) {
                return array_map(function ($t) { return [$t]; }, $params);
            }
            if (strpos($sql, 'information_schema.tables') !== false) return [[1]];
            if (strpos($sql, 'FROM redcap_record_list') !== false) return [['R1']];
            return parent::query($sql, $params);
        }

        /** How many statements so far contain $needle. */
        public function sent($needle)
        {
            $n = 0;
            foreach ($this->log as $s) if (strpos($s, $needle) !== false) $n++;
            return $n;
        }
    }

    /**
     * FaultyStore whose run row reads with a different fingerprint from the
     * $flipAt-th read after arm() (B7). advanceRun() reads the run once and
     * compares; the worker it builds reads it again. Flipping the second read
     * is the only way to see whether the worker's own guard was armed.
     */
    class FlipFingerprintStore extends \INSPIRE\UniversalValidator\Scan\FaultyStore
    {
        /** @var ?int */
        private $flipAt = null;
        private $reads = 0;

        public function arm($at)
        {
            $this->reads = 0;
            $this->flipAt = (int) $at;
        }

        public function run($pid, $runId)
        {
            $r = parent::run($pid, $runId);
            $this->reads++;
            if ($r !== null && $this->flipAt !== null && $this->reads >= $this->flipAt) {
                $r['fingerprint'] = str_repeat('f', 64);
            }
            return $r;
        }
    }

    /**
     * FaultyStore, plus one thing to happen just before the first finish():
     * another tab's Stop landing between this one's bump and its finish
     * (review R3U-5).
     */
    class RacingStore extends \INSPIRE\UniversalValidator\Scan\FaultyStore
    {
        /** @var ?callable */
        public $beforeFinish = null;

        public function finish($runId, array $outcome)
        {
            if ($this->beforeFinish !== null) {
                $h = $this->beforeFinish;
                $this->beforeFinish = null;
                $h();
            }
            return parent::finish($runId, $outcome);
        }
    }

    /**
     * A whole installation under ScanService, with ArrayScanStore as the store
     * (the merge guard).
     *
     * WHY THIS EXISTS. The merge of main left advanceRun() calling
     * aggregateTotal(), a method the scan chain had deleted, so every work
     * request that reached promotion died with an Error - and nothing in the
     * fast suite or the database harness drove a work request that far. Every
     * other fixture here stops at a gate, or builds the worker by hand. This one
     * serves every statement the four verbs send outside the store, so start(),
     * work() and status() run end to end over the REAL planner, worker,
     * catch-up, uniqueness finalizer, summary builder and promotion:
     *
     *   the record index   FakeSourceDb's three-statement index, over $records
     *   the change log     one entry per record before the run (ids 1..100) and
     *                      nothing during it, so a run can prove its window
     *   the worker slots   one slot, always free, with a real compare-and-set
     *   the summary        RollupBuilder's page of active findings, answered
     *                      from the store's own keyset findings()
     *   duplicate groups   none: no rule here is a uniqueness rule
     *   everything else    no rows, which is what an empty installation says
     *
     * The rule is one required field, a_val on instrument fa. $flagged records
     * hold a blank and get a finding; $broken records are read and cannot be
     * examined (the evaluator answers `why`); $problems is what the plan says it
     * cannot evaluate.
     */
    class E2eModule
    {
        public $sys = [];
        public $proj = [];
        public $user;
        /** @var \INSPIRE\UniversalValidator\Scan\ArrayScanStore */
        public $store;
        /** record ids in the project */
        public $records = ['R001', 'R002', 'R003'];
        /** record id => true: a blank required field, so a finding */
        public $flagged = [];
        /** record id => true: read, and not examinable */
        public $broken = [];
        /** rule problems the plan reports */
        public $problems = [];
        /** @var bool whether the context asks the service to compare fingerprints */
        public $verifyFingerprint = true;
        /** @var array the rule list durableScanContext() reports (B7: a test may edit it) */
        public $rules = [['type' => 'required', 'fields' => ['a_val']]];
        /** @var array the project's design as durableScanContext() reports it (M8) */
        public $structure = [];
        /** @var array the choice sets it reports beside it */
        public $choices = [];
        /** @var array the lookups into other projects the plan says the rules make */
        public $crossLookups = [];
        /** @var array pid => true: the other project refuses the reader, by decision */
        public $refusedBy = [];
        /**
         * @var ?int[] what the reader's plan says crossRefused is; null is the
         * module's own answer, the lookups' projects that refuse the reader
         */
        public $planRefused = null;
        /** @var int how many contexts were built: the cron test asks for none */
        public $contextsBuilt = 0;
        /** @var int[] the part of crossRefused that rests on a failed read */
        public $planUnread = [];
        /** @var ?string what the run's lease reads as, or null for no lease */
        public $lease = null;
        /** @var int how many reads of the change log's top fail, as a blip does (H11) */
        public $logFails = 0;
        /** @var string[] the configuration reads durableScanContext() says failed (R5-4) */
        public $unread = [];
        /** @var array record id => the group a finding on it stores (dag_key), as getData names it */
        public $dagNames = [];
        /** @var array record id => the value a finding on it keeps, for the report tests */
        public $valueOf = [];
        /** @var ?array what scanRecordIdPosture() answers (W7-R2); raw names when null */
        public $posture = null;
        public function scanRecordIdPosture($pid)
        {
            return $this->posture !== null ? $this->posture
                : ['recordIds' => 'raw', 'hashRecordId' => null, 'recordIdField' => 'record_id'];
        }
        public $logs = [];
        private $count = 0;
        private $slotEpoch = 0;
        public $index;

        public function __construct(\INSPIRE\UniversalValidator\Scan\ArrayScanStore $store)
        {
            $this->store = $store;
            $this->user = new \VerbUser();
            $this->user->name = 'alice';
            $this->sys = [\INSPIRE\UniversalValidator\Scan\ScanService::SYS_FLAG => '1',
                          'log-hmac-key' => str_repeat('k', 32)];
            $this->proj = [\INSPIRE\UniversalValidator\Scan\ScanService::PROJ_FLAG => '1'];
            $this->index = new \FakeSourceDb();
        }

        public function getUser() { return $this->user; }
        /** @var int how many times crossLookupRefusals() was asked */
        public $refusalAsks = 0;
        public function crossLookupRefusals($pid, array $lookups)
        {
            $this->refusalAsks++;
            $out = [];
            foreach ($lookups as $l) {
                if (isset($this->refusedBy[$l['pid']])) {
                    $out[] = ['pid' => $l['pid'], 'why' => 'no rights there', 'unread' => false];
                }
            }
            return $out;
        }
        public function getSystemSetting($k) { return isset($this->sys[$k]) ? $this->sys[$k] : null; }
        public function getProjectSetting($k, $pid = null) { return isset($this->proj[$k]) ? $this->proj[$k] : null; }
        public function log($event, $context = []) { $this->logs[] = [$event, $context]; return 1; }

        public function durableScanContext($pid, array $opts = [], $dag = null)
        {
            $gen = isset($opts['generation']) ? (int) $opts['generation'] : null;
            $seq = isset($opts['runSeq']) ? (int) $opts['runSeq'] : 1;
            $key = $this->sys['log-hmac-key'];
            $self = $this;
            $this->contextsBuilt++;
            // As buildScanPlan(): a lookup whose project refuses the reader is
            // in crossRefused, and its rule stays in the rule list.
            $refused = $this->planRefused;
            if ($refused === null) {
                $refused = [];
                foreach ($this->crossLookups as $l) {
                    if (isset($this->refusedBy[$l['pid']])) $refused[(int) $l['pid']] = (int) $l['pid'];
                }
                $refused = array_values($refused);
            }
            $plan = ['hostFields' => ['a_val' => ['fa' => true]], 'crossRefused' => $refused,
                     'crossUnread' => $this->planUnread, 'crossLookups' => $this->crossLookups,
                     'crossRecordLookups' => false];
            // false: a plan that does not say (fails closed in ScanService).
            if ($refused === false) unset($plan['crossRefused']);
            return ['ok' => true, 'why' => null,
                'rules' => $this->rules,
                'ownership' => ['a_val' => 'fa'], 'structure' => $this->structure, 'choices' => $this->choices,
                'problems' => $this->problems, 'unread' => $this->unread,
                'verifyFingerprint' => $this->verifyFingerprint,
                'plan' => $plan,
                'read' => function (array $ids) use ($self) {
                    $d = [];
                    foreach ($ids as $id) {
                        $id = (string) $id;
                        if (!in_array($id, $self->records, true)) continue;
                        $d[$id] = ['1' => ['a_val' => isset($self->flagged[$id]) ? '' : 'x']];
                    }
                    return ['ok' => true, 'data' => $d, 'why' => null];
                },
                'evaluate' => function ($id, $node) use ($self, $pid, $gen, $seq, $key) {
                    $id = (string) $id;
                    if (isset($self->broken[$id])) {
                        return ['findings' => [], 'bytes' => 0, 'contexts' => 0,
                                'why' => 'this record could not be examined'];
                    }
                    if (!isset($self->flagged[$id])) {
                        return ['findings' => [], 'bytes' => 0, 'contexts' => 1, 'why' => null];
                    }
                    $hash = \INSPIRE\UniversalValidator\Scan\Hmac::raw(
                        \INSPIRE\UniversalValidator\Scan\Hmac::P_RECORD, $pid, $id, $key);
                    return ['findings' => [['project_id' => (int) $pid, 'generation_id' => (int) $gen,
                        'identity' => hash('sha256', 'f|' . $id, true), 'valid_from_seq' => $seq,
                        'record_hash' => $hash, 'record_id_bin' => $id, 'event_id' => 1,
                        'instance' => 1, 'dag_key' => isset($self->dagNames[$id]) ? $self->dagNames[$id] : null,
                        'host_form' => 'fa', 'field' => 'a_val',
                        'rule_source_id' => 'r1', 'rule_revision' => str_repeat('c', 64),
                        'rule_ord' => 1, 'check_type' => 'required',
                        'reason_code' => 'required-blank']
                        + (isset($self->valueOf[$id])
                            ? ['value_bin' => $self->valueOf[$id], 'value_len' => strlen($self->valueOf[$id])] : [])],
                        'bytes' => 10, 'contexts' => 1, 'why' => null];
                },
            ];
        }

        public function query($sql, $params = [])
        {
            $s = ltrim($sql);
            if (strpos($s, 'SELECT ROW_COUNT()') === 0) return [[$this->count]];
            $this->count = 0;
            if (in_array($s, ['START TRANSACTION', 'COMMIT', 'ROLLBACK'], true)) return [];
            if (strpos($sql, 'MAX(version)') !== false) {
                return [[(string) \INSPIRE\UniversalValidator\Scan\Schema::VERSION]];
            }
            if (strpos($s, 'SELECT table_name FROM information_schema.tables') === 0) {
                return array_map(function ($t) { return [$t]; }, $params);
            }
            if (strpos($sql, 'information_schema.tables') !== false) return [[1]];
            if (strpos($sql, 'log_event_table') !== false) return [['redcap_log_event']];
            if (strpos($sql, 'FROM redcap_log_event') !== false) {
                if (strpos($sql, 'pk IN (') !== false) {
                    $out = [];
                    foreach (array_slice($params, 1) as $id) {
                        if (in_array((string) $id, $this->records, true)) $out[] = [(string) $id, '50'];
                    }
                    return $out;
                }
                if (strpos($sql, 'log_event_id > ?') !== false) return [];
                if (strpos($s, 'SELECT MIN(log_event_id)') === 0) return [['1']];
                if (strpos($s, 'SELECT MAX(log_event_id)') === 0) {
                    // The fence's own read, not the capability probe's. Since
                    // W8-N11 the two send the same statement, so the caller
                    // tells them apart.
                    $probe = false;
                    foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 12) as $f) {
                        if (isset($f['class']) && $f['class'] === 'INSPIRE\\UniversalValidator\\ScanCapabilities') {
                            $probe = true;
                            break;
                        }
                    }
                    if ($this->logFails > 0 && !$probe) {
                        $this->logFails--;
                        throw new \RuntimeException('MySQL server has gone away', 2006);
                    }
                    return [['100']];
                }
                return [];
            }
            if (strpos($sql, 'information_schema.columns') !== false
                    || strpos($sql, 'redcap_record_list') !== false
                    || strpos($sql, 'redcap_projects') !== false
                    || strpos($sql, 'FROM redcap_data') !== false) {
                $this->index->rows = array_map(function ($id) { return [$id, null]; }, $this->records);
                return $this->index->select($sql, $params);
            }
            if (strpos($sql, \INSPIRE\UniversalValidator\Scan\Schema::table('scan_worker_slot')) !== false) {
                if (strpos($s, 'SELECT slot_no, epoch') === 0) return [[1, $this->slotEpoch]];
                if (strpos($s, 'SELECT COUNT(*)') === 0) return [[1, 0]];
                if (strpos($s, 'SELECT 1') === 0) return [[1]];
                if (strpos($s, 'UPDATE') === 0) {
                    if (strpos($sql, 'SET owner = ?, run_id = ?, epoch = ?') !== false) $this->slotEpoch++;
                    $this->count = 1;
                    return true;
                }
                return [];
            }
            if (strpos($s, 'SELECT finding_id, host_form, reason_code, dag_key, check_type') === 0) {
                $limit = preg_match('/LIMIT (\d+)/', $sql, $m) ? (int) $m[1] : 100;
                $out = [];
                foreach ($this->store->findings($params[0], $params[1], ['dag' => null], [], $params[2], $limit) as $f) {
                    $out[] = [$f['finding_id'], $f['host_form'], $f['reason_code'], $f['dag_key'],
                              $f['check_type']];
                }
                return $out;
            }
            if (strpos($s, 'SELECT lease_expires_at') === 0) {
                return $this->lease === null ? [] : [[$this->lease]];
            }
            $runs = \INSPIRE\UniversalValidator\Scan\Schema::table('scan_run');
            if (strpos($s, 'SELECT run_id FROM ' . $runs) === 0 && strpos($sql, 'phase = ?') !== false) {
                // reapCancelled(): this project's active runs in that phase.
                $out = [];
                for ($id = 1; $id <= 50; $id++) {
                    $r = $this->store->run($params[0], $id);
                    if ($r !== null && $r['terminal'] === null && $r['phase'] === $params[1]) $out[] = [$id];
                }
                return $out;
            }
            return [];
        }
    }
}

namespace INSPIRE\UniversalValidator\Scan {

    // -- the flags -----------------------------------------------------------

    $mod = new \FakeModule();
    $svc = new ScanService($mod);
    $a = $svc->available(1);
    check('service: with nothing configured the durable scan is OFF', $a['ok'] === false);
    check('service: and says so in one plain sentence',
        strpos($a['why'], 'not enabled here') !== false);

    // BOTH, not either. A system administrator enabling it for the installation
    // does not enable it for a project that has not asked, and vice versa.
    $mod->sys[ScanService::SYS_FLAG] = '1';
    check('service: the system switch alone is not enough',
        $svc->available(1)['ok'] === false);

    $mod2 = new \FakeModule();
    $mod2->proj[ScanService::PROJ_FLAG] = '1';
    check('service: the project switch alone is not enough either',
        (new ScanService($mod2))->available(1)['ok'] === false);

    // Only a real "on" is on. A stray string is not a truthy setting here,
    // because a settings store that returns 'off' or 'no' would otherwise turn
    // the feature ON.
    $mod3 = new \FakeModule();
    $mod3->sys[ScanService::SYS_FLAG] = 'off';
    $mod3->proj[ScanService::PROJ_FLAG] = 'off';
    check('service: "off" does not read as on', (new ScanService($mod3))->available(1)['ok'] === false);
    $mod3->sys[ScanService::SYS_FLAG] = 'no';
    $mod3->proj[ScanService::PROJ_FLAG] = 'no';
    check('service: nor does "no"', (new ScanService($mod3))->available(1)['ok'] === false);

    // -- A MODULE BEING SWITCHED OFF REFUSES START AND WORK (review rv10a2-3) --
    //
    // The disable hook stops every run while the module still answers; a Start
    // in that window made a run the hook had walked past.
    $dis = new \FakeModule();
    $dis->sys[ScanService::SYS_FLAG] = '1';
    $dis->proj[ScanService::PROJ_FLAG] = '1';
    $dis->sys[ScanService::DISABLED_AT] = (string) time();
    $dv = (new ScanService($dis))->available(1);
    check('service: while the module is being switched off, Start and work are refused, and told why',
        $dv['ok'] === false && strpos($dv['why'], 'being switched off') !== false);
    $dis->sys[ScanService::ENABLED_AT] = (string) (time() + 1);
    $dv = (new ScanService($dis))->available(1);
    check('service: an enable after the disable lifts that refusal',
        strpos((string) $dv['why'], 'being switched off') === false);
    unset($dis->sys[ScanService::ENABLED_AT]);
    $dis->sys[ScanService::DISABLED_AT] = (string) (time() - ScanService::DISABLING_SECONDS - 1);
    $dv = (new ScanService($dis))->available(1);
    check('service: and with no enable hook it ends by itself, so a missed hook cannot refuse every scan',
        strpos((string) $dv['why'], 'being switched off') === false);
    // rvf2-1: the project disable hook's own marker refuses the same way, in
    // the project's words, until the project enable hook's marker.
    $pdis = new \FakeModule();
    $pdis->sys[ScanService::SYS_FLAG] = '1';
    $pdis->proj[ScanService::PROJ_FLAG] = '1';
    $pdis->proj[ScanService::PROJECT_DISABLED_AT] = (string) time();
    $dv = (new ScanService($pdis))->available(1);
    check('service rvf2-1: while the module is being switched off for the project, Start and work are refused',
        $dv['ok'] === false && $dv['why'] === ScanService::OFF_PROJECT);
    $pdis->proj[ScanService::PROJECT_ENABLED_AT] = (string) time();
    $dv = (new ScanService($pdis))->available(1);
    check('service rvf2-1: and the project enable after it lifts that refusal',
        strpos((string) $dv['why'], 'being switched off') === false);

    // -- the flag answer discloses nothing about the installation ------------
    //
    // The order in available() is deliberate: the flags are checked BEFORE the
    // schema, so someone who has not turned the feature on cannot use the
    // refusal to find out whether the tables were ever created.
    $quiet = new \FakeModule();
    $quiet->version = 1;                              // schema present and healthy
    $quiet->tables = Schema::tables();
    $q = (new ScanService($quiet))->available(1);
    check('service: a disabled installation is not told about its schema',
        $q['ok'] === false && $q['detail'] === null
        && strpos($q['why'], 'table') === false);

    // -- past the flags, the schema is the next gate -------------------------
    $on = new \FakeModule();
    $on->sys[ScanService::SYS_FLAG] = '1';
    $on->proj[ScanService::PROJ_FLAG] = '1';
    $on->version = 1;
    $on->tables = array_slice(Schema::tables(), 0, 3);   // three of ten
    $r = (new ScanService($on))->available(1);
    check('service: an incomplete schema refuses', $r['ok'] === false);
    check('service: and says it is the tables',
        strpos($r['why'], 'tables are not ready') !== false);
    check('service: with a diagnostic for the administrator, kept separate',
        is_string($r['detail']) && strpos($r['detail'], 'missing') !== false);

    // A schema that cannot be READ is not a schema that is absent, and the two
    // must not lead to the same action - one installs, the other refuses.
    $unread = new \FakeModule();
    $unread->sys[ScanService::SYS_FLAG] = '1';
    $unread->proj[ScanService::PROJ_FLAG] = '1';
    $unread->version = null;      // the version query throws "doesn't exist" -> version 0
    $u = (new ScanService($unread))->available(1);
    check('service: a fresh installation with no tables refuses rather than half-installing',
        $u['ok'] === false);

    // -- no database at all --------------------------------------------------
    //
    // A real REDCap build. The module must explain itself rather than fatal on
    // the page, and it must do so even before the flags: there is nothing an
    // administrator could switch on that would help.
    $nodb = new \NoDbModule();
    $nodb->sys[ScanService::SYS_FLAG] = '1';
    $nodb->proj[ScanService::PROJ_FLAG] = '1';
    $nd = (new ScanService($nodb))->available(1);
    check('service: a build with no module database refuses', $nd['ok'] === false);
    check('service: naming the reason an administrator can act on',
        strpos($nd['why'], 'database access') !== false);

    // -- every unknown run gets ONE sentence ---------------------------------
    //
    // "No such run" and "not your run" must be indistinguishable, or a project
    // link becomes a way to count the runs on every other project.
    check('service: there is a single wording for an unreachable run',
        is_string(ScanService::NO_RUN) && strlen(ScanService::NO_RUN) > 20);
    check('service: it names no project, no run and no owner',
        preg_match('/\d/', ScanService::NO_RUN) === 0
        && stripos(ScanService::NO_RUN, 'permission') === false
        && stripos(ScanService::NO_RUN, 'denied') === false);

    // -- the verbs refuse while disabled -------------------------------------
    //
    // Not just the page: each AJAX verb re-asks. redcap_module_ajax() guards the
    // action NAME and hands the caller's identity straight through, so a verb
    // that trusted the page's gate would be reachable by anyone who could form
    // the request.
    $offSvc = new ScanService(new \FakeModule());
    check('service: start refuses while disabled', $offSvc->start(1)['ok'] === false);
    check('service: work refuses while disabled', $offSvc->work(1, 5)['ok'] === false);
    check('service: and neither reveals whether run 5 exists',
        $offSvc->work(1, 5)['why'] === $offSvc->work(1, 99999)['why']);

    // -- A STORAGE FAILURE IS NOT A 500, AND NOT A LEAK ----------------------
    //
    // ScanService is the last frame before the AJAX handler. Now that the store
    // throws rather than answering a deadlock with the fence's own vocabulary,
    // something has to decide what an operator is told and what an
    // administrator is told, and this is the only place that can decide both.
    //
    // The stand-in raises the failure at the query seam, which is where the
    // real one arrives from: ModuleDb::exec() throws ScanStoreUnavailable when
    // the server will not say how many rows a write changed, and SqlScanStore's
    // read methods deliberately do not catch it.
    $ready = new \StorageFaultModule();
    $ready->sys[ScanService::SYS_FLAG] = '1';
    $ready->proj[ScanService::PROJ_FLAG] = '1';
    $ready->version = Schema::VERSION;
    // The record index exists here as well as answering; until W8-N2 the probe
    // took the COUNT(*) row [0] for a table, so this fixture passed without it.
    $ready->tables = array_merge(Schema::tables(), ['redcap_record_list']);
    check('service: the fixture is otherwise healthy, so the next refusal is the fault',
        (new ScanService($ready))->available(1)['ok'] === true);

    // W8-N2: a table is there when information_schema COUNTS it, not when the
    // count comes back. COUNT(*) always returns a row, and [0] is a row.
    $tex = new \ReflectionMethod(\INSPIRE\UniversalValidator\ScanCapabilities::class, 'tableExists');
    $tex->setAccessible(true);
    $absent = new \StorageFaultModule();
    $absent->tables = [];
    $present = new \StorageFaultModule();
    $present->tables = ['redcap_record_list'];
    check('W8-N2: a COUNT(*) of zero is no table',
        $tex->invoke(null, $absent, 'redcap_record_list')['state'] === \INSPIRE\UniversalValidator\ScanCapabilities::NO);
    check('W8-N2: a count of one is the table',
        $tex->invoke(null, $present, 'redcap_record_list')['state'] === \INSPIRE\UniversalValidator\ScanCapabilities::OK);
    check('W8-N2: so a record index information_schema does not list is not walked as one, however its probe answers',
        \INSPIRE\UniversalValidator\ScanCapabilities::recordEnumeration($absent, 1)['via'] !== 'redcap_record_list (probed)'
        && \INSPIRE\UniversalValidator\ScanCapabilities::recordEnumeration($present, 1)['via'] === 'redcap_record_list (probed)');

    $ready->failOn = 'FROM ' . Schema::table('scan_run');
    $svcF = new ScanService($ready);
    $w = $svcF->work(1, 5);
    check('service: a storage failure during work is a refusal, not an uncaught throw',
        is_array($w) && $w['ok'] === false);
    check('service: and the operator gets the one fixed sentence',
        $w['why'] === ScanStoreUnavailable::OPERATOR_TEXT);
    check('service: which names no table, column, value or error number',
        preg_match('/\d/', $w['why']) === 0
        && stripos($w['why'], 'uv_') === false && stripos($w['why'], 'deadlock') === false);

    $logged = $ready->logged('scan storage failure');
    check('service: what the server said reaches the module log',
        count($logged) === 1 && strpos($logged[0]['detail'], 'Deadlock') !== false);
    check('service: with the run and project it happened on',
        $logged[0]['run_id'] === 5 && $logged[0]['project_id'] === 1);
    check('service: and none of it reaches the browser',
        strpos(json_encode($w), 'Deadlock') === false);

    // START TAKES THE SAME BOUNDARY, through the same helper, and it cannot be
    // driven from here: every gate in front of startRun() - the availability
    // probe, the scope read, the manifest source - catches Throwable and turns
    // it into its own refusal, so nothing a stand-in can raise reaches the
    // catch except by going all the way through the planner. The store half of
    // it is proved in tests/scan_sqlstore_fault_php.php, where a write failure
    // over an EMPTY project slot throws instead of reporting contention; the
    // service half needs the database matrix, and is named for it.

    // -- reason codes --------------------------------------------------------
    //
    // The column is 64 characters and `assert:` carries up to 507 of expression.
    // Storing that per finding is a gigabyte of the same sentence, and it
    // destroys both the index and the GROUP BY the summary needs.
    check('reason: an assert keeps its kind and sheds its expression',
        ReasonCode::code('assert:total_dose <= max_dose * 3') === 'assert');
    $sp = ReasonCode::split('assert:a > b');
    check('reason: and the expression comes back for the RULE to store',
        $sp['detail'] === 'a > b');
    check('reason: a plain reason is its own code',
        ReasonCode::code('required-blank') === 'required-blank');
    check('reason: an empty reason is still a code, never a blank column',
        ReasonCode::code('') === 'unspecified');

    // Pooled problems are a closed five-element set: a mask indexes and groups,
    // a joined string does neither.
    $p = ReasonCode::split('pooled:length;checkdigit');
    check('reason: pooled becomes a bitmask', $p['code'] === 'pooled' && $p['bits'] === (1 | 4));
    check('reason: and the mask reads back as the same problems',
        ReasonCode::pooledNames($p['bits']) === ['length', 'checkdigit']);
    check('reason: an unknown pooled problem does not corrupt the mask',
        ReasonCode::split('pooled:length;invented')['bits'] === 1);

    // An unknown reason PASSES THROUGH. A future rule type must degrade to
    // generic wording, never to a silent hole.
    check('reason: a reason nobody has seen is kept, not dropped',
        ReasonCode::code('out-of-range') === 'out-of-range');
    $long = ReasonCode::code(str_repeat('x', 200));
    check('reason: an over-long code is cut to the column', strlen($long) <= ReasonCode::MAX);
    check('reason: and marked, so it is visibly odd rather than quietly wrong',
        substr($long, -1) === '~');

/* =========================================================================
 * B3  ONE DAG AXIS, END TO END, WITH NO DATABASE
 *
 * This file had no occurrence of 'dag' at all, and that absence is why the
 * seam stayed open through 1,228 green checks: scan_security_php was
 * internally consistent on DAG NAMES, the planning matrix was internally
 * consistent on numeric IDS, and neither suite ever held both sides of the
 * comparison at once.
 *
 * The join below does. ScanPageView::scanScope() is the real producer, driven
 * by a module whose getUser() answers a real rights array; RecordManifestSource
 * is the real walker over a fake ScanDb serving redcap_record_list; ScanPlanner
 * is the real planner over ArrayScanStore. The scope value is never written
 * down - it is taken from the producer and handed to the consumer.
 *
 * WHY appended IS THE ASSERTION AND outOfScope IS NOT. outOfScope === listed is
 * produced identically by a group that genuinely holds no records and by a
 * scope compared on the wrong axis, and ScanService::start discards the stats
 * array anyway. Measured against the shipped tree: listed 3, appended 0,
 * outOfScope 3, manifest_total 0 - and an empty frozen manifest is exactly what
 * promotes to coverage=complete-through-fence clean=true. Against the fixed
 * tree: listed 3, appended 2, outOfScope 1, manifest_total 2.
 * ===================================================================== */
{
    $db  = new \FakeSourceDb();
    $mod = new \DagUserModule();
    \REDCap::$groupNames = [7 => 'north', 31 => 'south'];

    $scope = \INSPIRE\UniversalValidator\ScanPageView::scanScope($mod, 149);
    check('B3 GATE 2: the page produces a scope for a DAG-bound designer',
        $scope['ok'] === true && $scope['dag'] === '7');

    $open = RecordManifestSource::open($db, 149, ['pk' => null]);
    check('B3 GATE 2: and the record index is usable as a source',
        $open['ok'] === true && $open['source']->hasDag());

    $planner = new ScanPlanner(new ArrayScanStore(), str_repeat('k', 32));
    $r = $planner->plan(149, [
        'source'    => $open['source'],
        'fence'     => null,
        'dagFilter' => $scope['dag'],          // TAKEN, never written down
        'rules'     => [['type' => 'required', 'fields' => ['a']]],
        'ownership' => ['a' => 'fa'],
        'policy'    => [],
        'createdBy' => 'u',
        'engine'    => '1',
    ]);

    check('B3 GATE 2: a group-scoped run planned from the page\'s own scope APPENDS the '
        . 'records in that group',
        $r['stats']['listed'] === 3 && $r['stats']['appended'] === 2
        && $r['stats']['outOfScope'] === 1);
    check('B3 GATE 2: the frozen manifest is not empty, so nothing can promote over nothing',
        (int) $r['run']['manifest_total'] === 2);
    check('B3 GATE 2: the run stores the id it was scoped by',
        $r['run']['scope_dag'] === '7');
    check('B3 GATE 2: and the user who started it may work it',
        ScanAuthorization::mayWork($scope['rights'], ['fa'], $r['run']['scope_dag'])['ok'] === true);
}

/* =====================================================================
 * EE-4  AN EMPTY SCOPE KEEPS THE RULE PROBLEMS THE PLANNER RECORDED
 *
 * The planner notes rule problems before it reads a record, then finishes a run
 * whose manifest froze empty. That finish wrote rule_problem_count = 0 beside
 * the marks it had just written: derive() does not carry the count, promote()
 * adds it, and this path never goes through promote(). The run is stored
 * clean = 0, so its counts are ones a report reads.
 * ===================================================================== */
{
    $es = new ArrayScanStore();
    $esOpen = RecordManifestSource::open(new \FakeSourceDb(), 149, ['pk' => null]);
    $esr = (new ScanPlanner($es, str_repeat('k', 32)))->plan(149, [
        'source' => $esOpen['source'], 'fence' => null,
        'dagFilter' => '99',                     // a group no record is in
        'rules' => [['type' => 'required', 'fields' => ['a']]], 'ownership' => ['a' => 'fa'],
        'policy' => [], 'createdBy' => 'u', 'engine' => '1',
        'ruleProblems' => [['rule' => 1, 'fields' => ['a'], 'why' => 'x', 'code' => 'c1'],
                           ['rule' => 2, 'fields' => ['b'], 'why' => 'y', 'code' => 'c2']]]);
    $esRow = $es->run(149, 1);
    check('EE-4: a run whose manifest froze empty is refused and finished as an empty scope',
        $esr['ok'] === false && $esRow['coverage'] === ScanOutcome::EMPTY_SCOPE
        && $esRow['clean'] !== null && (int) $esRow['clean'] === 0);
    check('EE-4: and keeps the two rule problems the planner recorded, rather than a 0',
        (int) $esRow['rule_problem_count'] === 2
        && ScanPromotion::tally($es->aggregates(1))['ruleProblems'] === 2);
}

/* =====================================================================
 * WHERE A RUN HAS GOT TO, AND WHETHER IT IS GETTING ANYWHERE (L4, L5)
 *
 * status() is reachable only through a module, a user and an entitlement,
 * which is why it had no test. What it SAYS about progress is progressOf(),
 * which takes a row, a census and a clock.
 * ===================================================================== */
{
    $NOW = strtotime('2026-09-20 12:00:00 UTC');
    $ago = function ($seconds) use ($NOW) { return gmdate('Y-m-d H:i:s', $NOW - $seconds); };
    $row = function (array $over) use ($ago) {
        return array_merge(['phase' => ScanPhase::SCANNING, 'terminal' => null,
            'created_at' => $ago(3600), 'updated_at' => $ago(1), 'progress_at' => $ago(10)], $over);
    };
    $census = [ScanStore::REC_PENDING => 4, ScanStore::REC_CLAIMED => 1, ScanStore::REC_DONE => 8,
               ScanStore::REC_UNREADABLE => 1, ScanStore::REC_UNSTABLE => 2,
               ScanStore::REC_TOMBSTONE => 3, ScanStore::REC_UNSTORED => 5];
    $p = ScanService::progressOf($row([]), $census, $NOW);
    check('progress: done is records EXAMINED and nothing else', $p['done'] === 8);
    check('progress: every not-examined state is an exclusion, once each', $p['excluded'] === 8);
    check('progress: a deleted record is neither', $p['removed'] === 3);
    check('progress: settled is the three together, and leaves out what is still waiting',
        $p['settled'] === 19);
    // The bug, in one line: twelve records, none examined, and the old sum.
    $none = ScanService::progressOf($row([]), [ScanStore::REC_UNSTORED => 12], $NOW);
    check('progress: a run where every record failed to store examined NOTHING',
        $none['done'] === 0 && $none['excluded'] === 12 && $none['settled'] === 12);
    check('progress: an empty census is all zeroes rather than an error',
        ScanService::progressOf($row([]), [], $NOW)['settled'] === 0);

    check('stall: a run that finished a record ten seconds ago is not stalled',
        $p['stalled'] === false && $p['paused'] === false && $p['idleSeconds'] === 10);
    // A worker holds the run: claim() took the lease a second ago, as it does
    // on every pass of a tab that is driving it.
    $held = function ($s) use ($NOW) { return gmdate('Y-m-d H:i:s', $NOW + $s); };
    $s = ScanService::progressOf($row(['progress_at' => $ago(500), 'lease_expires_at' => $held(299)]),
        $census, $NOW);
    check('stall: one a worker holds that has finished nothing for longer than the threshold is',
        $s['stalled'] === true && $s['paused'] === false && $s['idleSeconds'] === 500);
    // THE WEDGE. claim() refreshes updated_at on a run whose every batch is
    // being refused, which is exactly the run a watcher needs to be told about.
    check('stall: in scanning, a fresh updated_at does NOT hide it',
        ScanService::progressOf($row(['progress_at' => $ago(500), 'updated_at' => $ago(1),
            'lease_expires_at' => $held(299)]), $census, $NOW)['stalled'] === true);
    // The other three phases never move progress_at, so their cursor writes are
    // the only clock there is.
    foreach (ScanService::PHASES_ON_THE_UPDATE_CLOCK as $ph) {
        $busy = ScanService::progressOf($row(['phase' => $ph, 'progress_at' => $ago(5000),
            'updated_at' => $ago(3)]), $census, $NOW);
        check("stall: in $ph a moving cursor is progress", $busy['stalled'] === false
            && $busy['paused'] === false && $busy['idleSeconds'] === 3);
        // NOTHING IN THIS PHASE RENEWS A LEASE, so a cursor that has stopped
        // cannot be told from a run nobody is driving - and it is given the
        // reading that sends somebody to Continue, even with a lease left over
        // from scanning.
        $stuck = ScanService::progressOf($row(['phase' => $ph, 'progress_at' => $ago(5000),
            'updated_at' => $ago(4000), 'lease_expires_at' => $held(60)]), $census, $NOW);
        check("stall: and in $ph a cursor that has stopped is PAUSED, not stalled",
            $stuck['paused'] === true && $stuck['stalled'] === false && $stuck['idleSeconds'] === 4000);
    }

    // CL-2. A RUN NOBODY IS DRIVING IS PAUSED. Only an open tab works a scan,
    // and the page says closing it is safe; the run that tab leaves behind was
    // called stalled two minutes later, and the page told its next reader to
    // stop it and start a new one.
    $closed = ScanService::progressOf($row(['progress_at' => $ago(600), 'updated_at' => $ago(600)]),
        $census, $NOW);
    check('CL-2: a run whose tab was closed ten minutes ago, with no lease, is paused - not stalled',
        $closed['paused'] === true && $closed['stalled'] === false && $closed['idleSeconds'] === 600);
    check('CL-2: and so is one whose lease ran out',
        ScanService::progressOf($row(['progress_at' => $ago(600), 'updated_at' => $ago(1),
            'lease_expires_at' => $ago(5)]), $census, $NOW)['paused'] === true);
    // A LEASE OUTLIVES ITS REQUEST by five minutes. The tab closed three
    // minutes ago: its lease is still "live", and nobody has touched the run
    // since. That is the closed tab, not the wedge.
    $leftover = ScanService::progressOf($row(['progress_at' => $ago(180), 'updated_at' => $ago(180),
        'lease_expires_at' => $held(120)]), $census, $NOW);
    check('CL-2: a lease left by a tab closed three minutes ago does not make the run stalled',
        $leftover['paused'] === true && $leftover['stalled'] === false);
    check('CL-2: an unreadable lease is no lease, so it can never make a run look stalled',
        ScanService::progressOf($row(['progress_at' => $ago(600), 'lease_expires_at' => 'not a date']),
            $census, $NOW)['stalled'] === false);
    // A STOPPED RUN sits in `cancelling` until a later request finishes it.
    $stop = ScanService::progressOf($row(['phase' => ScanPhase::CANCELLING,
        'progress_at' => $ago(9000), 'updated_at' => $ago(1), 'lease_expires_at' => $held(299)]),
        $census, $NOW);
    check('CL-2: a run that was asked to stop is stopping - never stalled, never paused',
        $stop['stopping'] === true && $stop['stalled'] === false && $stop['paused'] === false);
    check('CL-2: and a run that was not asked to stop is not stopping',
        $closed['stopping'] === false && $s['stopping'] === false);
    // CL-3, RE-DERIVED FOR B10. Planning was one synchronous request that wrote
    // nothing to the run row until the freeze, so it was exempt from stalled.
    // It is a slice per request now and every appended page moves progress_at
    // (review R3U-2), so a planner two and a half minutes into a long listing
    // has a clock a second old, and is told nothing.
    $plan = ScanService::progressOf(['phase' => ScanPhase::PLANNING, 'terminal' => null,
        'created_at' => $ago(150), 'updated_at' => $ago(1), 'progress_at' => $ago(1),
        'lease_expires_at' => $held(299)], [ScanStore::REC_PENDING => 400000], $NOW);
    check('CL-3: a run still listing its records for two and a half minutes is not stalled',
        $plan['stalled'] === false && $plan['paused'] === false && $plan['stopping'] === false
        && !array_key_exists('planningStopped', $plan));
    // AND IT IS READ AS SCANNING IS. A planner holding its lease that has
    // appended nothing for the stall threshold is stalled; one nobody holds is
    // paused, because Continue now lists the rest.
    $planWedged = ScanService::progressOf(['phase' => ScanPhase::PLANNING, 'terminal' => null,
        'created_at' => $ago(400), 'updated_at' => $ago(1), 'progress_at' => $ago(150),
        'lease_expires_at' => $held(299)], [], $NOW);
    check('B10: a planner holding its lease with no page appended for the threshold is stalled',
        $planWedged['stalled'] === true && $planWedged['paused'] === false);
    $planLeft = ScanService::progressOf(['phase' => ScanPhase::PLANNING, 'terminal' => null,
        'created_at' => $ago(400), 'updated_at' => $ago(150), 'progress_at' => $ago(150),
        'lease_expires_at' => null], [], $NOW);
    check('B10: and a planning run nobody holds is paused, which Continue now resumes',
        $planLeft['paused'] === true && $planLeft['stalled'] === false);
    // R3U-2: THE PLANNING CLOCK IS THE LAST APPEND, NOT THE RUN'S AGE. A run
    // created three hours ago whose planner appended a page a second ago is a
    // big project being listed, and the Stop "stopped" sent people to would
    // have ended it under the request doing the work.
    $planLive = ScanService::progressOf(['phase' => ScanPhase::PLANNING, 'terminal' => null,
        'created_at' => $ago(3 * 3600), 'updated_at' => $ago(1), 'progress_at' => $ago(1),
        'lease_expires_at' => $held(299)], [], $NOW);
    check('R3U-2: a live planner on a run three hours old is never told it stopped',
        $planLive['stalled'] === false && $planLive['paused'] === false && $planLive['idleSeconds'] === 1);

    // R2O-3, RETIRED. Round 2 gave a planning run quiet for 15 minutes its own
    // sentence - press Stop and start again - and hid Continue, because a list
    // that died part way could not be resumed. Since B10 it can: the cursor
    // and the carry are committed with every page. A planning run left for
    // three hours is paused, and Continue carries on listing.
    $planRow = function ($idleFor, array $over = []) use ($ago) {
        return array_merge(['phase' => ScanPhase::PLANNING, 'terminal' => null,
            'created_at' => $ago($idleFor), 'updated_at' => $ago($idleFor),
            'progress_at' => $ago($idleFor), 'lease_expires_at' => null], $over);
    };
    $dead = ScanService::progressOf($planRow(3 * 3600), [], $NOW);
    check('B10: a planning run nobody has listed for three hours is paused - Continue resumes it',
        $dead['paused'] === true && $dead['stalled'] === false && $dead['stopping'] === false
        && $dead['idleSeconds'] === 10800 && !array_key_exists('planningStopped', $dead));
    check('B10: before the first page it is timed from its creation',
        ScanService::progressOf($planRow(600, ['progress_at' => null]), [], $NOW)['paused'] === true);
    check('B10: a planning row with no readable clock is not given a quiet reading',
        ScanService::progressOf(['phase' => ScanPhase::PLANNING, 'terminal' => null,
            'created_at' => 'not a date'], [], $NOW)['paused'] === false);
    check('B10: the planning window and its setting are gone',
        !method_exists(ScanPolicy::class, 'planningSeconds')
        && !defined(ScanPolicy::class . '::D_PLANNING_MINUTES')
        && !defined(ScanPolicy::class . '::MAX_PLANNING_MINUTES')
        && strpos((string) file_get_contents(__DIR__ . '/../config.json'), 'scan-system-planning-minutes') === false);
    check('stall: the list of those phases is the three finishing ones, and not scanning',
        count(ScanService::PHASES_ON_THE_UPDATE_CLOCK) === 3
        && !in_array(ScanPhase::SCANNING, ScanService::PHASES_ON_THE_UPDATE_CLOCK, true));
    check('stall: a run that has never finished a record is timed from when it was created',
        ScanService::progressOf($row(['progress_at' => null, 'created_at' => $ago(900)]),
            [], $NOW)['idleSeconds'] === 900);
    check('stall: a FINISHED run is never stalled, however long ago it ended',
        ScanService::progressOf($row(['phase' => ScanPhase::TERMINAL, 'terminal' => 'complete',
            'progress_at' => $ago(999999)]), $census, $NOW)['stalled'] === false);
    check('stall: a row with no readable clock is not reported as the alarming reading of it',
        ScanService::progressOf(['phase' => ScanPhase::SCANNING, 'terminal' => null,
            'created_at' => 'not a date'], $census, $NOW) ['stalled'] === false);
    check('stall: the threshold is the caller\'s to tighten',
        ScanService::progressOf($row(['lease_expires_at' => $held(299)]), $census, $NOW, 5)['stalled'] === true
        && ScanService::progressOf($row(['lease_expires_at' => $held(299)]), $census, $NOW, 11)['stalled'] === false
        && ScanService::progressOf($row([]), $census, $NOW, 5)['paused'] === true);
    // UTC IN, UTC OUT. The store stamps gmdate(); read back in a server zone
    // east of Greenwich, every run would look hours idle.
    $tz = date_default_timezone_get();
    date_default_timezone_set('Africa/Douala');
    $east = ScanService::progressOf($row([]), $census, $NOW);
    date_default_timezone_set('America/Los_Angeles');
    $west = ScanService::progressOf($row([]), $census, $NOW);
    date_default_timezone_set($tz);
    check('stall: the idle time does not depend on the server\'s time zone',
        $east['idleSeconds'] === 10 && $west['idleSeconds'] === 10);
    check('stall: the threshold is a sentence on a page, so it is in neither the policy nor the fingerprint',
        !array_key_exists('stallSeconds', ScanPolicy::resolve([], []))
        && ScanService::STALL_SECONDS === 120);
}

/* =====================================================================
 * OC-2  STATUS REPORTS WHAT PROMOTION DECIDED
 *
 * promote() stores clean, rule_problem_count and not_examined; status() returned
 * none of them, so the page could not tell a certified run from one refused
 * clean over a rule problem. decisionOf() is the part of status() that reads
 * them, and it is reachable without an entitlement.
 * ===================================================================== */
{
    $d = function (array $row) { return ScanService::decisionOf($row); };
    check('OC-2: a certified run reports clean as 1',
        $d(['clean' => '1', 'rule_problem_count' => '0', 'not_examined' => '0'])
            === ['clean' => 1, 'ruleProblems' => 0, 'notExamined' => 0]);
    check('OC-2: a run refused clean over a rule problem reports 0 and the count',
        $d(['clean' => '0', 'rule_problem_count' => '1', 'not_examined' => '0'])
            === ['clean' => 0, 'ruleProblems' => 1, 'notExamined' => 0]);
    check('OC-2: no decision stays NO decision - null, never a 0 that reads as "found something"',
        $d(['clean' => null, 'rule_problem_count' => '0', 'not_examined' => '0'])['clean'] === null
        && $d([])['clean'] === null);
    check('OC-2: records not examined are reported',
        $d(['clean' => '0', 'not_examined' => '3'])['notExamined'] === 3);
    check('OC-2: a value other than 1 is never read as certified',
        $d(['clean' => '2'])['clean'] === 0 && $d(['clean' => 'yes'])['clean'] === 0);
    // THE ROW status() IS GIVEN. A run finished by the real promotion over the
    // in-memory store: one record examined, nothing found, one rule problem.
    $ps = new ArrayScanStore();
    $prid = (int) $ps->startRun(830, ['created_by' => 'alice', 'fence_open' => '10'])['run']['run_id'];
    $ps->writeManifest($prid, [['id_bin' => 'R1', 'hash' => hash('sha256', 'R1', true)]]);
    $pep = (int) $ps->run(830, $prid)['lease_epoch'];
    $pc = $ps->claim($prid, 'w', $pep, 5);
    $ps->commitBatch($prid, 'w', $pep, ['bytes' => 0, 'findings' => [], 'records' => [
        ['ordinal' => $pc[0]['ordinal'], 'record_hash' => $pc[0]['hash'], 'claim' => $pc[0]['claim'],
         'state' => ScanStore::REC_DONE, 'version' => '500']]]);
    ScanPromotion::noteRuleProblems($ps, $prid, [['rule' => 1, 'fields' => ['a'], 'why' => 'x']]);
    $ps->setProgressState($prid, $pep, ['fenceTarget' => '500']);
    ScanPromotion::promote($ps, 830, $prid, ['uniqueDone' => true, 'rollupDone' => true]);
    $prow = $ps->run(830, $prid);
    check('OC-2: a run promotion refused over a rule problem reaches status() as not certified',
        $prow['terminal'] === ScanOutcome::COMPLETE
        && ScanService::decisionOf($prow) === ['clean' => 0, 'ruleProblems' => 1, 'notExamined' => 0]);
}

/* =====================================================================
 * OC-4  A RUN WITH NO VERDICT NEVER SAYS ZERO OVER FINDINGS IT HAS
 *
 * status() reports findingsOf(). For a cancelled, failed or expired run that
 * was the stored-row counter, which the uniqueness finalizer never moves: a run
 * cancelled during unique-finalize said "Nothing found yet" over the duplicates
 * it had written. The run row cannot say whether a summary was built, so a run
 * with no verdict answers with the larger of the counter and whatever summary
 * exists - and with NULL, "not counted", when both are zero.
 * ===================================================================== */
{
    $agg = new ArrayScanStore();
    $aid = (int) $agg->startRun(820, ['created_by' => 'alice'])['run']['run_id'];
    $bare = new ArrayScanStore();
    $bid = (int) $bare->startRun(821, ['created_by' => 'alice'])['run']['run_id'];
    foreach (RollupBuilder::KINDS as $kind) $agg->addAggregate($aid, $kind, 'x', null, 2);
    $as = function (ArrayScanStore $s, $pid, $id, array $over) {
        return ScanPromotion::findingsOf($s, array_merge($s->run($pid, $id), $over));
    };
    foreach (ScanOutcome::NO_VERDICT as $how) {
        check("OC-4: a $how run with nothing stored and nothing summarised is NOT COUNTED, never zero",
            $as($bare, 821, $bid, ['terminal' => $how, 'detail_rows' => 0]) === null);
        check("OC-4: a $how run whose summary holds findings the counter missed reports them",
            $as($agg, 820, $aid, ['terminal' => $how, 'detail_rows' => 0]) === 2);
        check("OC-4: and a $how run answers with the larger of the two, whichever it is",
            $as($agg, 820, $aid, ['terminal' => $how, 'detail_rows' => 5]) === 5
            && $as($bare, 821, $bid, ['terminal' => $how, 'detail_rows' => 3]) === 3);
    }
    check('OC-4: a run that reached a verdict still answers from its summary alone',
        $as($agg, 820, $aid, ['terminal' => ScanOutcome::COMPLETE, 'detail_rows' => 9]) === 2
        && $as($bare, 821, $bid, ['terminal' => ScanOutcome::COMPLETE, 'detail_rows' => 9]) === 0);
    check('OC-4: and a run still working reports its counter, as it did',
        $as($agg, 820, $aid, ['terminal' => null, 'detail_rows' => 4]) === 4);

    // FINDINGS THE DETAIL BUDGET LEFT OUT. The counter stops at the budget
    // while the worker goes on finding, so every answer adds them: a run
    // still working (review rv10b1-3), and a run with no verdict, whose
    // floor is taken over what was STORED and then has them added (rv10b1-5
    // M1: compared instead, a run that stored 5 and left 2 out said 5).
    $uns = new ArrayScanStore();
    $uid = (int) $uns->startRun(822, ['created_by' => 'alice'])['run']['run_id'];
    $uns->addAggregate($uid, ScanStore::DETAIL_UNSTORED, '', '', 2);
    check('rv10b1-3: a run still working counts the findings it left out beside the ones it stored',
        $as($uns, 822, $uid, ['terminal' => null, 'detail_rows' => 3]) === 5);
    // And reads its aggregates for that only once the budget it froze is
    // spent: below it nothing was left out, and a status poll reads none.
    $frozen = json_encode(['maxFindings' => 10, 'maxBytes' => 1000]);
    $noAgg = (new FaultyStore($uns))->failFrom('aggregates');
    $askAgg = function (array $over) use ($noAgg, $uns, $uid) {
        try {
            return ScanPromotion::findingsOf($noAgg, array_merge($uns->run(822, $uid), $over));
        } catch (ScanStoreUnavailable $e) {
            return 'read';
        }
    };
    check('rv10b1-3: a working run below the budget it froze reads no aggregates for its count',
        $askAgg(['terminal' => null, 'detail_rows' => 4, 'detail_bytes' => 10,
                 'policy_json' => $frozen]) === 4);
    check('rv10b1-3: one at the findings limit, or the bytes limit, or with no budget to read, does',
        $askAgg(['terminal' => null, 'detail_rows' => 10, 'detail_bytes' => 0, 'policy_json' => $frozen]) === 'read'
        && $askAgg(['terminal' => null, 'detail_rows' => 4, 'detail_bytes' => 1000, 'policy_json' => $frozen]) === 'read'
        && $askAgg(['terminal' => null, 'detail_rows' => 4, 'policy_json' => '{']) === 'read'
        && $askAgg(['terminal' => null, 'detail_rows' => 4, 'policy_json' => json_encode(['maxBytes' => 1000])]) === 'read');
    foreach (ScanOutcome::NO_VERDICT as $how) {
        check("rv10b1-5: a run ended $how that stored 5 and left 2 out found 7",
            $as($uns, 822, $uid, ['terminal' => $how, 'detail_rows' => 5]) === 7);
    }
    foreach (RollupBuilder::KINDS as $kind) $uns->addAggregate($uid, $kind, 'x', null, 4);
    check('rv10b1-5: and with a summary of 4 beside a counter of 5, still 5 stored and 2 left out',
        $as($uns, 822, $uid, ['terminal' => ScanOutcome::CANCELLED, 'detail_rows' => 5]) === 7);
    check('rv10b1-5: a summary larger than the counter is the floor the 2 are added to',
        $as($uns, 822, $uid, ['terminal' => ScanOutcome::CANCELLED, 'detail_rows' => 1]) === 6);
}

/* =====================================================================
 * CL-5 / EE-3  THE SERVICE GIVES ITS WORKER A WAY TO TELL "DELETED"
 *
 * ScanService hands the worker an `exists` probe over the project's record
 * index, and the worker will only call a record it could not read a TOMBSTONE
 * when the probe confirms it is gone. Deleting that one line from the service
 * left every suite green: each worker test builds its own dependencies, and
 * the service's were reachable only through a module, a user, an entitlement
 * and a database. A record deleted mid-run then ended UNREADABLE, which caps
 * coverage at partial.
 *
 * So the worker below is built from the service's own dependencies
 * (workerDeps(), reached by reflection - it is private and stays so), over the
 * real RecordManifestSource on a stand-in record index. Three are replaced,
 * because they need tables this fixture does not have and are not under test:
 * the installation slots (null is unrationed), the uniqueness finalizer, and
 * the summary builder. The change log is absent, so there is no fence: the
 * deleted record leaves no log entry to be found by.
 * ===================================================================== */
{
    $cs = new ArrayScanStore();
    $cid = (int) $cs->startRun(149, ['created_by' => 'alice'])['run']['run_id'];
    $cs->writeManifest($cid, [['id_bin' => 'R001', 'hash' => hash('sha256', 'R001', true)],
                              ['id_bin' => 'GONE1', 'hash' => hash('sha256', 'GONE1', true)]]);
    $svcC = new ScanService(new \SourceModule());
    $depsOf = new \ReflectionMethod(ScanService::class, 'workerDeps');
    $depsOf->setAccessible(true);
    $ctxC = [
        // The export returns R001 and leaves GONE1 out, every time it is asked.
        'read' => function (array $ids) {
            $d = [];
            foreach ($ids as $id) if ((string) $id === 'R001') $d['R001'] = ['1' => ['a' => 'x']];
            return ['ok' => true, 'data' => $d, 'why' => null];
        },
        'evaluate' => function ($id, $node) {
            return ['findings' => [], 'bytes' => 0, 'contexts' => 1, 'why' => null];
        },
    ];
    $deps = $depsOf->invoke($svcC, 149, $cs, $cs->run(149, $cid), ScanPolicy::resolve([], []),
                            $ctxC, 'browser');
    check('CL-5: the service hands its worker an existence probe',
        isset($deps['exists']) && is_callable($deps['exists']));
    $deps = array_merge($deps, ['slots' => null, 'finalizer' => new \SvcNoDuplicates(),
                                'rollup' => null]);
    $cpasses = 0;
    do {
        $cpasses++;
        $cres = (new ScanWorker($cs, $deps))->work(149, $cid);
    } while (empty($cres['done']) && $cpasses < 20);
    $cstates = $cs->recordStates($cid);
    check('CL-5: a record missing from the export and from the project ends as a TOMBSTONE',
        isset($cstates[ScanStore::REC_TOMBSTONE]) && (int) $cstates[ScanStore::REC_TOMBSTONE] === 1
        && empty($cstates[ScanStore::REC_UNREADABLE]));
    check('CL-5: and the record that is there was examined',
        isset($cstates[ScanStore::REC_DONE]) && (int) $cstates[ScanStore::REC_DONE] === 1);
    $cp = ScanPromotion::promote($cs, 149, $cid, ['uniqueDone' => true, 'rollupDone' => true]);
    check('CL-5: so the run covers its whole list - a deleted record is not a hole',
        $cp['promoted'] === true && $cp['outcome']['coverage'] === ScanOutcome::MANIFEST
        && $cp['outcome']['coverage'] !== ScanOutcome::COV_PARTIAL);
}

/* =====================================================================
 * CL-6  THE LARGEST ATTEMPT SETTING STILL ENDS A RECORD
 *
 * The attempt counter saturates at 254 and the worker ends a record when
 * tries + 1 >= the setting, so `scan-system-record-attempts` = 300 made that
 * comparison false for ever: a record the read leaves out was requeued on every
 * pass, reported `requeued > 0`, and the browser read that as progress and asked
 * again at 0 ms. The policy is clamped; this drives the real worker under the
 * policy the setting resolves to, one record per pass, and asks that the record
 * that is gone from the project gets an ending.
 * ===================================================================== */
{
    $policy = ScanPolicy::resolve(['scan-system-record-attempts' => '300'], []);
    $store = new ArrayScanStore();
    $runId = (int) $store->startRun(800, ['created_by' => 'alice'])['run']['run_id'];
    $store->writeManifest($runId, [['id_bin' => 'A', 'hash' => hash('sha256', 'A', true)],
                                   ['id_bin' => 'GONE', 'hash' => hash('sha256', 'GONE', true)]]);
    $read = function ($ids) {
        $d = [];
        foreach ($ids as $id) if ($id !== 'GONE') $d[$id] = ['1' => ['x' => 'v']];
        return ['ok' => true, 'data' => $d, 'why' => null];
    };
    $passes = 0;
    $states = [];
    while ($passes < 400) {
        $passes++;
        $w = new ScanWorker($store, [
            'read' => $read, 'attempts' => $policy['recordAttempts'], 'owner' => 'w1',
            'evaluate' => function ($id, $node) {
                return ['findings' => [], 'bytes' => 0, 'contexts' => 1, 'why' => null];
            },
            'finalizer' => new \SvcNoDuplicates(),
            'exists' => function (array $ids) { return array_fill_keys($ids, false); },
            'budget' => new WorkBudget(['mode' => 'browser', 'memoryLimit' => null,
                'timeLimit' => null, 'min' => 1, 'max' => 1, 'first' => 1,
                'startedAt' => microtime(true) - 2.8])]);
        $res = $w->work(800, $runId);
        $states = $store->recordStates($runId);
        if (!empty($res['done'])) break;
    }
    check('CL-6: an attempt setting past the counter is held at the counter\'s ceiling',
        $policy['recordAttempts'] === 254);
    check('CL-6: and under it a record the project no longer holds reaches an ending',
        isset($states[ScanStore::REC_TOMBSTONE]) && (int) $states[ScanStore::REC_TOMBSTONE] === 1
        && empty($states[ScanStore::REC_PENDING]) && empty($states[ScanStore::REC_CLAIMED]));
    check('CL-6: in a bounded number of passes, not by running out of test',
        $passes < 400);
}

/* =====================================================================
 * B10  A PLANNING RUN NOBODY IS LISTING IS PAUSED, THROUGH status() ITSELF
 *
 * progressOf() decides; status() is what the page is sent. R2O-3 told a run
 * whose planning had gone quiet for 15 minutes to stop and start again, and
 * hid Continue; since B10 the list resumes from its cursor, so the verb is
 * asked over run rows whose clock is a minute, five minutes and three hours
 * old, and must never send the reader to Stop.
 * ===================================================================== */
{
    $V = 'INSPIRE\UniversalValidator\ScanPageView';
    $pm = new \VerbModule();
    $pm->runRow = \VerbModule::row(['phase' => ScanPhase::PLANNING, 'manifest_total' => 0,
        'created_at' => gmdate('Y-m-d H:i:s', time() - 3 * 3600),
        'updated_at' => gmdate('Y-m-d H:i:s', time() - 3 * 3600)]);
    $pst = (new ScanService($pm))->status(149, 77);
    $said = $V::stalledText($pst);
    check('B10 status: a planning run nobody has listed for three hours is paused, not stopped',
        $pst['ok'] === true && $pst['active'] === true && $pst['paused'] === true
        && $pst['stalled'] === false && !array_key_exists('planningStopped', $pst));
    check('B10 status: and the panel says how long, and to press Continue',
        is_string($said) && strpos($said, 'for 180 minutes') !== false
        && strpos($said, 'Press Continue') !== false && strpos($said, 'start a new') === false);
    // A planner still inside the stall threshold is left alone, as CL-3
    // intended.
    $pm->runRow['created_at'] = gmdate('Y-m-d H:i:s', time() - 60);
    $pm->runRow['updated_at'] = $pm->runRow['created_at'];
    $fresh = (new ScanService($pm))->status(149, 77);
    check('B10 status: one planning for a minute is told nothing',
        $fresh['paused'] === false && $V::stalledText($fresh) === null);
    $pm->runRow['created_at'] = gmdate('Y-m-d H:i:s', time() - 300);
    $pm->runRow['updated_at'] = $pm->runRow['created_at'];
    $left = (new ScanService($pm))->status(149, 77);
    check('B10 status: one nobody has listed for five minutes is paused, and told to press Continue',
        $left['paused'] === true && strpos((string) $V::stalledText($left), 'Press Continue') !== false);
}

/* =====================================================================
 * R2O-4  A REFUSAL SAYS WHETHER ASKING AGAIN CAN HELP
 *
 * A watching page ended its watch on every ok:false, including the
 * endpoint's catch-all for a database blip, and never learned the run had
 * finished. status() now marks the refusals that will not change - no such
 * run, not this caller's to read - as final, and leaves every other one
 * unmarked so the page keeps asking. Driven through the real verb.
 * ===================================================================== */
{
    $ask = function (callable $setUp) {
        $m = new \VerbModule();
        $m->runRow = \VerbModule::row([]);
        $setUp($m);
        return (new ScanService($m))->status(149, 77);
    };
    $ok = $ask(function ($m) {});
    check('R2O-4: the fixture reaches a real answer, or nothing below proves anything',
        $ok['ok'] === true && !array_key_exists('final', $ok));

    $none = $ask(function ($m) { $m->runRow = null; });
    check('R2O-4: no such run is final',
        $none['ok'] === false && $none['why'] === ScanService::NO_RUN && $none['final'] === true);
    $other = $ask(function ($m) { $m->runRow['project_id'] = 150; });
    check('R2O-4: and so is another project\'s run, in the same words',
        $other['why'] === ScanService::NO_RUN && $other['final'] === true);
    $noDesign = $ask(function ($m) { $m->user->design = false; });
    check('R2O-4: a user without design rights is refused for good',
        $noDesign['ok'] === false && strpos($noDesign['why'], 'design rights') !== false
        && $noDesign['final'] === true);
    $barred = $ask(function ($m) { $m->user->rights['forms'] = ['fa' => '0']; });
    check('R2O-4: a user barred from the run\'s instrument is refused for good',
        $barred['ok'] === false && $barred['final'] === true);
    $noRights = $ask(function ($m) { $m->user->rights = 'unreadable'; });
    check('R2O-4: rights in a shape that cannot be read are a decision too, and final',
        $noRights['ok'] === false && $noRights['final'] === true);

    $threw = $ask(function ($m) { $m->user = 'throw'; });
    check('R2O-4: rights that could not be READ - the read threw - are not final',
        $threw['ok'] === false && strpos($threw['why'], 'Could not verify your rights') === 0
        && $threw['final'] === false);
    $noRules = $ask(function ($m) {
        $m->ctx = ['ok' => false, 'why' => 'the rule list could not be read: RuntimeException'];
    });
    check('R2O-4: a rule list that could not be loaded is not final either',
        $noRules['ok'] === false && $noRules['final'] === false);

    // R3U-1, R3U-N1, R4-1..3: status() asks the other project about the
    // lookups the RUN made (its stored policy), for this reader, through the
    // module. Final only when every refused lookup was refused by a decision.
    $L300 = ['pid' => 300, 'fields' => ['specimen_id']];
    $L400 = ['pid' => 400, 'fields' => ['record']];
    $stored = function ($policyJson, $refusals) use ($ask) {
        $seen = null;
        $r = $ask(function ($m) use ($policyJson, $refusals, &$seen) {
            $m->runRow['policy_json'] = $policyJson;
            $m->refusals = $refusals;
            $seen = $m;
        });
        return [$r, $seen];
    };
    $two = json_encode(['crossLookups' => [$L300, $L400]]);
    list($ok, $okm) = $stored($two, []);
    check('R4 status: a run whose lookups the other projects all answer is shown', $ok['ok'] === true);
    check('R4 status: and the module was asked about exactly the lookups the run stored',
        $okm->asked === [[$L300, $L400]]);
    list($dec) = $stored($two, [['pid' => 300, 'why' => 'no rights', 'unread' => false]]);
    check('R3U-1 status: a refusal project 300 decided is final and says it does not answer',
        $dec['ok'] === false && $dec['final'] === true
        && strpos($dec['why'], 'project 300, which does not answer your lookups') !== false);
    list($unr) = $stored($two, [['pid' => 300, 'why' => 'blip', 'unread' => true]]);
    check('R3U-1 status: a refusal on a failed read is refused, not final, and says it could not be read',
        $unr['ok'] === false && $unr['final'] === false
        && strpos($unr['why'], 'project 300, and whether it answers your lookups could not be read just now') !== false);
    list($mix) = $stored($two, [['pid' => 300, 'why' => 'no rights', 'unread' => false],
                                ['pid' => 400, 'why' => 'blip', 'unread' => true]]);
    check('R3U-1 status: one decided and one unread is not final, and names the decided one',
        $mix['ok'] === false && $mix['final'] === false
        && strpos($mix['why'], 'project 300, which does not answer') !== false && strpos($mix['why'], '400') === false);
    list($vague) = $stored($two, [['pid' => 300, 'why' => 'no rights']]);
    check('R3U-1 status: a refusal that does not say it was decided is not called one',
        $vague['ok'] === false && $vague['final'] === false);
    list($threw) = $stored($two, 'throw');
    check('R4 status: a module that cannot answer refuses the run, not for good',
        $threw['ok'] === false && $threw['final'] === false && strpos($threw['why'], 'could not be read') !== false);
    list($odd) = $stored($two, null);
    check('R4 status: and so does an answer that is not a list', $odd['ok'] === false && $odd['final'] === false);
    list($none, $nonem) = $stored('{"crossLookups":[]}', 'throw');
    check('R4 status: a run that looked nothing up in another project is shown without asking',
        $none['ok'] === true && $nonem->asked === []);
    foreach (['policy with no list' => '{"valueMode":"none"}', 'empty policy' => '{}', 'list policy' => '[]'] as $what => $legacy) {
        list($r) = $stored($legacy, []);
        check('R4 status: a ' . $what . ' never recorded its lookups, so the run is refused for good, with the remedy',
            $r['ok'] === false && $r['final'] === true && strpos($r['why'], 'start a new scan') !== false);
    }
    foreach (['not json' => '{crossLookups', 'a null column' => null, 'a scalar' => '"x"',
              'a list that is not a list' => '{"crossLookups":300}'] as $what => $bad) {
        list($r) = $stored($bad, []);
        check('R4 status: ' . $what . ' in the stored policy is refused, not final',
            $r['ok'] === false && $r['final'] === false && strpos($r['why'], 'could not be read') !== false);
    }
    // The reader's plan no longer decides: a plan that refuses 300 does not
    // refuse a run that made no lookup there, and a plan that answers 300
    // does not open a run whose stored lookup 300 refuses.
    $planSays = $ask(function ($m) {
        $m->ctx['plan'] = ['crossRefused' => [300], 'crossUnread' => []] + $m->ctx['plan'];
    });
    check('R4-3 status: the rules as they stand do not refuse a run that never looked in 300', $planSays['ok'] === true);

    $sp = \INSPIRE\UniversalValidator\Scan\ScanPlanner::storedPolicy(
        ['policy' => ['valueMode' => 'none'], 'crossLookups' => [$L400, $L300, $L400]]);
    check('R4 planner: the stored policy keeps the settings and adds each lookup once, in one order',
        $sp === ['valueMode' => 'none', 'crossLookups' => [$L300, $L400]]);
    check('R4 planner: an empty list is stored as one, so status() can tell it from no list',
        \INSPIRE\UniversalValidator\Scan\ScanPlanner::storedPolicy(['policy' => [], 'crossLookups' => []])
            === ['crossLookups' => []]);
    check('R4 planner: a request that does not say stores no list, which status() refuses',
        !array_key_exists('crossLookups', \INSPIRE\UniversalValidator\Scan\ScanPlanner::storedPolicy(['policy' => []]))
        && !array_key_exists('crossLookups', \INSPIRE\UniversalValidator\Scan\ScanPlanner::storedPolicy(
            ['policy' => [], 'crossLookups' => null])));
}

/* =====================================================================
 * R2O-5  STOP ON A RUN NOBODY IS WORKING ENDS IT
 *
 * Stop bumped the epoch and left the run in `cancelling`, which only a later
 * work request or the next Start finished - so a run nobody was driving read
 * "Press Continue to finish stopping it" and kept the project's only slot.
 * stopRun() is the body cancel() runs after its gates, driven here over the
 * in-memory store so that the run, the slot and a worker that comes back are
 * all real; cancel() itself is driven after it.
 * ===================================================================== */
{
    $stopRun = new \ReflectionMethod(ScanService::class, 'stopRun');
    $stopRun->setAccessible(true);
    // The body uses only the store it is handed.
    $svc = new ScanService(new \NoDbModule());
    $NOW = time();
    $t = function ($s) use ($NOW) { return gmdate('Y-m-d H:i:s', $NOW + $s); };
    $h = function ($id) { return hash('sha256', $id, true); };
    $fresh = function ($pid) use ($h) {
        $store = new ArrayScanStore();
        $runId = (int) $store->startRun($pid, ['created_by' => 'alice', 'policy_json' => '{"crossLookups":[]}'])['run']['run_id'];
        $store->writeManifest($runId, [['id_bin' => 'A', 'hash' => $h('A'), 'dag' => null],
                                       ['id_bin' => 'B', 'hash' => $h('B'), 'dag' => null]]);
        return [$store, $runId];
    };
    $doneBatch = function (ScanStore $store, $pid, $runId, array $claimed) use ($h) {
        $gen = (int) $store->run($pid, $runId)['generation_id'];
        $b = ['bytes' => 0, 'records' => [], 'findings' => []];
        foreach ($claimed as $c) {
            $b['records'][] = ['ordinal' => $c['ordinal'], 'state' => ScanStore::REC_DONE,
                               'version' => '100', 'claim' => $c['claim'], 'record_hash' => $c['hash']];
            $b['findings'][] = ['ordinal' => $c['ordinal'], 'project_id' => $pid,
                'generation_id' => $gen, 'identity' => $h('f' . $c['id_bin']),
                'record_hash' => $c['hash'], 'record_id_bin' => $c['id_bin'],
                'host_form' => 'fa', 'field' => 'a_val', 'rule_source_id' => 'r1',
                'rule_revision' => str_repeat('0', 64), 'rule_ord' => 1,
                'check_type' => 'required', 'reason_code' => 'required-blank',
                'event_id' => 1, 'instance' => 1, 'dag_key' => null, 'valid_from_seq' => 1];
        }
        return $b;
    };
    $isOver = function (ScanStore $store, $pid, $runId) {
        $r = $store->run($pid, $runId);
        return $r['phase'] === ScanPhase::TERMINAL && $r['terminal'] === ScanOutcome::CANCELLED;
    };

    // NOBODY IS WORKING IT. A worker took a batch, then its tab was closed;
    // the lease it took has run out.
    list($store, $runId) = $fresh(820);
    $epoch = (int) $store->run(820, $runId)['lease_epoch'];
    $claimed = $store->claim($runId, 'w1', $epoch, 1);
    $before = $store->run(820, $runId);
    $before['lease_expires_at'] = $t(-5);
    $r = $stopRun->invoke($svc, $store, 820, $runId, 'alice', $before, $NOW);
    check('R2O-5: Stop on a run nobody holds ENDS it, at once, as a cancelled run',
        $r['ok'] === true && $r['stopped'] === true && $isOver($store, 820, $runId));
    check('R2O-5: and the project slot is free - a new scan starts without anyone pressing Continue',
        $store->startRun(820, ['created_by' => 'bob'])['ok'] === true);
    // THE WORKER COMES BACK with the batch it claimed before the stop.
    check('R2O-5: a worker that comes back cannot commit into the finished run',
        $store->commitBatch($runId, 'w1', $epoch, $doneBatch($store, 820, $runId, $claimed)) !== true);
    check('R2O-5: nor claim any more of it', $store->claim($runId, 'w1', $epoch, 5) === false);
    $end = $store->run(820, $runId);
    check('R2O-5: and the run is exactly as the stop left it - nothing examined, nothing found',
        $isOver($store, 820, $runId) && (int) $end['detail_rows'] === 0
        && count($store->allFindings()) === 0
        && !isset($store->recordStates($runId)[ScanStore::REC_DONE]));
    check('R2O-5: a stop on a run that has ended is refused, as it always was',
        $stopRun->invoke($svc, $store, 820, $runId, 'alice', $end, $NOW)['ok'] === false);

    // A LEASE LEFT BEHIND is not a worker: live, but taken ten minutes ago.
    list($store, $runId) = $fresh(822);
    $before = $store->run(822, $runId);
    $before['lease_expires_at'] = $t(120);
    $before['updated_at'] = $t(-600);
    check('R2O-5: a lease left by a closed tab does not hold the stop open',
        $stopRun->invoke($svc, $store, 822, $runId, 'alice', $before, $NOW)['stopped'] === true
        && $isOver($store, 822, $runId));

    // A WORKER HOLDS IT: its lease is live and was taken a second ago. That is
    // the case left as it was - the worker's own request ends it.
    list($store, $runId) = $fresh(823);
    $before = $store->run(823, $runId);
    $before['lease_expires_at'] = $t(299);
    $before['updated_at'] = $t(-1);
    $r = $stopRun->invoke($svc, $store, 823, $runId, 'alice', $before, $NOW);
    check('R2O-5: a run a worker holds is asked to stop and left for that worker to finish',
        $r['ok'] === true && $r['stopped'] === false
        && $store->run(823, $runId)['phase'] === ScanPhase::CANCELLING);
    check('R2O-5: so it keeps the slot until then', $store->startRun(823, [])['ok'] === false);
    (new ScanWorker($store, ['owner' => 'w1', 'attempts' => 3]))->work(823, $runId);
    check('R2O-5: and the worker\'s next request finishes it, as before',
        $isOver($store, 823, $runId) && $store->startRun(823, [])['ok'] === true);

    // A SECOND STOP on a run left `cancelling` finishes it: nothing holds a
    // run in that phase.
    list($store, $runId) = $fresh(824);
    $before = $store->run(824, $runId);
    $before['lease_expires_at'] = $t(299);
    $before['updated_at'] = $t(-1);
    $stopRun->invoke($svc, $store, 824, $runId, 'alice', $before, $NOW);
    $again = $store->run(824, $runId);
    check('R2O-5: pressing Stop again on a run still stopping ends it',
        $again['phase'] === ScanPhase::CANCELLING
        && $stopRun->invoke($svc, $store, 824, $runId, 'alice', $again, $NOW)['stopped'] === true
        && $isOver($store, 824, $runId));

    // A FINISHING PHASE renews no lease, so a stop there ends the run, and
    // catch-up's writes from the old epoch are refused.
    list($store, $runId) = $fresh(825);
    $epoch = (int) $store->run(825, $runId)['lease_epoch'];
    $store->commitBatch($runId, 'w1', $epoch,
        $doneBatch($store, 825, $runId, $store->claim($runId, 'w1', $epoch, 2)));
    $store->advancePhase($runId, $epoch, ScanPhase::CATCH_UP);
    $before = $store->run(825, $runId);
    $before['lease_expires_at'] = $t(200);
    check('R2O-5: a stop in catch-up ends the run',
        $stopRun->invoke($svc, $store, 825, $runId, 'alice', $before, $NOW)['stopped'] === true
        && $isOver($store, 825, $runId));
    check('R2O-5: and catch-up coming back cannot tombstone or requeue into it',
        $store->tombstone($runId, $epoch, ['A']) === 0 && $store->requeue($runId, $epoch, ['B']) === 0
        && $store->advancePhase($runId, $epoch, ScanPhase::UNIQUE) === false);

    // R2O-3's run: planning died. Stop alone now ends it and frees the slot,
    // and the dead planner's late write is refused.
    $store = new ArrayScanStore();
    $runId = (int) $store->startRun(826, ['created_by' => 'alice'])['run']['run_id'];
    $before = $store->run(826, $runId);
    $before['lease_expires_at'] = null;
    check('R2O-5: a run whose planning died is ended by Stop alone',
        $stopRun->invoke($svc, $store, 826, $runId, 'alice', $before, $NOW)['stopped'] === true
        && $isOver($store, 826, $runId) && $store->startRun(826, [])['ok'] === true);
    // A refused append is FALSE since B10, not 0: zero is "every record was
    // already there", and a planner must be able to tell that from "stop".
    check('R2O-5: and a planner that wakes up cannot add to it',
        $store->appendManifest($runId, [['id_bin' => 'Z', 'hash' => $h('Z'), 'dag' => null]]) === false
        && $store->freezeManifest($runId) === false);

    // THROUGH cancel() ITSELF, past its gates, over the real SqlScanStore.
    // The lease is the one cancel() reads; the order of the two writes is the
    // fence.
    $vm = new \VerbModule();
    $vm->runRow = \VerbModule::row([]);
    $r = (new ScanService($vm))->cancel(149, 77);
    $order = array_map(function ($w) {
        return strpos($w[0], 'SET cancel_requested_at') !== false ? 'cancel'
            : (strpos($w[0], 'SET phase = ?, terminal = ?') !== false ? 'finish' : 'other');
    }, $vm->writes);
    $order = array_values(array_filter($order, function ($o) { return $o !== 'other'; }));
    check('R2O-5 verb: cancel() on a run nobody holds says it has stopped, and it has',
        $r['ok'] === true && $r['stopped'] === true && $r['why'] === null
        && $vm->runRow['terminal'] === ScanOutcome::CANCELLED);
    check('R2O-5 verb: the epoch is bumped BEFORE the terminal write, never after',
        $order === ['cancel', 'finish']);
    $vm2 = new \VerbModule();
    $vm2->runRow = \VerbModule::row(['updated_at' => gmdate('Y-m-d H:i:s', time() - 1)]);
    $vm2->lease = gmdate('Y-m-d H:i:s', time() + 299);
    $r2 = (new ScanService($vm2))->cancel(149, 77);
    check('R2O-5 verb: a run a worker holds is only asked to stop',
        $r2['ok'] === true && $r2['stopped'] === false && $vm2->runRow['phase'] === 'cancelling'
        && $vm2->runRow['terminal'] === null);
}

/* =====================================================================
 * P1 / M11 / M12  ONE REQUEST ASKS EACH QUESTION ONCE
 *
 * A work request built the entitlement and the whole plan twice - for the work
 * and for the status it returns - ran every capability probe twice, and opened
 * the change log four times. One ScanService is one request, so its answers
 * are kept for its life and for nobody else's. Counted from outside: the
 * statements that reach the module, and the plans it is asked for.
 * ===================================================================== */
{
    $cm = new \CountingVerbModule();
    $cm->runRow = \VerbModule::row([]);
    $svc = new ScanService($cm);

    $a1 = $svc->available(149);
    $a2 = $svc->available(149);
    check('P1: the fixture passes the gate, so what follows is counted past it',
        $a1['ok'] === true && $a2 === $a1);
    check('M11: available() asked twice in one request checks the schema once',
        $cm->sent('MAX(version)') === 1
        && $cm->sent('SELECT table_name FROM information_schema.tables') === 1);

    $s1 = $svc->status(149, 77);
    $s2 = $svc->status(149, 77);
    check('M12: two answers about one run in one request build the plan once',
        $s1['ok'] === true && $s2['ok'] === true && $cm->plans === 1);
    check('P1: the record-enumeration probe available() made is the one the plan is handed',
        $cm->sent('FROM redcap_record_list WHERE project_id = ? LIMIT 1') === 1
        && isset($cm->lastOpts['capabilities']['recordEnumeration']['state'])
        && $cm->lastOpts['capabilities']['recordEnumeration']['state'] === \INSPIRE\UniversalValidator\ScanCapabilities::OK);
    check('P1: and the rest of the capability census is taken once', $cm->sent('SHOW GRANTS') === 1);
    check('M12: the plan is asked with this request\'s memo, so a second plan is never built either',
        isset($cm->lastOpts['memo']) && $cm->lastOpts['memo'] instanceof \ArrayObject);
    // NOT KEPT: the census. Two status() calls are two moments, and the second
    // must see what the first could not.
    check('P11: the census is never kept between two status() calls',
        $cm->sent('SELECT state, COUNT(*)') === 2);

    // Every input that changes the answer is in the key.
    $cm->user->name = 'bob';
    $svc->status(149, 77);
    check('M12: another reader in the same service is a new entitlement - rights, ceiling and '
        . 'cross-project answers are theirs', $cm->plans === 2);
    // One input at a time, so each part of the key is pinned on its own.
    $cm->runRow = \VerbModule::row(['generation_id' => 6]);
    $svc->status(149, 77);
    check('M12: so is another generation of the run', $cm->plans === 3);
    $cm->runRow = \VerbModule::row(['generation_id' => 6, 'run_seq' => 6]);
    $svc->status(149, 77);
    check('M12: and another run sequence, which the evaluator is bound to', $cm->plans === 4);
    $cm->runRow = \VerbModule::row(['generation_id' => 6, 'run_seq' => 6, 'scope_dag' => '7']);
    $cm->user->rights['group_id'] = 7;
    \REDCap::$groupNames = [7 => 'north'];
    $svc->status(149, 77);
    check('M12: and another scope', $cm->plans === 5);
    $cm->user->rights['group_id'] = null;
    \REDCap::$groupNames = [];

    // NEVER ACROSS REQUESTS: a new service asks again.
    $cm->runRow = \VerbModule::row([]);
    $cm->user->name = null;
    $fresh = new ScanService($cm);
    $fresh->available(149);
    $fresh->status(149, 77);
    check('P1: a new request asks every question again',
        $cm->plans === 6 && $cm->sent('MAX(version)') === 2 && $cm->sent('SHOW GRANTS') === 2);

    // The change log, opened once however many parts of the request need it.
    $fence = new \ReflectionMethod(ScanService::class, 'fence');
    $fence->setAccessible(true);
    $fm = new \CountingVerbModule();
    $fs = new ScanService($fm);
    $fence->invoke($fs, 149);
    $fence->invoke($fs, 149);
    $fence->invoke($fs, 149);
    check('P1: the change log is opened once per request, not once per caller',
        $fm->sent('SELECT log_event_table') === 1);
    $fence->invoke($fs, 150);
    check('P1: and per project', $fm->sent('SELECT log_event_table') === 2);
}

/* =====================================================================
 * P11  THE STATUS A WORK REQUEST RETURNS REUSES WHAT PROMOTION READ
 *
 * advanceRun() hands statusOf() the run row and census promote() read, and
 * only when promote() wrote nothing after reading them; tests/mysql/cases/
 * perf.php drives that over a real store, both ways. Here: statusOf() uses
 * what it is handed and reads what it is not.
 * ===================================================================== */
{
    $statusOf = new \ReflectionMethod(ScanService::class, 'statusOf');
    $statusOf->setAccessible(true);
    $km = new \CountingVerbModule();
    $km->runRow = \VerbModule::row([]);
    $ks = new ScanService($km);
    $row = $ks->status(149, 77);                  // warms the entitlement
    $before = count($km->log);
    $given = $statusOf->invoke($ks, 149, 77, ['run' => \VerbModule::row(['manifest_total' => 10]),
        'census' => [ScanStore::REC_DONE => 4, ScanStore::REC_PENDING => 6]]);
    $sent = array_slice($km->log, $before);
    $reads = function ($needle) use (&$sent) {
        return count(array_filter($sent, function ($s) use ($needle) { return strpos($s, $needle) !== false; }));
    };
    check('P11: a status handed the census promotion read does not count the records again',
        $row['ok'] === true && $given['ok'] === true && $given['done'] === 4
        && $reads('SELECT state, COUNT(*)') === 0);
    check('P11: nor read the run row again', $reads('SELECT run_id, project_id, scope_dag') === 0);
    $before = count($km->log);
    $statusOf->invoke($ks, 149, 77, ['run' => null, 'census' => null]);
    $sent = array_slice($km->log, $before);
    check('P11: and one handed nothing reads both, as status() does',
        $reads('SELECT state, COUNT(*)') === 1 && $reads('SELECT run_id, project_id, scope_dag') === 1);
}

/* =====================================================================
 * W8-N1  PROMOTION COUNTS THE WINDOW AND THE BLOCKING ITSELF
 *
 * The rebase that brought @UVEXISTS in kept `windowChanges =>
 * $this->aggregateTotal(...)` in advanceRun() after B4a had removed that
 * method, so every work request that reached promotion died with an Error and
 * the endpoint answered "could not be reached". Both of the inputs the service
 * read there on every pass are promote()'s own reads now, sent once, by the
 * pass that can promote - and a number the caller passes for either is
 * overridden, as violations already were.
 * ===================================================================== */
{
    check('W8-N1: the kind promotion counts is the kind catch-up writes',
        ScanPromotion::WINDOW_KINDS === CatchUp::K_WINDOW);
    $h = function ($id) { return hash('sha256', $id, true); };
    $finished = function ($pid, array $aggs) use ($h) {
        $store = new ArrayScanStore();
        $runId = (int) $store->startRun($pid, ['created_by' => 'alice', 'policy_json' => '{"crossLookups":[]}'])['run']['run_id'];
        $store->writeManifest($runId, [['id_bin' => 'A', 'hash' => $h('A'), 'dag' => null]]);
        $epoch = (int) $store->run($pid, $runId)['lease_epoch'];
        $c = $store->claim($runId, 'w1', $epoch, 1);
        $store->commitBatch($runId, 'w1', $epoch, ['bytes' => 0, 'findings' => [], 'records' => [[
            'ordinal' => $c[0]['ordinal'], 'state' => ScanStore::REC_DONE, 'version' => '100',
            'claim' => $c[0]['claim'], 'record_hash' => $c[0]['hash']]]]);
        $store->setProgressState($runId, $epoch, ['fenceTarget' => '500']);
        foreach ($aggs as $a) $store->addAggregate($runId, $a[0], 'x', 'y', $a[1], $a[2]);
        return [$store, $runId];
    };
    $ready = ['uniqueDone' => true, 'rollupDone' => true, 'crossRecordLookups' => true,
              // What a caller might still pass. Both are overridden.
              'windowChanges' => 0, 'blockingAggregates' => 0];

    list($store, $runId) = $finished(830, [[CatchUp::K_WINDOW, 2, 0]]);
    $p = ScanPromotion::promote($store, 830, $runId, $ready);
    check('W8-N1: a run with lookups and changes in its window is capped at its list, from the store',
        $p['promoted'] === true && $p['outcome']['coverage'] === ScanOutcome::MANIFEST);
    list($store, $runId) = $finished(831, []);
    check('W8-N1: and the same run with no change in its window keeps the fence',
        ScanPromotion::promote($store, 831, $runId, $ready)['outcome']['coverage'] === ScanOutcome::FENCED);
    list($store, $runId) = $finished(832, [['reconcile-unsettled', 1, 1]]);
    check('W8-N1: an aggregate that blocks coverage blocks it, whatever the caller said',
        ScanPromotion::promote($store, 832, $runId, $ready)['outcome']['coverage'] === ScanOutcome::COV_PARTIAL);

    // WHAT PROMOTION READ GOES BACK, and whether it wrote after reading it.
    list($store, $runId) = $finished(833, []);
    $done = ScanPromotion::promote($store, 833, $runId, $ready);
    check('P11: a promotion that finished the run says it wrote',
        $done['wrote'] === true && is_array($done['census']) && is_array($done['run']));
    $waiting = new ArrayScanStore();
    $wid = (int) $waiting->startRun(834, ['created_by' => 'alice'])['run']['run_id'];
    $waiting->writeManifest($wid, [['id_bin' => 'A', 'hash' => $h('A'), 'dag' => null]]);
    $np = ScanPromotion::promote($waiting, 834, $wid, $ready);
    check('P11: one that could not promote wrote nothing, and hands back the census it read',
        $np['promoted'] === false && $np['wrote'] === false
        && $np['census'] === $waiting->recordStates($wid)
        && (int) $np['run']['run_id'] === $wid);
    $over = ScanPromotion::promote($store, 833, $runId, $ready);
    check('P11: one asked about a finished run read the row and no census',
        $over['wrote'] === false && $over['census'] === null && is_array($over['run']));
}

/* =====================================================================
 * P10  WHAT A RUN HAS STORED
 *
 * commitBatch() keeps detail_rows and detail_bytes on the run row, and
 * nothing reported them, so nobody watching a scan could see what it was
 * adding to the installation's tables.
 * ===================================================================== */
{
    $pm = new \VerbModule();
    $pm->runRow = \VerbModule::row(['detail_rows' => '12345', 'detail_bytes' => '3565158']);
    $ps = (new ScanService($pm))->status(149, 77);
    check('P10: status reports the finding rows and value bytes the run has stored',
        $ps['ok'] === true && $ps['detailRows'] === 12345 && $ps['detailBytes'] === 3565158);
    $pm->runRow = \VerbModule::row(['detail_rows' => null, 'detail_bytes' => '-4']);
    $pz = (new ScanService($pm))->status(149, 77);
    check('P10: and an absent or negative counter as zero, never a negative size',
        $pz['detailRows'] === 0 && $pz['detailBytes'] === 0);
}

/* =====================================================================
 * MERGE GUARD  START, WORK AND STATUS, END TO END, WITH NO DATABASE
 *
 * From the merge of main until wave 8, every work request that reached
 * promotion threw an Error: advanceRun() called aggregateTotal(), which the
 * scan chain had deleted. No fast suite and no database case drove a work
 * request that far, so it shipped green. These drive the four verbs the page
 * calls, over ArrayScanStore (E2eModule), until the run is over - and assert
 * the row promotion stored and what status() says about it.
 * ===================================================================== */
{
    /**
     * Start, then work until the run is terminal (at most 25 requests). A
     * Throwable from any verb is caught and reported as `threw`, so a missing
     * method fails a check rather than ending the file.
     */
    $drive = function (\E2eModule $m, ArrayScanStore $store, $pid, $then = null) {
        $out = ['start' => null, 'passes' => 0, 'works' => [], 'threw' => null, 'row' => null,
                'status' => null, 'svc' => null];
        try {
            $svc = new ScanService($m, $store);
            $out['svc'] = $svc;
            $out['start'] = $svc->start($pid);
            if (empty($out['start']['ok'])) return $out;
            $runId = (int) $out['start']['run_id'];
            if ($then !== null) $then($svc, $runId);
            while ($out['passes'] < 25) {
                $out['passes']++;
                // A request is its own ScanService, as it is in production.
                $w = (new ScanService($m, $store))->work($pid, $runId);
                $out['works'][] = $w;
                $row = $store->run($pid, $runId);
                if ($row['terminal'] !== null || empty($w['ok'])) break;
            }
            $out['row'] = $store->run($pid, $runId);
            $out['status'] = (new ScanService($m, $store))->status($pid, $runId);
        } catch (\Throwable $e) {
            $out['threw'] = get_class($e) . ': ' . $e->getMessage();
        }
        return $out;
    };

    // -- a clean run ----------------------------------------------------------
    $cs = new ArrayScanStore();
    $cm = new \E2eModule($cs);
    $c = $drive($cm, $cs, 149);
    check('E2E clean: no verb threw' . ($c['threw'] ? ' (' . $c['threw'] . ')' : ''), $c['threw'] === null);
    check('E2E clean: start opened a run', !empty($c['start']['ok']) && (int) $c['start']['run_id'] > 0);
    check('E2E clean: every work request answered ok',
        $c['works'] && !array_filter($c['works'], function ($w) { return empty($w['ok']); }));
    $row = $c['row'];
    check('E2E clean: the run was promoted complete, through its fence, and certified clean',
        is_array($row) && $row['phase'] === ScanPhase::TERMINAL && $row['terminal'] === ScanOutcome::COMPLETE
        && $row['coverage'] === ScanOutcome::FENCED && $row['detail'] === ScanOutcome::DETAIL_COMPLETE
        && (int) $row['clean'] === 1 && (int) $row['rule_problem_count'] === 0
        && (int) $row['not_examined'] === 0 && (string) $row['fence_target'] === '100');
    $st = $c['status'];
    check('E2E clean: and status() says so: finished, every record examined, nothing found',
        !empty($st['ok']) && $st['terminal'] === ScanOutcome::COMPLETE && $st['active'] === false
        && $st['clean'] === 1 && $st['findings'] === 0 && $st['total'] === 3 && $st['done'] === 3
        && $st['settled'] === 3 && $st['ruleProblems'] === 0 && $st['notExamined'] === 0);
    $last = end($c['works']);
    check('E2E clean: the work request that finished it carries the same status',
        isset($last['status']['terminal']) && $last['status']['terminal'] === ScanOutcome::COMPLETE);

    // -- THE LABELS OF THE DAY THE RUN WAS PLANNED (M5, W7-N11) ------------------
    //
    // start() builds the module's ScanDimensions over the rule list the
    // findings' ordinals refer to, the planner stores it under the run's
    // generation, and the pass that finishes the run reads its state row for
    // `labelDegraded`, which no caller supplied before.
    $labelled = function (ArrayScanStore $s) {
        return new class($s) extends \E2eModule {
            public $dimsAsked = false;
            public $dimsFail = false;
            /** @var string the form's label in the dictionary now */
            public $formLabel = 'Form A';
            /** @var array the identifier flags the dictionary has now */
            public $liveIds = [];
            /** @var array the identifier flags the run is planned with */
            public $snapIds = ['a_val' => true];
            public function durableScanContext($pid, array $opts = [], $dag = null)
            {
                $c = parent::durableScanContext($pid, $opts, $dag);
                $c['plan']['allRules'] = $this->rules;
                $c['plan']['ruleIds'] = [0 => ['source_id' => 'r1']];
                $c['plan']['identifiers'] = $this->liveIds;
                return $c;
            }
            public function scanDimensions($pid, ?array $rules = null)
            {
                $this->dimsAsked = $rules;
                if ($this->dimsFail) throw new \RuntimeException('no labels today');
                $d = new \INSPIRE\UniversalValidator\ScanDimensions();
                $d->forms = ['fa' => $this->formLabel];
                $d->fieldLabels = ['a_val' => 'Value A'];
                $d->fieldForms = ['a_val' => 'fa'];
                $d->identifiers = $this->snapIds;
                $d->singleEventProved = true;
                $d->rules = [1 => ['type' => 'required', 'label' => 'A is required', 'message' => '',
                                   'messages' => [], 'assert' => '', 'fields' => ['a_val'], 'detail' => []]];
                return $d;
            }
        };
    };
    $ds = new ArrayScanStore();
    $dm = $labelled($ds);
    $dd = $drive($dm, $ds, 171);
    $drow = $dd['row'];
    $dgen = is_array($drow) ? (int) $drow['generation_id'] : 0;
    $dsnap = \INSPIRE\UniversalValidator\ScanDimensions::fromSnapshot($ds->dimensions(171, $dgen));
    check('M5: start stores the labels under the run\'s generation, for this project only',
        $dd['threw'] === null && $dsnap !== null && $dsnap->forms === ['fa' => 'Form A']
        && $dsnap->fieldLabels === ['a_val' => 'Value A'] && $dsnap->identifiers === ['a_val' => true]
        && $dsnap->rules[1]['label'] === 'A is required' && $dsnap->rulesBySource === ['r1' => 1]
        && $ds->dimensions(172, $dgen) === []);
    check('M5: built over the rule list the findings\' ordinals refer to', $dm->dimsAsked === $dm->rules);
    check('W7-N11: a run planned with every label read is certified clean without the labels sentence',
        is_array($drow) && (int) $drow['clean'] === 1
        && strpos((string) $drow['terminal_reason'], 'labels could not be read') === false);

    $us2 = new ArrayScanStore();
    $um2 = $labelled($us2);
    $um2->dimsFail = true;
    $u2 = $drive($um2, $us2, 173);
    $u2row = $u2['row'];
    $u2snap = is_array($u2row)
        ? \INSPIRE\UniversalValidator\ScanDimensions::fromSnapshot($us2->dimensions(173, (int) $u2row['generation_id']))
        : null;
    check('W7-N11: labels that could not be read at planning do not stop the scan, and are kept as such',
        $u2['threw'] === null && is_array($u2row) && $u2row['terminal'] === ScanOutcome::COMPLETE
        && $u2snap !== null && isset($u2snap->degraded['labels']) && $u2snap->forms === []);
    check('W7-N11: and the finished run says some labels could not be read',
        (int) $u2row['clean'] === 1
        && strpos((string) $u2row['terminal_reason'], 'some labels could not be read') !== false);

    $fi = new ArrayScanStore();
    $fm = $labelled($fi);
    $fst = (new ScanService($fm, (new FaultyStore($fi))->failFrom('writeDimensions')))->start(174);
    $frow = $fi->run(174, 1);
    check('M5: a start whose labels could not be stored is refused, in words',
        empty($fst['ok']) && strpos((string) $fst['why'], 'could not keep the names') !== false);
    check('M5: and its run is ended as failed rather than left holding the project',
        is_array($frow) && $frow['terminal'] === ScanOutcome::FAILED
        && !empty((new ScanService($fm, $fi))->start(174)['ok']));

    // -- THE REPORT OF A FINISHED RUN (B5) ----------------------------------------
    //
    // scan-findings names forms and rules as the run was planned (M5), names
    // records the way the module's audit log does (W7-R2), and withholds a
    // field flagged as an identifier when the run was planned OR now.
    $rs = new ArrayScanStore();
    $rm = $labelled($rs);
    $rm->flagged = ['R002' => true];
    $rm->snapIds = [];
    $rm->proj['scan-value-storage'] = 'raw';
    $rd = $drive($rm, $rs, 175);
    $rrun = is_array($rd['row']) ? (int) $rd['row']['run_id'] : 0;
    $rm->formLabel = 'Form B';
    $rpage = function () use ($rm, $rs, $rrun) { return (new ScanService($rm, $rs))->findings(175, $rrun); };
    $cell = function (array $p, $k) {
        return isset($p['rows'][0]['cells'][$k]) ? $p['rows'][0]['cells'][$k] : null;
    };
    $rp = $rpage();
    check('B5: a finished run\'s page carries its one finding, with values kept raw',
        !empty($rp['ok']) && count($rp['rows']) === 1 && $cell($rp, 'record') === 'R002'
        && $rp['report']['valuesState'] === 'raw' && $rp['report']['expectedRows'] === 1);
    check('B5 (M5): forms are named as the run was planned, not as the project calls them now',
        $cell($rp, 'instrument') === 'Form A' && $rp['facets']['form'][0]['label'] === 'Form A'
        && $rp['report']['labelsDegraded'] === false);
    // A run that kept values with identifiers removed: its values show,
    // except a field flagged as an identifier then OR now.
    $is = new ArrayScanStore();
    $im = $labelled($is);
    $im->flagged = ['R002' => true];
    $im->valueOf = ['R002' => 'v-002'];
    $im->snapIds = [];
    $im->proj['scan-value-storage'] = 'identifier-redacted';
    $irun = (int) $drive($im, $is, 176)['row']['run_id'];
    $ipage = function () use ($im, $is, $irun) { return (new ScanService($im, $is))->findings(176, $irun); };
    $ip = $ipage();
    check('B5 (control): in a run kept identifier-redacted, a field flagged neither then nor now shows its value',
        !empty($ip['ok']) && $ip['report']['valuesState'] === 'identifier-redacted' && $cell($ip, 'value') === 'v-002');
    $im->liveIds = ['a_val' => true];
    check('B5: a field flagged as an identifier since the run was planned is withheld',
        $cell($ipage(), 'value') === '[identifier withheld]');
    $im->liveIds = [];
    $im->user->rights['data_export_tool'] = '2';
    check('B5: a reader below Full Data Set is refused the report of a run that kept values, as status refuses it',
        $ipage()['ok'] === false && strpos($ipage()['why'], 'Full Data Set') !== false
        && $ipage()['why'] === (new ScanService($im, $is))->status(176, $irun)['why']);
    $im->user->rights['data_export_tool'] = '1';
    $rm->posture = ['recordIds' => FindingRowMapper::IDS_HASHED, 'recordIdField' => 'record_id',
                    'hashRecordId' => function ($id) { return 'h-' . md5('k|' . $id); }];
    $hp = $rpage();
    check('B5 (W7-R2): under hashed ids the page names the record by the module\'s hash and never by the id',
        $cell($hp, 'record') === 'h-' . md5('k|R002') && strpos(json_encode($hp), 'R002') === false);
    $rm->posture = 'unknown';
    $np = $rpage();
    check('B5: a module that cannot say how records are named shows no report',
        $np['ok'] === false && strpos($np['why'], 'cannot say how a report names records') !== false);
    $rm->posture = null;
    // A run planned before label snapshots were kept: today's names, and the
    // report says that is what they are.
    $oi = new ArrayScanStore();
    $os = new class($oi) extends FaultyStore {
        public function writeDimensions($projectId, $generationId, array $rows) { return 0; }
    };
    $om = $labelled($oi);
    $om->flagged = ['R002' => true];
    $orun = (int) (new ScanService($om, $os))->start(177)['run_id'];
    for ($i = 0; $i < 15 && $orun > 0 && $oi->run(177, $orun)['terminal'] === null; $i++) {
        (new ScanService($om, $os))->work(177, $orun);
    }
    $om->formLabel = 'Form B';
    $op = (new ScanService($om, $os))->findings(177, $orun);
    check('B5: a run with no label snapshot is named as the project is now, and says so',
        !empty($op['ok']) && $cell($op, 'instrument') === 'Form B' && $op['report']['labelsDegraded'] === true
        && strpos($op['report']['degradedSummary'], 'planned before its labels were kept') !== false);

    // -- the change log fails to read for one request (H11) ----------------------
    //
    // SourceFence::forProject() answered a failed read as "no fence", so the
    // request built its worker unfenced and took every record it read as
    // having held still. The request now stops as a storage failure, reads
    // nothing, and the next request runs fenced.
    $ls = new ArrayScanStore();
    $lm = new \E2eModule($ls);
    $l = $drive($lm, $ls, 160, function ($svc, $runId) use ($lm) { $lm->logFails = 1; });
    check('H11 E2E: no verb threw' . ($l['threw'] ? ' (' . $l['threw'] . ')' : ''), $l['threw'] === null);
    $first = isset($l['works'][0]) ? $l['works'][0] : null;
    check('H11 E2E: a request whose change log failed to read is refused as a storage failure',
        is_array($first) && $first['ok'] === false
        && strpos((string) $first['why'], 'could not reach its own storage') !== false);
    check('H11 E2E: and it read no record unfenced',
        is_array($l['row']) && (int) $l['row']['manifest_done'] === 0);
    $lrun = (int) $l['start']['run_id'];
    for ($i = 0; $i < 25; $i++) {
        $w = (new ScanService($lm, $ls))->work(160, $lrun);
        if (empty($w['ok']) || $ls->run(160, $lrun)['terminal'] !== null) break;
    }
    $lrow = $ls->run(160, $lrun);
    check('H11 E2E: the next requests run fenced and finish through the fence',
        $lrow['terminal'] === ScanOutcome::COMPLETE && $lrow['coverage'] === ScanOutcome::FENCED);

    // -- a configuration read in part ends nothing (review R5-4) -----------------
    //
    // One failed read on an unchanged project - its design, its Missing Data
    // Codes, another project's settings - moved the fingerprint, and the pass
    // that compared it ended the run failed. durableScanContext() now names
    // such reads as `unread`; start refuses, work refuses as one to retry, and
    // only a configuration read in full may end a run.
    $us = new ArrayScanStore();
    $um = new \E2eModule($us);
    $um->unread = ['the project\'s design'];
    $u0 = (new ScanService($um, $us))->start(161);
    check('R5-4 E2E: a scan is not started on a configuration read in part',
        empty($u0['ok']) && strpos((string) $u0['why'], 'could not be read just now') !== false
        && strpos((string) $u0['why'], 'the project\'s design') !== false);
    check('R5-4 E2E: and no run was opened for it', $us->run(161, 1) === null);
    $um->unread = [];
    $u1 = (new ScanService($um, $us))->start(161);
    $urun = (int) $u1['run_id'];
    $um->unread = ['the missing data codes'];
    $uw = (new ScanService($um, $us))->work(161, $urun);
    $urow = $us->run(161, $urun);
    check('R5-4 E2E: a work request on a configuration read in part is refused as one to retry',
        $uw['ok'] === false && $uw['stop'] === 'unread'
        && strpos((string) $uw['why'], 'the missing data codes') !== false);
    check('R5-4 E2E: and the run is left exactly where it was, not ended',
        $urow['terminal'] === null && $urow['phase'] !== ScanPhase::TERMINAL
        && (int) $urow['manifest_done'] === 0);
    $um->unread = [];
    for ($i = 0; $i < 25; $i++) {
        $w = (new ScanService($um, $us))->work(161, $urun);
        if (empty($w['ok']) || $us->run(161, $urun)['terminal'] !== null) break;
    }
    check('R5-4 E2E: read in full again, the same run finishes complete',
        $us->run(161, $urun)['terminal'] === ScanOutcome::COMPLETE);

    // -- a finished run is answered with its status (review R5-8) ---------------
    //
    // A late request from a second tab, sent after a design edit, was told the
    // run had been ended for a change, about a run that had finished complete.
    $um->structure = ['fields' => ['a_val' => ['form' => 'fa', 'type' => 'text', 'validation' => 'integer']]];
    $late = (new ScanService($um, $us))->work(161, $urun);
    check('R5-8 E2E: a late request for a finished run is not told the run was ended for a change',
        $late['ok'] === true && $late['stop'] === null && $late['why'] !== ScanService::CONFIG_CHANGED);
    check('R5-8 E2E: it is answered with the finished run\'s status',
        isset($late['status']['terminal']) && $late['status']['terminal'] === ScanOutcome::COMPLETE);
    check('R5-8 E2E: and the stored run is untouched',
        $us->run(161, $urun)['terminal'] === ScanOutcome::COMPLETE);
    $um->unread = ['the project\'s design'];
    $late = (new ScanService($um, $us))->work(161, $urun);
    check('R5-8 E2E: nor is a finished run refused for a configuration read in part',
        $late['ok'] === true && isset($late['status']['terminal'])
        && $late['status']['terminal'] === ScanOutcome::COMPLETE);
    $um->unread = [];

    // -- a tightened privacy policy stops the run (review R5-5) -----------------
    //
    // advanceRun() ended it FAILED with the rules sentence; the worker's own
    // guard, which stops it through finishCancelled() with the privacy
    // sentence, could not be reached because workerDeps() never armed it.
    $ps5 = new ArrayScanStore();
    $pm5 = new \E2eModule($ps5);
    $p5 = (new ScanService($pm5, $ps5))->start(162);
    $p5run = (int) $p5['run_id'];
    $pm5->proj['scan-value-retention-days'] = '7';      // the default keeps them longer
    $pw = (new ScanService($pm5, $ps5))->work(162, $p5run);
    $prow = $ps5->run(162, $p5run);
    check('R5-5 E2E: a privacy tightening during a run stops it: cancelled, not failed',
        $prow['terminal'] === ScanOutcome::CANCELLED);
    check('R5-5 E2E: and the stored reason is the privacy one',
        strpos((string) $prow['terminal_reason'], 'privacy settings were tightened') !== false);
    check('R5-5 E2E: the work answer says so, not that the rules or the design changed',
        $pw['ok'] === false && strpos((string) $pw['why'], 'privacy settings changed') !== false
        && $pw['why'] !== ScanService::CONFIG_CHANGED);
    check('R5-5 E2E: and nothing was examined after the tightening',
        (int) $prow['manifest_done'] === 0);
    // The rules moving is still the failed ending, with its own sentence.
    $rs5 = new ArrayScanStore();
    $rm5 = new \E2eModule($rs5);
    $r5 = (new ScanService($rm5, $rs5))->start(163);
    $r5run = (int) $r5['run_id'];
    $rm5->structure = ['fields' => ['a_val' => ['form' => 'fa', 'type' => 'text', 'validation' => 'date_ymd']]];
    $rw = (new ScanService($rm5, $rs5))->work(163, $r5run);
    check('R5-5 E2E: a design change during a run still ends it failed, as a change',
        $rs5->run(163, $r5run)['terminal'] === ScanOutcome::FAILED && $rw['stop'] === 'changed');

    // -- a run whose rules look values up in project 300 (R3U-N1, R4-1) ---------
    $ks = new ArrayScanStore();
    $km = new \E2eModule($ks);
    $kl = ['pid' => 300, 'fields' => ['specimen_id']];
    $km->crossLookups = [$kl];
    // review rv10b1-2: a starter project 300 refuses is refused the start.
    // Their plan skips the lookup and keeps the rule, so the run would have
    // been evaluated without it on their passes and with it on others'.
    $km->refusedBy = [300 => true];
    $k0 = (new ScanService($km, $ks))->start(152);
    check('rv10b1-2 E2E: a starter project 300 refuses is refused the start, in words that name it',
        empty($k0['ok']) && $k0['run_id'] === null && $ks->run(152, 1) === null
        && $k0['why'] === 'this scan looks values up in project 300, which does not answer your lookups, '
                        . 'so you cannot start this scan');
    // A starter it answers starts it, and the lookup is the rules', recorded
    // for whoever reads the run later.
    $km->refusedBy = [];
    $kRefusedWork = null;
    $k = $drive($km, $ks, 152, function ($svc, $runId) use ($km, $ks, &$kRefusedWork) {
        // review rv5b-5 / rv10b1-2: before any pass, a reader 300 refuses
        // asks to continue the run. Nothing is examined and nothing counted.
        $km->refusedBy = [300 => true];
        $kRefusedWork = (new ScanService($km, $ks))->work(152, $runId);
        $kRefusedWork['_done'] = (int) $ks->run(152, $runId)['manifest_done'];
        $km->refusedBy = [];
    });
    check('E2E cross: no verb threw' . ($k['threw'] ? ' (' . $k['threw'] . ')' : ''), $k['threw'] === null);
    $kp = is_array($k['row']) ? json_decode($k['row']['policy_json'], true) : null;
    check('E2E cross: the run keeps every lookup its rules make',
        is_array($kp) && isset($kp['crossLookups']) && $kp['crossLookups'] === [$kl]);
    check('E2E cross: and they are not part of the fingerprint, so every pass matched it and the run finished',
        is_array($k['row']) && $k['row']['terminal'] === ScanOutcome::COMPLETE);
    check('rv5b-5 E2E: a reader project 300 refuses is refused the work, final, and in words that name it',
        is_array($kRefusedWork) && $kRefusedWork['ok'] === false && $kRefusedWork['final'] === true
        && $kRefusedWork['why'] === 'this scan looks values up in project 300, which does not answer your '
                                  . 'lookups, so you cannot continue this scan');
    check('rv5b-5 E2E: and the refused work answer carries no counter and no status, and examined nothing',
        is_array($kRefusedWork) && !array_intersect(['worked', 'requeued', 'blocked', 'findings', 'listed', 'status'],
                                                    array_keys($kRefusedWork))
        && $kRefusedWork['_done'] === 0);
    $km->refusedBy = [300 => true];
    $k['status'] = (new ScanService($km, $ks))->status(152, (int) $k['start']['run_id']);
    check('E2E cross: a reader project 300 refuses is refused the run, for good',
        $k['status']['ok'] === false && $k['status']['final'] === true
        && strpos($k['status']['why'], 'project 300') !== false);
    $km->refusedBy = [];
    $kr = (new ScanService($km, $ks))->status(152, (int) $k['start']['run_id']);
    check('E2E cross: a reader project 300 answers is shown it', !empty($kr['ok']));
    $km->crossLookups = [];
    $km->refusedBy = [300 => true];
    $kr = (new ScanService($km, $ks))->status(152, (int) $k['start']['run_id']);
    check('E2E cross: removing the rule does not open the stored answers to a reader 300 refuses',
        $kr['ok'] === false && strpos($kr['why'], 'project 300') !== false);

    // review rv5b-5: THE RUN'S OWN LOOKUPS, asked apart from the plan. The
    // rule that looked in 300 is gone, so the reader's plan refuses nothing;
    // the run still holds 300's answers, so status() refuses the reader, and
    // the work is refused with it rather than handing over its counters.
    $ls = new ArrayScanStore();
    $lm = new \E2eModule($ls);
    $lm->crossLookups = [$kl];
    $lm->flagged = ['R002' => true];
    $lrun = (int) (new ScanService($lm, $ls))->start(157)['run_id'];
    $lm->crossLookups = [];
    $lm->refusedBy = [300 => true];
    $lw = (new ScanService($lm, $ls))->work(157, $lrun);
    check('rv5b-5: a reader the run\'s stored lookup refuses is refused the work, though their plan refuses nothing',
        $lrun > 0 && $lw['ok'] === false && $lw['final'] === true
        && $lw['why'] === 'this scan looks values up in project 300, which does not answer your lookups, '
                         . 'so you cannot continue this scan'
        && !isset($lw['findings']) && !isset($lw['worked']) && (int) $ls->run(157, $lrun)['manifest_done'] === 0);
    // One request asks the other project once: the work and the status it
    // returns are the same reader in the same request.
    $lm->refusedBy = [];
    $lm->crossLookups = [$kl];
    $asks = $lm->refusalAsks;
    $lw = (new ScanService($lm, $ls))->work(157, $lrun);
    check('rv5b-5: a reader it answers works the run, and the request asked the other project once',
        $lw['ok'] === true && !empty($lw['status']['ok']) && $lm->refusalAsks === $asks + 1);

    // review rv10b1-2: THE READER'S OWN PLAN, asked apart from the run's
    // lookups. The run below stored none, so status() and the run-lookup half
    // of the refusal let the reader through; a plan that says another project
    // refuses them is a plan that skips a rule, and the work is refused.
    $ps = new ArrayScanStore();
    $pm = new \E2eModule($ps);
    $prun = (int) (new ScanService($pm, $ps))->start(153)['run_id'];
    $pm->planRefused = [300];
    $pw = (new ScanService($pm, $ps))->work(153, $prun);
    check('rv10b1-2: a reader whose plan another project refuses is refused the work, final, though the run stored no lookup',
        $prun > 0 && $pw['ok'] === false && $pw['final'] === true
        && $pw['why'] === 'this scan looks values up in project 300, which does not answer your lookups, '
                         . 'so you cannot continue this scan'
        && (int) $ps->run(153, $prun)['manifest_done'] === 0);
    $pm->planRefused = [300, 400];
    $pm->planUnread = [400];
    $pw = (new ScanService($pm, $ps))->work(153, $prun);
    check('rv10b1-2: one refused by a decision and one by a failed read is not final, and names the decided one',
        $pw['ok'] === false && $pw['final'] === false
        && strpos($pw['why'], 'project 300, which does not answer') !== false && strpos($pw['why'], '400') === false);
    $pm->planRefused = [400];
    $pw = (new ScanService($pm, $ps))->work(153, $prun);
    check('rv10b1-2: a refusal that rests only on a failed read says so, and says yet',
        $pw['ok'] === false && $pw['final'] === false
        && $pw['why'] === 'this scan looks values up in project 400, and whether it answers your lookups could '
                         . 'not be read just now, so you cannot continue this scan yet');
    $pm->planUnread = [];
    $pm->planRefused = false;
    $pw = (new ScanService($pm, $ps))->work(153, $prun);
    check('rv10b1-2: a plan that does not say which projects refuse the reader is refused, not final (fails closed)',
        $pw['ok'] === false && $pw['final'] === false && strpos($pw['why'], 'could not be read') !== false);
    $pm->planRefused = null;
    $pw = (new ScanService($pm, $ps))->work(153, $prun);
    check('rv10b1-2: the same reader with a plan that refuses nothing works the run', $pw['ok'] === true
        && (int) $ps->run(153, $prun)['manifest_done'] > 0);
    $pm->planRefused = false;
    $p2 = (new ScanService($pm, new ArrayScanStore()))->start(154);
    check('rv10b1-2: and a start on a plan that does not say is refused too',
        empty($p2['ok']) && strpos((string) $p2['why'], 'so you cannot start this scan yet') !== false);
    $pm->planRefused = null;

    // review rv10b1-2: WHAT THIS REQUEST'S CONTEXT CANNOT EVALUATE IS WRITTEN
    // WITH ITS WORK. The run starts on a plan with no problem; every pass after
    // it evaluates with a context that has one. start() wrote nothing, so only
    // the passes can, and a run that skipped a rule may not finish clean.
    $qs = new ArrayScanStore();
    $qm = new \E2eModule($qs);
    $q = $drive($qm, $qs, 155, function ($svc, $runId) use ($qm) {
        $qm->problems = [['rule' => 1, 'fields' => ['a_val'],
                          'why' => 'this rule could not be evaluated by this request']];
    });
    check('rv10b1-2: a problem only the work passes met is recorded, and the run does not finish clean',
        $q['threw'] === null && is_array($q['row']) && $q['row']['terminal'] === ScanOutcome::COMPLETE
        && (int) $q['row']['rule_problem_count'] === 1 && (int) $q['row']['clean'] === 0);

    // THE CRON PATH (review rv10b1-2). No cron in config.json does scan work:
    // the two that exist reap abandoned runs and clear expired values, and the
    // module's one work() call is the browser endpoint. A work request with no
    // signed-in user - which is what a cron pass would be - is refused at the
    // entitlement, before any plan is built, so it can skip no rule.
    $cs2 = new ArrayScanStore();
    $cm2 = new \E2eModule($cs2);
    $crun = (int) (new ScanService($cm2, $cs2))->start(156)['run_id'];
    $cm2->user = null;
    $built = $cm2->contextsBuilt;
    $cw = (new ScanService($cm2, $cs2))->work(156, $crun, 'cron');
    check('rv10b1-2 cron: a work request with no signed-in user is refused before a plan is built, and examines nothing',
        $crun > 0 && $cw['ok'] === false
        && $cw['why'] === 'You need project design rights to run the validation scan.'
        && $cm2->contextsBuilt === $built && (int) $cs2->run(156, $crun)['manifest_done'] === 0
        && !array_intersect(['worked', 'findings', 'status'], array_keys($cw)));
    $cron = json_decode(file_get_contents(__DIR__ . '/../config.json'), true);
    $methods = array_map(function ($c) { return $c['method']; }, $cron['crons']);
    sort($methods);
    check('rv10b1-2 cron: the crons are the reap and the value expiry, and neither is a work pass',
        $methods === ['uvScanExpireValuesCron', 'uvScanReapCron']);

    // -- a run with a finding ---------------------------------------------------
    $fs = new ArrayScanStore();
    $fm = new \E2eModule($fs);
    $fm->flagged = ['R002' => true];
    $f = $drive($fm, $fs, 150);
    check('E2E findings: no verb threw' . ($f['threw'] ? ' (' . $f['threw'] . ')' : ''), $f['threw'] === null);
    $row = $f['row'];
    check('E2E findings: the run covers the project and is not clean, because it found something',
        is_array($row) && $row['terminal'] === ScanOutcome::COMPLETE && $row['coverage'] === ScanOutcome::FENCED
        && (int) $row['clean'] === 0 && (int) $row['rule_problem_count'] === 0
        && (int) $row['not_examined'] === 0);
    $st = $f['status'];
    check('E2E findings: status() counts the finding from the summary the rollup built',
        !empty($st['ok']) && $st['clean'] === 0 && $st['findings'] === 1 && $st['done'] === 3);

    // -- a finding, a record that cannot be examined, and a rule problem ---------
    $ps = new ArrayScanStore();
    $pm = new \E2eModule($ps);
    $pm->flagged = ['R001' => true];
    $pm->broken = ['R003' => true];
    $pm->problems = [['rule' => 2, 'fields' => ['b_val'], 'why' => 'rule 2 cannot be evaluated', 'code' => 'c2']];
    $p = $drive($pm, $ps, 151);
    check('E2E partial: no verb threw' . ($p['threw'] ? ' (' . $p['threw'] . ')' : ''), $p['threw'] === null);
    $row = $p['row'];
    check('E2E partial: a record that could not be examined caps the run at partial, with the counts stored',
        is_array($row) && $row['terminal'] === ScanOutcome::PARTIAL && $row['coverage'] === ScanOutcome::COV_PARTIAL
        && (int) $row['clean'] === 0 && (int) $row['rule_problem_count'] === 1
        && (int) $row['not_examined'] === 1);
    $st = $p['status'];
    check('E2E partial: and status() reports the finding, the rule problem and the record not examined',
        !empty($st['ok']) && $st['findings'] === 1 && $st['ruleProblems'] === 1 && $st['notExamined'] === 1
        && $st['done'] === 2 && $st['excluded'] === 1);

    // -- Stop, with nobody driving the run -------------------------------------
    $xs = new ArrayScanStore();
    $xm = new \E2eModule($xs);
    $xm->problems = [['rule' => 2, 'fields' => ['b_val'], 'why' => 'rule 2 cannot be evaluated', 'code' => 'c2']];
    $stop = null;
    $x = $drive($xm, $xs, 152, function (ScanService $svc, $runId) use (&$stop) {
        $stop = $svc->cancel(152, $runId);
    });
    check('E2E stop: no verb threw' . ($x['threw'] ? ' (' . $x['threw'] . ')' : ''), $x['threw'] === null);
    check('E2E stop: Stop on a run nobody drives ends it at once',
        !empty($stop['ok']) && $stop['stopped'] === true);
    $row = $x['row'];
    check('E2E stop: the run is stored cancelled, partial, with no verdict, and with the rule problem its plan noted',
        is_array($row) && $row['terminal'] === ScanOutcome::CANCELLED && $row['coverage'] === ScanOutcome::COV_PARTIAL
        && $row['clean'] === null && (int) $row['rule_problem_count'] === 1);
    check('E2E stop: and status() says it was cancelled, and that it is over',
        !empty($x['status']['ok']) && $x['status']['terminal'] === ScanOutcome::CANCELLED
        && $x['status']['active'] === false);

    // -- Stop while a worker holds the run --------------------------------------
    $hs = new ArrayScanStore();
    $hm = new \E2eModule($hs);
    $hm->lease = gmdate('Y-m-d H:i:s', time() + 300);
    $hm->problems = [['rule' => 2, 'fields' => ['b_val'], 'why' => 'rule 2 cannot be evaluated', 'code' => 'c2']];
    $held = null;
    $h = $drive($hm, $hs, 153, function (ScanService $svc, $runId) use (&$held, $hs) {
        $held = $svc->cancel(153, $runId);
        $held['phase'] = $hs->run(153, $runId)['phase'];
    });
    check('E2E held stop: no verb threw' . ($h['threw'] ? ' (' . $h['threw'] . ')' : ''), $h['threw'] === null);
    check('E2E held stop: Stop on a run a worker holds leaves it stopping for that worker',
        !empty($held['ok']) && $held['stopped'] === false && $held['phase'] === ScanPhase::CANCELLING);
    check('E2E held stop: and the next work request ends it, cancelled',
        is_array($h['row']) && $h['row']['terminal'] === ScanOutcome::CANCELLED && $h['passes'] === 1
        && !empty($h['status']['ok']) && $h['status']['terminal'] === ScanOutcome::CANCELLED);
    check('E2E held stop: with the rule problem its plan noted (R3U-3)',
        (int) $h['row']['rule_problem_count'] === 1 && $h['status']['ruleProblems'] === 1);
}

/* =====================================================================
 * R3U-3  ONE STOPPED RUN, ONE ROW, WHICHEVER REQUEST ENDS IT
 *
 * Stop on a run nobody drives, the reap a Start does first, and the work
 * request that finds a run `cancelling` each wrote derive(['cancelled' =>
 * true]) - no counts - while promote() wrote the same stopped run with its
 * tally. So the row said detail `complete` and 0 rule problems, 0 records not
 * examined, 0 gaps, the panel lost "1 rule could not be evaluated" and the
 * export was named _CANCELLED instead of _CANCELLED_TRUNCATED.
 *
 * One run, built four times: four records, two examined with a finding each,
 * one that could not be read, one never reached; a rule problem the plan
 * noted; two collection gaps; a detail budget of two findings. Each copy is
 * ended by a different writer, and every row must read the same.
 * ===================================================================== */
{
    $stopped = function ($pid) {
        $store = new ArrayScanStore();
        $m = new \E2eModule($store);
        $m->proj['scan-max-detail-findings'] = '2';
        $key = $m->sys['log-hmac-key'];
        $hash = function ($id) use ($pid, $key) { return Hmac::raw(Hmac::P_RECORD, $pid, $id, $key); };
        $runId = (int) $store->startRun($pid, ['created_by' => 'alice', 'policy_json' => '{"crossLookups":[]}'])['run']['run_id'];
        $store->writeManifest($runId, array_map(function ($id) use ($hash) {
            return ['id_bin' => $id, 'hash' => $hash($id), 'dag' => null];
        }, ['R001', 'R002', 'R003', 'R004']));
        ScanPromotion::noteRuleProblems($store, $runId, [['rule' => 3, 'why' => 'rule 3 is broken', 'code' => 'c3']]);
        $store->addAggregate($runId, ScanPromotion::GAP_KINDS, 'fa', 'x', 2, 0);
        $run = $store->run($pid, $runId);
        $epoch = (int) $run['lease_epoch'];
        $b = ['bytes' => 0, 'records' => [], 'findings' => []];
        foreach ($store->claim($runId, 'w1', $epoch, 3) as $i => $c) {
            $b['records'][] = ['ordinal' => $c['ordinal'], 'claim' => $c['claim'], 'record_hash' => $c['hash'],
                'state' => $i < 2 ? ScanStore::REC_DONE : ScanStore::REC_UNREADABLE,
                'version' => $i < 2 ? '50' : null];
            if ($i < 2) {
                $b['findings'][] = ['ordinal' => $c['ordinal'], 'project_id' => $pid,
                    'generation_id' => (int) $run['generation_id'], 'identity' => hash('sha256', 'f' . $c['id_bin'], true),
                    'valid_from_seq' => (int) $run['run_seq'], 'record_hash' => $c['hash'],
                    'record_id_bin' => $c['id_bin'], 'event_id' => 1, 'instance' => 1, 'dag_key' => null,
                    'host_form' => 'fa', 'field' => 'a_val', 'rule_source_id' => 'r1',
                    'rule_revision' => str_repeat('c', 64), 'rule_ord' => 1, 'check_type' => 'required',
                    'reason_code' => 'required-blank'];
                // THE BUDGET WAS MET (H9): the second examined record found one
                // more finding than the two the budget stores, and the gate left
                // it out. A count of one left out is what makes the run truncated.
                if ($i === 1) $b['unstored'] = [(int) $c['ordinal'] => 1];
            }
        }
        $ok = $store->commitBatch($runId, 'w1', $epoch, $b);
        return [$store, $m, $runId, $ok === true];
    };
    $cols = function (ScanStore $store, $pid, $runId) {
        $r = $store->run($pid, $runId);
        $out = [];
        foreach (['phase', 'terminal', 'coverage', 'detail', 'clean', 'rule_problem_count', 'not_examined',
                  'gap_count'] as $k) {
            $out[$k] = $r[$k] === null ? null : (string) $r[$k];
        }
        $out['suffix'] = ScanOutcome::suffix($r);
        return $out;
    };
    $want = ['phase' => ScanPhase::TERMINAL, 'terminal' => ScanOutcome::CANCELLED,
             'coverage' => ScanOutcome::COV_PARTIAL, 'detail' => ScanOutcome::DETAIL_TRUNCATED, 'clean' => null,
             'rule_problem_count' => '1', 'not_examined' => '1', 'gap_count' => '2',
             'suffix' => '_CANCELLED_TRUNCATED'];
    $policy = ScanPolicy::resolve([], ['scan-max-detail-findings' => '2']);

    // promote(): the request a worker held, ending the run it was told to stop.
    list($ps, $pm, $pr, $pok) = $stopped(870);
    $ps->cancel(870, $pr, 'alice');
    ScanPromotion::promote($ps, 870, $pr, ['uniqueDone' => false, 'uniqueBlocking' => 0, 'rollupDone' => false,
        'maxFindings' => $policy['maxFindings'], 'maxBytes' => $policy['maxBytes'], 'crossRecordLookups' => false]);
    check('R3U-3: the fixture committed its batch', $pok);
    check('R3U-3: promote() writes the stopped run with its counts and its truncation',
        $cols($ps, 870, $pr) === $want);

    // Stop on a run nobody drives: cancel() -> stopRun().
    list($ss, $sm, $sr) = $stopped(871);
    $sres = (new ScanService($sm, $ss))->cancel(871, $sr);
    check('R3U-3: Stop on a run nobody drives ends it ...', !empty($sres['ok']) && $sres['stopped'] === true);
    check('R3U-3: ... with the same row promote() writes', $cols($ss, 871, $sr) === $want);

    // The reap a Start does first: start() on the project.
    list($rs, $rm, $rr) = $stopped(872);
    $rs->cancel(872, $rr, 'alice');
    $rstart = (new ScanService($rm, $rs))->start(872);
    check('R3U-3: Start ends the run left stopping, and takes the slot ...',
        !empty($rstart['ok']) && (int) $rstart['run_id'] !== $rr);
    check('R3U-3: ... and the stopped run\'s row is the same row', $cols($rs, 872, $rr) === $want);

    // A work request that finds the run stopping.
    list($ws, $wm, $wr) = $stopped(873);
    $ws->cancel(873, $wr, 'alice');
    $wres = (new ScanService($wm, $ws))->work(873, $wr);
    check('R3U-3: a work request that finds the run stopping ends it, and says so ...',
        !empty($wres['ok']) && $wres['stop'] === 'cancelled' && $wres['worked'] === 0
        && isset($wres['status']['terminal']) && $wres['status']['terminal'] === ScanOutcome::CANCELLED);
    check('R3U-3: ... with the same row', $cols($ws, 873, $wr) === $want);
    check('R3U-3: and status() of a stopped run reports what promotion counted',
        $wres['status']['ruleProblems'] === 1 && $wres['status']['notExamined'] === 1
        && $wres['status']['detail'] === ScanOutcome::DETAIL_TRUNCATED);

    // The worker's own two endings. advanceRun() ends a stopping run before
    // it builds the worker, so the worker meets one only when the run turns
    // `cancelling` between the two reads; it must still write the same row,
    // and so must its stop when the privacy settings tightened.
    $limits = ['maxFindings' => $policy['maxFindings'], 'maxBytes' => $policy['maxBytes']];
    list($ks, $km, $kr) = $stopped(874);
    $ks->cancel(874, $kr, 'alice');
    $kres = (new ScanWorker($ks, ['owner' => 'w2', 'attempts' => 3, 'limits' => $limits]))->work(874, $kr);
    check('R3U-3: the worker that finds the run stopping ends it ...',
        !empty($kres['ok']) && $kres['stop'] === 'cancelled');
    check('R3U-3: ... with the same row promote() writes', $cols($ks, 874, $kr) === $want);
    list($vs, $vm, $vr) = $stopped(875);
    $vres = (new ScanWorker($vs, ['owner' => 'w2', 'attempts' => 3, 'limits' => $limits,
                                  'policyRevision' => 99]))->work(875, $vr);
    $vrow = $vs->run(875, $vr);
    check('R3U-3: a stop because the privacy settings tightened keeps the counts and the truncation ...',
        $vres['ok'] === false && $cols($vs, 875, $vr) === $want);
    check('R3U-3: ... and says why', strpos((string) $vrow['terminal_reason'], 'privacy settings were tightened') !== false);

    // The finisher never reopens a run, and ends one only once.
    check('R3U-3: the finisher does not touch a run that has ended',
        ScanPromotion::finishCancelled($ws, 873, $wr, []) === false && $cols($ws, 873, $wr) === $want);
    check('R3U-3: nor a run that is not there', ScanPromotion::finishCancelled($ws, 873, 999, []) === false);

    // The caller decides the run ends, as derive(['cancelled' => true]) let
    // it: the worker stopping a run whose privacy settings tightened has
    // pressed no Stop, and its sentence replaces the outcome's own.
    list($ts, $tm, $tr) = $stopped(874);
    $tWhy = 'the project\'s privacy settings were tightened while this scan was running';
    check('R3U-3: a run nobody pressed Stop on is ended as cancelled when the caller says so, with its counts',
        ScanPromotion::finishCancelled($ts, 874, $tr, ['maxFindings' => $policy['maxFindings'],
            'maxBytes' => $policy['maxBytes'], 'why' => $tWhy]) === true
        && $cols($ts, 874, $tr) === $want && $ts->run(874, $tr)['terminal_reason'] === $tWhy);
}

/* =====================================================================
 * R3U-5  A STOP SOMEBODY ELSE FINISHED IS STILL A STOP
 * R3U-6  A FINISH THAT THROWS LEAVES THE STOP STANDING
 *
 * finish() changes only a run that still holds its slot. Two tabs pressing
 * Stop together - A bumps, B bumps, B finishes, A finishes - left A told
 * ok:true stopped:false over a run that had ended, and its page said
 * "Stopping the scan." beside the finished panel. A asks again now, and a run
 * that is cancelled has stopped; one that ended some other way is for the
 * status to describe.
 *
 * And the catch around that finish was unpinned: narrowing it kept the suite
 * green. A finish that throws must leave the stop as it stands - the run
 * `cancelling`, its slot held for the next Continue or Start to finish - and
 * answer ok:true, stopped:false, never an exception.
 * ===================================================================== */
{
    $stopRun = new \ReflectionMethod(ScanService::class, 'stopRun');
    $stopRun->setAccessible(true);
    $svc = new ScanService(new \NoDbModule());
    $NOW = time();
    $open = function (ArrayScanStore $inner, $pid) {
        $runId = (int) $inner->startRun($pid, ['created_by' => 'alice', 'policy_json' => '{"crossLookups":[]}'])['run']['run_id'];
        $inner->writeManifest($runId, [['id_bin' => 'A', 'hash' => hash('sha256', 'A', true), 'dag' => null]]);
        return $runId;
    };
    // Nobody drives it: no lease.
    $undriven = function (ScanStore $store, $pid, $runId) {
        $r = $store->run($pid, $runId);
        $r['lease_expires_at'] = null;
        return $r;
    };

    // Two tabs, one run.
    $inner = new ArrayScanStore();
    $rid = $open($inner, 880);
    $tabA = new \RacingStore($inner);
    $resB = null;
    $tabA->beforeFinish = function () use (&$resB, $stopRun, $svc, $inner, $rid, $undriven, $NOW) {
        $resB = $stopRun->invoke($svc, $inner, 880, $rid, 'tab-B', $undriven($inner, 880, $rid), $NOW);
    };
    $resA = $stopRun->invoke($svc, $tabA, 880, $rid, 'tab-A', $undriven($inner, 880, $rid), $NOW);
    check('R3U-5: the tab whose finish landed first is told the run stopped',
        is_array($resB) && $resB['ok'] === true && $resB['stopped'] === true);
    check('R3U-5: and so is the tab whose finish then found it ended',
        $resA['ok'] === true && $resA['stopped'] === true && $resA['why'] === null);
    check('R3U-5: the run ended once, cancelled',
        $inner->run(880, $rid)['terminal'] === ScanOutcome::CANCELLED);

    // The run ended some other way between the bump and the finish: not a stop.
    $inner = new ArrayScanStore();
    $rid = $open($inner, 881);
    $tabA = new \RacingStore($inner);
    $tabA->beforeFinish = function () use ($inner, $rid) {
        $inner->finish($rid, ScanOutcome::derive(['failed' => true]));
    };
    $resA = $stopRun->invoke($svc, $tabA, 881, $rid, 'tab-A', $undriven($inner, 881, $rid), $NOW);
    check('R3U-5: a run another request ended some other way is not reported as stopped',
        $resA['ok'] === true && $resA['stopped'] === false
        && $inner->run(881, $rid)['terminal'] === ScanOutcome::FAILED);

    // R3U-6: finish() throws.
    $inner = new ArrayScanStore();
    $rid = $open($inner, 882);
    $broken = (new \INSPIRE\UniversalValidator\Scan\FaultyStore($inner))->failFrom('finish');
    $threw = null;
    try {
        $res6 = $stopRun->invoke($svc, $broken, 882, $rid, 'alice', $undriven($inner, 882, $rid), $NOW);
    } catch (\Throwable $e) {
        $threw = get_class($e);
        $res6 = null;
    }
    check('R3U-6: a finish that throws is not an exception out of Stop' . ($threw ? ' (' . $threw . ')' : ''),
        $threw === null);
    check('R3U-6: the stop stands - ok, not stopped',
        is_array($res6) && $res6['ok'] === true && $res6['stopped'] === false && $res6['why'] === null);
    $left = $inner->run(882, $rid);
    check('R3U-6: the run is left cancelling, holding its slot for the next Continue or Start',
        $left['phase'] === ScanPhase::CANCELLING && $left['terminal'] === null
        && !empty($left['cancel_requested_at']) && $inner->startRun(882, [])['ok'] === false);
    check('R3U-6: and the next stop, with a finish that works, ends it',
        $stopRun->invoke($svc, $inner, 882, $rid, 'alice', $undriven($inner, 882, $rid), $NOW)['stopped'] === true
        && $inner->run(882, $rid)['terminal'] === ScanOutcome::CANCELLED);
}

/* =====================================================================
 * R3G-4  THE EVALUATOR'S REVISION IS IN EVERY RUN'S FINGERPRINT
 *
 * The fingerprint's `engine` input read a UV_ENGINE_VERSION constant that
 * nothing defined, so it was 'engine-1' on every release, and a durable run
 * that spanned a verdict-changing upgrade finished as a mix of two
 * evaluators. ScanService::ENGINE_REVISION is that input now.
 * ===================================================================== */
{
    $gs = new ArrayScanStore();
    $gm = new \E2eModule($gs);
    $gst = (new ScanService($gm, $gs))->start(160);
    $gid = (int) $gst['run_id'];
    $gctx = $gm->durableScanContext(160, []);
    $gpol = ScanPolicy::resolve([], []);
    $gfp = function ($engine) use ($gctx, $gpol) {
        return ScanPlanner::requestFingerprint(['rules' => $gctx['rules'], 'ownership' => $gctx['ownership'],
            'structure' => $gctx['structure'], 'policy' => $gpol, 'engine' => $engine]);
    };
    check('R3G-4: the evaluator has a revision, and it is not the value every earlier release used',
        is_string(ScanService::ENGINE_REVISION) && ScanService::ENGINE_REVISION !== ''
        && ScanService::ENGINE_REVISION !== 'engine-1');
    check('R3G-4: a different revision is a different fingerprint',
        !ScanPlanner::fingerprintMatches($gfp('engine-1'), $gfp(ScanService::ENGINE_REVISION)));
    // W7-R4 changed what a duplicate finding carries, its identity included,
    // so a run the engine-4 evaluator began is not finished by this one.
    check('R3G-4: the duplicates\' new ordinal, group and reason moved the revision past engine-4',
        ScanService::ENGINE_REVISION !== 'engine-4'
        && !ScanPlanner::fingerprintMatches($gfp('engine-4'), $gfp(ScanService::ENGINE_REVISION)));
    check('R3G-4: start() fingerprints the run with this revision',
        !empty($gst['ok']) && ScanPlanner::fingerprintMatches($gs->run(160, $gid)['fingerprint'],
                                                             $gfp(ScanService::ENGINE_REVISION)));
    // The same run as if the previous release had started it.
    $runsProp = new \ReflectionProperty(ArrayScanStore::class, 'runs');
    $runsProp->setAccessible(true);
    $rows = $runsProp->getValue($gs);
    $rows[$gid]['fingerprint'] = $gfp('engine-1');
    $runsProp->setValue($gs, $rows);
    $gw = (new ScanService($gm, $gs))->work(160, $gid);
    // ENDED, NOT MERELY REFUSED (W7-N11). The run used to stay non-terminal,
    // refused on every pass until the reaper expired it; the configuration
    // as it is now goes to promotion, which ends it `failed`.
    check('R3G-4: a run started under the previous evaluator is ended by this one, not continued',
        empty($gw['ok']) && strpos((string) $gw['why'], 'changed') !== false
        && (int) $gs->run(160, $gid)['manifest_done'] === 0
        && $gs->run(160, $gid)['terminal'] === ScanOutcome::FAILED);
}

/* =====================================================================
 * B10  PLANNING IS A SLICE PER REQUEST, DRIVEN BY THE WORK PUMP
 *
 * The whole record list used to be walked inside the start request, with no
 * deadline (review R3U-2): a project too big for one request died
 * uncatchably and left a run in `planning` that no work request would touch.
 * Now the start lists what its budget allows, and every work request lists
 * the next slice through ScanWorker, under the worker's slot and its attempt
 * cap.
 * ===================================================================== */
{
    $key = str_repeat('k', 32);
    $spent = function () { return new WorkBudget(['startedAt' => microtime(true) - 3600]); };
    $wide = function () { return new WorkBudget(['startedAt' => microtime(true) + 3600]); };
    $rules = ['source' => null, 'rules' => [['type' => 'required', 'fields' => ['a']]],
              'ownership' => ['a' => 'fa'], 'policy' => [], 'createdBy' => 'u', 'engine' => '1',
              'pageSize' => 1];
    $fresh = function ($n, $db = null) use ($key, $spent, $rules) {
        $db = $db === null ? new \MoodySourceDb() : $db;
        $db->rows = [];
        for ($i = 1; $i <= $n; $i++) $db->rows[] = [sprintf('W%03d', $i), null];
        $open = RecordManifestSource::open($db, 300, ['pk' => null]);
        $store = new ArrayScanStore();
        $first = (new ScanPlanner($store, $key))->plan(300, array_merge($rules,
            ['source' => $open['source'], 'budget' => $spent()]));
        return [$store, (int) $first['run']['run_id'], $open['source'], $first, $db];
    };
    $worker = function (ArrayScanStore $store, $src, $budget, array $more = []) use ($key) {
        $planner = function ($runId, array $slice) use ($store, $src, $key) {
            return (new ScanPlanner($store, $key))->step(300, $runId,
                array_merge($slice, ['source' => $src, 'pageSize' => 1]));
        };
        return new ScanWorker($store, array_merge(['planner' => $planner, 'budget' => $budget,
            'owner' => 'w' . mt_rand(), 'attempts' => 3,
            'read' => function ($ids) { return ['ok' => true, 'data' => [], 'why' => null]; },
            'evaluate' => function ($id, $node) {
                return ['findings' => [], 'bytes' => 0, 'contexts' => 1, 'why' => null];
            }], $more));
    };

    list($ps, $pid1, $src1, $first) = $fresh(5);
    check('B10: a start whose budget is spent lists its one guaranteed page and leaves the run planning',
        $first['ok'] === true && $first['run']['phase'] === ScanPhase::PLANNING
        && $first['stats']['listed'] === 1 && $ps->recordStates($pid1) === [ScanStore::REC_PENDING => 1]);
    $none = (new ScanWorker($ps, ['budget' => $spent(), 'owner' => 'x']))->work(300, $pid1);
    check('B10: a worker built without a planner still says planning takes no work from it',
        $none['why'] === 'this scan is not in a phase that takes work' && $none['phase'] === ScanPhase::PLANNING);
    $w1 = $worker($ps, $src1, $spent())->work(300, $pid1);
    check('B10: a work request lists the next slice, and reports it as movement the page counts',
        $w1['ok'] === true && $w1['phase'] === ScanPhase::PLANNING && $w1['listed'] === 1
        && $w1['stop'] === 'time' && $ps->recordStates($pid1) === [ScanStore::REC_PENDING => 2]);
    $passes = 1;
    do {
        $wn = $worker($ps, $src1, $spent())->work(300, $pid1);
        $passes++;
    } while ($wn['phase'] === ScanPhase::PLANNING && $passes < 20);
    check('B10: the request that reaches the end of the list freezes it and goes on to scanning',
        $passes === 4 && $wn['ok'] === true && $wn['phase'] === ScanPhase::SCANNING
        && (int) $ps->run(300, $pid1)['manifest_total'] === 5);

    // THE PAGE THAT ENDS THE LIST HANDS THE LEASE BACK. A start that lists a
    // small project whole leaves no planner holding it, so a Stop pressed a
    // second later ends the run then and there (R2O-5), as it did before
    // planning had a lease at all.
    $ls = new ArrayScanStore();
    $ldb = new \MoodySourceDb();
    $ldb->rows = [['L001', null], ['L002', null]];
    $lopen = RecordManifestSource::open($ldb, 301, ['pk' => null]);
    $lfirst = (new ScanPlanner($ls, $key))->plan(301, array_merge($rules,
        ['source' => $lopen['source'], 'budget' => $wide(), 'pageSize' => 1000]));
    $lid = (int) $lfirst['run']['run_id'];
    $lrows = new \ReflectionProperty(ArrayScanStore::class, 'runs');
    $lrows->setAccessible(true);
    $lrow = $lrows->getValue($ls)[$lid];
    check('B10: a start that lists the whole project leaves no planning lease on the frozen run',
        $lfirst['ok'] === true && $lfirst['run']['phase'] === ScanPhase::SCANNING
        && $lrow['lease_owner'] === null && $lrow['lease_expires_at'] === null);
    $lstop = new \ReflectionMethod(ScanService::class, 'stopRun');
    $lstop->setAccessible(true);
    $lbefore = $ls->run(301, $lid);
    $lbefore['lease_expires_at'] = $lrow['lease_expires_at'];
    $lr = $lstop->invoke(new ScanService(new \NoDbModule()), $ls, 301, $lid, 'bob', $lbefore, time());
    check('B10: so a Stop pressed straight after that Start ends the run at once',
        $lr['ok'] === true && $lr['stopped'] === true
        && $ls->run(301, $lid)['terminal'] === ScanOutcome::CANCELLED);

    // W1-N2: THE SLOT IS RENEWED BETWEEN PAGES, AND A LOST SLOT STOPS THE SLICE.
    list($rs, $rid2, $src2) = $fresh(6);
    $slotDb = new \SlotCounterDb();
    $slotDb->loseAt = 2;
    $r2 = $worker($rs, $src2, $wide(), ['slots' => new WorkerSlots($slotDb), 'slotTtl' => 300])
        ->work(300, $rid2);
    check('W1-N2: a planning slice renews the worker slot before each further page',
        $slotDb->renewals === 2);
    check('W1-N2: and stops after the page in hand when the slot was taken',
        $r2['ok'] === true && $r2['stop'] === 'slot' && $r2['listed'] === 2
        && $r2['phase'] === ScanPhase::PLANNING && $rs->recordStates($rid2) === [ScanStore::REC_PENDING => 3]);
    check('W1-N2: handing the planning lease back with that page, so the next request is not held',
        $rs->claimPlan($rid2, 'next', (int) $rs->run(300, $rid2)['lease_epoch'], 300, 3)['state'] === 'ok');

    // ATTEMPTS: A PAGE THAT CANNOT BE LISTED IS RETIRED AS A RECORD IS, AND
    // THE RUN ENDS SAYING SO, instead of sitting in `planning` for ever.
    list($bs, $rid3, $src3, , $db3) = $fresh(4);
    $db3->broken = true;
    $answers = [];
    for ($i = 0; $i < 4; $i++) $answers[] = $worker($bs, $src3, $wide())->work(300, $rid3);
    check('attempts: each failed slice says the list could not be read, and the run stays planning',
        $answers[0]['ok'] === false && $answers[0]['stop'] === 'read'
        && $answers[2]['stop'] === 'read' && $answers[2]['phase'] === ScanPhase::PLANNING);
    $row3 = $bs->run(300, $rid3);
    check('attempts: at the cap the next request retires the walk, and the run ends failed with why',
        $answers[3]['stop'] === 'failed' && $answers[3]['phase'] === ScanPhase::TERMINAL
        && $answers[3]['why'] === ScanPlanner::PLAN_GAVE_UP
        && $row3['terminal'] === ScanOutcome::FAILED && $row3['terminal_reason'] === ScanPlanner::PLAN_GAVE_UP);
    check('attempts: the cap is the worker\'s record cap, one mechanism',
        ScanPolicy::D_RECORD_ATTEMPTS === 3);

    // R3U-2: A STOP ENDS THE WALK, AND THE WALK SAYS SO.
    list($ss, $rid4, $src4) = $fresh(5);
    $ss->cancel(300, $rid4, 'bob');
    $r4 = $worker($ss, $src4, $wide())->work(300, $rid4);
    check('R3U-2: a stopped planning run is finished as stopped, with nothing more listed',
        $r4['ok'] === true && $r4['phase'] === ScanPhase::TERMINAL && $r4['stop'] === 'cancelled'
        && $ss->run(300, $rid4)['terminal'] === ScanOutcome::CANCELLED
        && $ss->recordStates($rid4) === [ScanStore::REC_PENDING => 1]);
    // ... including when the stop lands while the slice is walking: the next
    // append is refused, the slice ends there, and its answer is the truth.
    list($ms, $rid5, $src5, , $db5) = $fresh(6);
    $db5->onPage = function ($n) use ($ms, $rid5) {
        if ($n === 3) $ms->cancel(300, $rid5, 'bob');      // the start read page 1
    };
    $r5 = $worker($ms, $src5, $wide())->work(300, $rid5);
    check('R3U-2: a Stop that lands mid-slice ends the walk at its next append, said as a stop',
        $r5['ok'] === true && $r5['phase'] === ScanPhase::TERMINAL && $r5['stop'] === 'cancelled'
        && $r5['why'] === ScanPlanner::PLAN_STOPPED && $db5->pages === 3
        && $ms->run(300, $rid5)['terminal'] === ScanOutcome::CANCELLED
        && $ms->recordStates($rid5) === [ScanStore::REC_PENDING => 2]);

    // R5-6: A SLICE WHOSE APPEND FAILS AT THE STORE HANDS ITS LEASE BACK. It
    // kept it, and every request for the next five minutes was told another
    // request was listing the project while none was.
    list($fs, $rid6, $src6) = $fresh(6);
    $faulty = (new \INSPIRE\UniversalValidator\Scan\FaultyStore($fs))->failFrom('appendManifest');
    $fplanner = function ($runId, array $slice) use ($faulty, $src6, $key) {
        return (new ScanPlanner($faulty, $key))->step(300, $runId,
            array_merge($slice, ['source' => $src6, 'pageSize' => 1]));
    };
    $f1 = (new ScanWorker($faulty, ['planner' => $fplanner, 'budget' => $wide(), 'owner' => 'tabA',
        'attempts' => 3]))->work(300, $rid6);
    $leaseOf = new \ReflectionProperty(ArrayScanStore::class, 'runs');
    $leaseOf->setAccessible(true);
    check('R5-6: a slice whose page could not be stored says so, and leaves no planning lease behind',
        $f1['ok'] === false && $f1['stop'] === 'storage'
        && $leaseOf->getValue($fs)[$rid6]['lease_owner'] === null
        && $fs->recordStates($rid6) === [ScanStore::REC_PENDING => 1]);
    $f2 = $worker($fs, $src6, $spent())->work(300, $rid6);
    check('R5-6: so the next request lists the next page rather than being told the walk is held',
        $f2['ok'] === true && $f2['stop'] === 'time' && $f2['listed'] === 1
        && $fs->recordStates($rid6) === [ScanStore::REC_PENDING => 2]);

    // review rv5b-7 (b): A SLICE WHOSE LEASE ANOTHER REQUEST TOOK reports the
    // pages it appended and not the page it was refused. The start listed
    // page 1; this slice appends page 2, and before page 3 is appended
    // another request holds the lease, so that append is refused and the
    // slice is told the walk is held. It listed one record. Counted with the
    // refused page it says two, and a held slice that says it listed is one
    // the page asks again straight away.
    list($hs, $rid7, $src7, , $db7) = $fresh(6);
    $db7->onPage = function ($n) use ($hs, $rid7, $leaseOf) {
        if ($n !== 3) return;
        $runs = $leaseOf->getValue($hs);
        $runs[$rid7]['lease_owner'] = 'tabB';
        $leaseOf->setValue($hs, $runs);
    };
    $h7 = $worker($hs, $src7, $wide())->work(300, $rid7);
    check('rv5b-7: a slice refused its page because another request took the walk reports only what it appended',
        $h7['ok'] === true && $h7['stop'] === 'held' && $h7['why'] === ScanPlanner::PLAN_HELD
        && $h7['listed'] === 1 && $hs->recordStates($rid7) === [ScanStore::REC_PENDING => 2]);
}

/* =====================================================================
 * B10 / R3U-2  THE START IS BUDGETED, AND THE WORK PUMP LISTS THE REST
 *
 * Through ScanService itself: start() hands the planner a browser budget,
 * so a first page that takes longer than the budget is the only page the
 * start lists; work() then lists the rest through the planner workerDeps()
 * binds, and the run freezes the whole project.
 * ===================================================================== */
{
    $bs = new ArrayScanStore();
    $bm = new \E2eModule($bs);
    $bm->records = [];
    for ($i = 1; $i <= 2500; $i++) $bm->records[] = sprintf('B%04d', $i);
    $slow = new \MoodySourceDb();
    $slow->slowFor = 3.2;                     // past the 3 s browser target on its own
    $bm->index = $slow;
    $bst = (new ScanService($bm, $bs))->start(161);
    $bid = (int) $bst['run_id'];
    check('R3U-2: the start lists the page its budget allows and leaves the rest to the pump',
        !empty($bst['ok']) && $bs->run(161, $bid)['phase'] === ScanPhase::PLANNING
        && $bs->recordStates($bid) === [ScanStore::REC_PENDING => 1000]);
    $listed = 0;
    for ($i = 0; $i < 10 && $bs->run(161, $bid)['phase'] === ScanPhase::PLANNING; $i++) {
        $bw = (new ScanService($bm, $bs))->work(161, $bid);
        $listed += isset($bw['listed']) ? (int) $bw['listed'] : 0;
    }
    check('B10: work requests list the rest through the planner the service binds, and freeze it',
        $listed === 1500 && (int) $bs->run(161, $bid)['manifest_total'] === 2500
        && $bs->run(161, $bid)['phase'] !== ScanPhase::PLANNING);
}

/* =====================================================================
 * H10 part 1 (with review R5-1)  A GROUP-SCOPED RUN SHOWS NO COUNT UNTIL
 * ITS SCOPE IS PROVED AT THE TARGET FENCE
 *
 * 3000 records, 200 of them in group 7, read by a user confined to group 7;
 * none of the group's records is on the walk's second page of 1000.
 * Until catch-up has walked the window to the target fence, the run's list
 * is the group as planning read it, so no count describes an established
 * scope: the status keeps its phase, its flags and Stop, and the work
 * answers keep `moved` (what the page paces itself by) and nothing else
 * that counts. The planner's own `listed` is pinned in
 * tests/scan_sqlstore_fault_php.php.
 * ===================================================================== */
{
    $gs = new ArrayScanStore();
    $gm = new class($gs) extends \E2eModule {
        public $dagOf = [];
        /** @var float one read of record data takes this long, then reads are quick again */
        public $readSleep = 0.0;
        public function query($sql, $params = [])
        {
            if (strpos($sql, 'log_event_table') === false
                    && (strpos($sql, 'information_schema.columns') !== false
                        || strpos($sql, 'redcap_record_list') !== false
                        || strpos($sql, 'redcap_projects') !== false
                        || strpos($sql, 'FROM redcap_data') !== false)) {
                $rows = [];
                foreach ($this->records as $id) $rows[] = [$id, isset($this->dagOf[$id]) ? $this->dagOf[$id] : null];
                $this->index->rows = $rows;
                return $this->index->select($sql, $params);
            }
            return parent::query($sql, $params);
        }
        public function durableScanContext($pid, array $opts = [], $dag = null)
        {
            $ctx = parent::durableScanContext($pid, $opts, $dag);
            $read = $ctx['read'];
            $self = $this;
            $ctx['read'] = function (array $ids) use ($read, $self) {
                if ($self->readSleep > 0) { usleep((int) ($self->readSleep * 1e6)); $self->readSleep = 0.0; }
                return $read($ids);
            };
            return $ctx;
        }
    };
    $gm->records = [];
    for ($i = 1; $i <= 3000; $i++) {
        $id = sprintf('G%04d', $i);
        $gm->records[] = $id;
        $gm->dagOf[$id] = ($i % 10 === 0 && ($i <= 1000 || $i > 2000)) ? '7' : '31';
    }
    $gm->index = new \MoodySourceDb();
    $gm->index->slowFor = 3.2;                // past the 3 s browser target: the start lists one page
    $gm->user->rights['group_id'] = 7;
    $keptNames = \REDCap::$groupNames;
    \REDCap::$groupNames = [7 => 'site_a', 31 => 'site_b'];
    $counts = ['total', 'done', 'settled', 'excluded', 'removed', 'findings',
               'detailRows', 'detailBytes', 'ruleProblems', 'notExamined'];
    $counters = ['worked', 'requeued', 'blocked', 'findings', 'listed'];

    $gst = (new ScanService($gm, $gs))->start(162);
    $gid = (int) $gst['run_id'];
    $g0 = (new ScanService($gm, $gs))->status(162, $gid);
    check('H10: a group-scoped run that is still listing shows its phase and Stop, and no count',
        !empty($gst['ok']) && $gs->run(162, $gid)['scope_dag'] === '7'
        && $g0['ok'] === true && $g0['phase'] === ScanPhase::PLANNING && $g0['mayCancel'] === true
        && $g0['withheld'] === true && $g0['withheldWhy'] === ScanAuthorization::WITHHELD
        && !array_intersect($counts, array_keys($g0)));

    // A planning slice that lists one more page and runs out of time. The
    // page holds none of group 7's records, so nothing the reader may count
    // moved: the planner's own word is what says the walk did.
    $gm->index->slowFor = 3.2;
    $gw1 = (new ScanService($gm, $gs))->work(162, $gid);
    check('H10: a work answer while the group is being listed carries no counter, and says the run moved',
        $gw1['ok'] === true && !array_intersect($counters, array_keys($gw1))
        && $gw1['moved'] === true && $gw1['withheld'] === true
        && $gs->run(162, $gid)['phase'] === ScanPhase::PLANNING
        && $gw1['status']['withheld'] === true && !array_intersect($counts, array_keys($gw1['status'])));

    // The last page, the freeze, and a scan whose first read runs out of time.
    $gm->readSleep = 3.2;
    $gw2 = (new ScanService($gm, $gs))->work(162, $gid);
    $gRow = $gs->run(162, $gid);
    check('H10: and so does one that froze the group\'s list and examined records, before the fence',
        $gw2['ok'] === true && !array_intersect($counters, array_keys($gw2))
        && $gw2['moved'] === true && $gw2['withheld'] === true
        && (int) $gRow['manifest_total'] === 200 && (int) $gRow['manifest_done'] > 0
        && empty($gRow['fence_target']));
    $g1 = (new ScanService($gm, $gs))->status(162, $gid);
    check('H10: the status of a frozen run that has not reached the fence gives no count either',
        $g1['ok'] === true && $g1['withheld'] === true && !array_intersect($counts, array_keys($g1))
        && $g1['phase'] === $gRow['phase']);

    // Driven to the end: every answer given before the fence withholds.
    $leaks = 0;
    for ($i = 0; $i < 10 && $gs->run(162, $gid)['terminal'] === null; $i++) {
        $a = (new ScanService($gm, $gs))->work(162, $gid);
        $proved = ScanAuthorization::scopeProved($gs->run(162, $gid));
        if (!$proved && (array_intersect($counters, array_keys($a)) || empty($a['withheld']))) $leaks++;
    }
    $gEnd = (new ScanService($gm, $gs))->status(162, $gid);
    check('H10: no answer given before the scope was proved carried a count', $leaks === 0);
    check('H10: once catch-up has proved the scope at the target fence, the counts are the group\'s own',
        $gEnd['ok'] === true && empty($gEnd['withheld']) && $gEnd['terminal'] !== null
        && $gEnd['total'] === 200 && !empty($gs->run(162, $gid)['fence_target']));

    // The same project, read by a user with no group: nothing is withheld.
    $us = new ArrayScanStore();
    $um = clone $gm;
    $um->store = $us;
    $um->user = clone $gm->user;
    $um->user->rights['group_id'] = null;
    $um->index = new \MoodySourceDb();
    $ust = (new ScanService($um, $us))->start(163);
    $uid = (int) $ust['run_id'];
    $u0 = (new ScanService($um, $us))->status(163, $uid);
    $uw = (new ScanService($um, $us))->work(163, $uid);
    check('H10: a run over the whole project is not withheld, and its answers keep their counts',
        !empty($ust['ok']) && empty($u0['withheld']) && $u0['total'] === 3000
        && empty($uw['withheld']) && $uw['moved'] === true && $uw['worked'] > 0
        && empty($uw['status']['withheld']) && isset($uw['status']['total']));

    // A status that could not be read at all is no proof of the scope: the
    // answer to a request whose storage failed, for a group-scoped run, keeps
    // no counter either. Since review rv5b-5 neither does the same answer for
    // a whole-project run: a status not given is not one the counters may
    // stand beside.
    $disc = new \ReflectionMethod(ScanService::class, 'disclosed');
    $disc->setAccessible(true);
    $failed = ['ok' => false, 'worked' => 0, 'requeued' => 0, 'blocked' => 0, 'findings' => 0,
               'phase' => ScanPhase::SCANNING, 'done' => false, 'stop' => 'storage',
               'why' => ScanStoreUnavailable::OPERATOR_TEXT];
    $unread = ['ok' => false, 'why' => ScanStoreUnavailable::OPERATOR_TEXT];
    $dScoped = $disc->invoke(new ScanService($gm, $gs),
        ['scope_dag' => '7', 'phase' => ScanPhase::SCANNING], $failed, $unread);
    $dWhole = $disc->invoke(new ScanService($um, $us),
        ['scope_dag' => null, 'phase' => ScanPhase::SCANNING], $failed, $unread);
    check('H10: a group-scoped answer whose status could not be read withholds its counters',
        $dScoped['withheld'] === true && $dScoped['moved'] === false
        && !array_intersect($counters, array_keys($dScoped)) && $dScoped['stop'] === 'storage');
    check('rv5b-5: so does a whole-project answer whose status could not be read',
        $dWhole['withheld'] === true && $dWhole['moved'] === false
        && !array_intersect($counters, array_keys($dWhole)) && $dWhole['stop'] === 'storage');
    // And one whose status was refused rather than unread, with counters that
    // are not zero: the refusal is the reader's, so the counts are not theirs.
    $busy = ['worked' => 10, 'findings' => 3] + $failed;
    $busy['ok'] = true;
    $busy['stop'] = null;
    $dRefused = $disc->invoke(new ScanService($um, $us),
        ['scope_dag' => null, 'phase' => ScanPhase::SCANNING], $busy,
        ['ok' => false, 'final' => true, 'why' => 'this scan looks values up in project 500, which does not '
            . 'answer your lookups, so its results are not shown to you']);
    check('rv5b-5: a whole-project answer whose status was refused keeps no counter, and says it moved',
        $dRefused['withheld'] === true && $dRefused['moved'] === true
        && !array_intersect($counters, array_keys($dRefused)));

    // review rv5b-7 (a): A REQUEST WHOSE ONLY MOVEMENT IS A NEW PHASE MOVED
    // THE RUN. Scanning ends with every counter at zero when the last batch
    // was examined by an earlier request; the phase it hands on is the only
    // sign, and a page told nothing moved waits out its idle ladder.
    $phaseOnly = ['ok' => true, 'worked' => 0, 'requeued' => 0, 'blocked' => 0, 'findings' => 0, 'listed' => 0,
                  'phase' => ScanPhase::CATCH_UP, 'done' => false, 'stop' => null, 'why' => null];
    $dPhase = $disc->invoke(new ScanService($um, $us),
        ['scope_dag' => null, 'phase' => ScanPhase::SCANNING], $phaseOnly, ['ok' => true]);
    $dSame = $disc->invoke(new ScanService($um, $us),
        ['scope_dag' => null, 'phase' => ScanPhase::CATCH_UP], $phaseOnly, ['ok' => true]);
    check('rv5b-7: a request whose only movement is a new phase answers that it moved, and one in the same phase does not',
        $dPhase['moved'] === true && $dSame['moved'] === false);

    // review rv5b-3. A catch-up pass over a page of other groups' changes
    // counts nothing and still moved the run; the worker says so, and the
    // answer keeps it, withheld or not.
    $walkedOnly = ['ok' => true, 'worked' => 0, 'requeued' => 0, 'blocked' => 0, 'findings' => 0,
                   'listed' => 0, 'moved' => true, 'phase' => ScanPhase::CATCH_UP, 'done' => false,
                   'stop' => 'time', 'why' => null];
    $heldStatus = ['ok' => true, 'withheld' => true, 'withheldWhy' => ScanAuthorization::WITHHELD];
    $dWalked = $disc->invoke(new ScanService($gm, $gs),
        ['scope_dag' => '7', 'phase' => ScanPhase::CATCH_UP], $walkedOnly, $heldStatus);
    check('rv5b-3: a catch-up pass that walked only other groups\' changes is an answer that moved',
        $dWalked['moved'] === true && $dWalked['withheld'] === true
        && !array_intersect($counters, array_keys($dWalked)));
    $dStill = $disc->invoke(new ScanService($gm, $gs),
        ['scope_dag' => '7', 'phase' => ScanPhase::CATCH_UP], array_merge($walkedOnly, ['moved' => false]),
        $heldStatus);
    check('rv5b-3 control: and one that wrote nothing did not',
        $dStill['moved'] === false);
    \REDCap::$groupNames = $keptNames;
}

/* =====================================================================
 * rv5b-1  A RECORD THAT LEAVES THE RUN'S GROUP LEAVES THE RUN
 *
 * The reviewer's repro, end to end. A group-7 user scans; R03 is one of the
 * group's five records. An administrator then moves R03 to group 31 and a
 * group-31 user blanks its a_val - two log entries for R03 inside the
 * window. Catch-up requeued it as an edit, the worker read it with group
 * 31's value, and the proved status showed the group-7 user total=5 and one
 * finding, on group 31's record. Twice: moved after the run read it (catch-up
 * must take it out) and moved before (the worker must not read it).
 * ===================================================================== */
{
    $keptNames = \REDCap::$groupNames;
    \REDCap::$groupNames = [7 => 'site_a', 31 => 'site_b'];
    // Ten records, five in group 7, read by a group-7 user, over a record
    // index that knows each record's group and a change log the case writes.
    $groupModule = function (ArrayScanStore $ms) {
        $mm = new class($ms) extends \E2eModule {
            public $dagOf = [];
            public $version = [];
            public $changes = [];
            public $top = '100';
            public $bottom = '1';
            public $reads = [];
            public $readSleep = 0.0;
            /** A record's first log position, for ScopeDrift; '1' when unset. */
            public $firstSeen = [];
            public function query($sql, $params = [])
            {
                if (strpos($sql, 'FROM redcap_log_event') !== false) {
                    $s = ltrim($sql);
                    if (strpos($sql, 'pk IN (') !== false) {
                        $min = strpos($sql, 'MIN(log_event_id)') !== false;
                        $out = [];
                        foreach (array_slice($params, 1) as $id) {
                            $id = (string) $id;
                            if (in_array($id, $this->records, true)) {
                                $out[] = [$id, $min ? (isset($this->firstSeen[$id]) ? $this->firstSeen[$id] : '1')
                                    : (isset($this->version[$id]) ? $this->version[$id] : '50')];
                            }
                        }
                        return $out;
                    }
                    if (strpos($sql, 'ORDER BY log_event_id') !== false) {
                        // SourceFence::entriesSince(): one entry per change, in log order.
                        preg_match('/LIMIT (\d+)/', $sql, $lim);
                        $rows = [];
                        foreach ($this->changes as $id => $v) {
                            if ((int) $v > (int) $params[1] && (int) $v <= (int) $params[2]) {
                                $rows[] = [(string) $v, (string) $id];
                            }
                        }
                        usort($rows, function ($a, $b) { return (int) $a[0] - (int) $b[0]; });
                        return array_slice($rows, 0, (int) $lim[1]);
                    }
                    if (strpos($sql, 'log_event_id > ?') !== false) {
                        $rows = [];
                        $cursor = isset($params[3]) ? (string) $params[3] : null;
                        foreach ($this->changes as $id => $v) {
                            if ((int) $v <= (int) $params[1] || (int) $v > (int) $params[2]) continue;
                            if ($cursor !== null && strcmp($id, $cursor) <= 0) continue;
                            $rows[$id] = [$id, (string) $v];
                        }
                        ksort($rows, SORT_STRING);
                        return array_values($rows);
                    }
                    if (strpos($s, 'SELECT MAX(log_event_id) FROM') === 0) return [[$this->top]];
                    if (strpos($s, 'SELECT MIN(log_event_id)') === 0) return [[$this->bottom]];
                }
                if (strpos($sql, 'log_event_table') === false
                        && (strpos($sql, 'information_schema.columns') !== false
                            || strpos($sql, 'redcap_record_list') !== false
                            || strpos($sql, 'redcap_projects') !== false
                            || strpos($sql, 'FROM redcap_data') !== false)) {
                    $rows = [];
                    foreach ($this->records as $id) $rows[] = [$id, $this->dagOf[$id]];
                    $this->index->rows = $rows;
                    return $this->index->select($sql, $params);
                }
                return parent::query($sql, $params);
            }
            public function durableScanContext($pid, array $opts = [], $dag = null)
            {
                $ctx = parent::durableScanContext($pid, $opts, $dag);
                $read = $ctx['read'];
                $self = $this;
                $ctx['read'] = function (array $ids) use ($read, $self) {
                    foreach ($ids as $id) $self->reads[] = (string) $id;
                    if ($self->readSleep > 0) { usleep((int) ($self->readSleep * 1e6)); $self->readSleep = 0.0; }
                    return $read($ids);
                };
                return $ctx;
            }
        };
        $mm->records = [];
        for ($i = 1; $i <= 10; $i++) {
            $id = sprintf('R%02d', $i);
            $mm->records[] = $id;
            $mm->dagOf[$id] = $i <= 5 ? '7' : '31';
        }
        $mm->user->rights['group_id'] = 7;
        return $mm;
    };
    $movedRun = function ($pid, $moveFirst) use ($groupModule) {
        $ms = new ArrayScanStore();
        $mm = $groupModule($ms);
        $move = function () use ($mm) {
            $mm->dagOf['R03'] = '31';
            $mm->flagged['R03'] = true;
            $mm->version['R03'] = '110';
            $mm->changes['R03'] = '110';
            $mm->top = '120';
            $mm->reads = [];
        };
        $rid = (int) (new ScanService($mm, $ms))->start($pid)['run_id'];
        if ($moveFirst) {
            $move();
        } else {
            // The first request examines the group's five records, then the
            // budget stops it: R03 is read while it is still group 7's.
            $mm->readSleep = 3.2;
            (new ScanService($mm, $ms))->work($pid, $rid);
            $move();
        }
        for ($i = 0; $i < 12 && $ms->run($pid, $rid)['terminal'] === null; $i++) {
            (new ScanService($mm, $ms))->work($pid, $rid);
        }
        $row = $ms->run($pid, $rid);
        $ids = [];
        foreach ($ms->findings($pid, (int) $row['generation_id'], ['dag' => null], [], 0, 100) as $f) {
            $ids[] = (string) $f['record_id_bin'];
        }
        return ['row' => $row, 'states' => $ms->recordStates($rid), 'reads' => $mm->reads,
                'status' => (new ScanService($mm, $ms))->status($pid, $rid), 'findings' => $ids];
    };
    foreach (['after the run read it' => [178, false], 'before the run read it' => [179, true]]
             as $when => $c) {
        $mo = $movedRun($c[0], $c[1]);
        check('rv5b-1: a record moved to another group ' . $when . ' is never read with that group\'s data',
            !in_array('R03', $mo['reads'], true));
        check('rv5b-1: it leaves the run (' . $when . '), and the run still finishes through its fence',
            isset($mo['states'][ScanStore::REC_DEPARTED]) && $mo['states'][ScanStore::REC_DEPARTED] === 1
            && $mo['row']['terminal'] === 'complete' && !empty($mo['row']['fence_target']));
        check('rv5b-1: the group\'s reader is shown the group\'s four records and no finding (' . $when . ')',
            $mo['status']['ok'] === true && empty($mo['status']['withheld'])
            && $mo['status']['total'] === 4 && $mo['status']['done'] === 4
            && $mo['status']['findings'] === 0 && $mo['findings'] === []);
    }

    // NO RECORD SOURCE, NO ANSWER. A scoped run whose source cannot be opened
    // can tell no record's group, and cannot tell takes nothing out of it; an
    // unscoped run admits everything, as it did.
    $ns = new ArrayScanStore();
    $nm = new class($ns) extends \E2eModule {
        public function query($sql, $params = [])
        {
            if (strpos($sql, 'log_event_table') === false
                    && (strpos($sql, 'information_schema.columns') !== false
                        || strpos($sql, 'redcap_record_list') !== false
                        || strpos($sql, 'redcap_projects') !== false
                        || strpos($sql, 'FROM redcap_data') !== false)) {
                throw new \RuntimeException('the record list could not be read');
            }
            return parent::query($sql, $params);
        }
    };
    $probe = new \ReflectionMethod(ScanService::class, 'scopeProbe');
    $probe->setAccessible(true);
    $nsvc = new ScanService($nm, $ns);
    check('rv5b-1: with no record source a scoped run can tell no record\'s group, so takes none out',
        call_user_func($probe->invoke($nsvc, 180, '7'), ['R1']) === ['R1' => null]);
    check('rv5b-1 control: and an unscoped run with no source still admits every record',
        call_user_func($probe->invoke($nsvc, 180, null), ['R1']) === ['R1' => true]);

    // THE SERVICE WIRES BOTH HALVES: a scoped run's worker is handed the
    // group question, and its duplicate check the run's departures. An
    // unscoped run's worker asks nothing.
    $wdOf = new \ReflectionMethod(ScanService::class, 'workerDeps');
    $wdOf->setAccessible(true);
    $fdOf = new \ReflectionProperty(UniqueFinalizer::class, 'deps');
    $fdOf->setAccessible(true);
    $wctx = ['read' => function (array $ids) { return ['ok' => true, 'data' => [], 'why' => null]; },
             'evaluate' => function ($id, $node) {
                 return ['findings' => [], 'bytes' => 0, 'contexts' => 1, 'why' => null];
             }];
    $wsS = new ArrayScanStore();
    $wsId = (int) $wsS->startRun(149, ['created_by' => 'alice', 'scope_dag' => '7'])['run']['run_id'];
    $wsS->writeManifest($wsId, [['id_bin' => 'W1', 'hash' => hash('sha256', 'W1', true), 'dag' => '7'],
                                ['id_bin' => 'W2', 'hash' => hash('sha256', 'W2', true), 'dag' => '7']]);
    $wsEp = (int) $wsS->run(149, $wsId)['lease_epoch'];
    $wsS->advancePhase($wsId, $wsEp, ScanPhase::CATCH_UP);
    $wsS->depart($wsId, $wsEp, [hash('sha256', 'W1', true)]);
    $wdS = $wdOf->invoke(new ScanService(new \SourceModule()), 149, $wsS, $wsS->run(149, $wsId),
                         ScanPolicy::resolve([], []), $wctx, 'browser');
    $fdS = $fdOf->getValue($wdS['finalizer']);
    check('rv5b-1: a scoped run\'s worker is handed the group question',
        isset($wdS['scope']) && is_callable($wdS['scope']));
    check('rv5b-1: and its duplicate check is handed the run\'s departures, and only them',
        isset($fdS['departed']) && is_callable($fdS['departed'])
        && call_user_func($fdS['departed'], [hash('sha256', 'W1', true), hash('sha256', 'W2', true)])
           === [hash('sha256', 'W1', true) => true]);
    $wsU = new ArrayScanStore();
    $wuId = (int) $wsU->startRun(149, ['created_by' => 'alice'])['run']['run_id'];
    $wdU = $wdOf->invoke(new ScanService(new \SourceModule()), 149, $wsU, $wsU->run(149, $wuId),
                         ScanPolicy::resolve([], []), $wctx, 'browser');
    check('rv5b-1 control: an unscoped run\'s worker is not handed the group question',
        !isset($wdU['scope']));

    /* -----------------------------------------------------------------
     * rv5b-2 / rv5b-6  A GROUP-SCOPED RUN THAT ENDS WITHOUT PROVING ITS
     * SCOPE IS NOT PROMISED ITS COUNTS, AND SAYS NOTHING THAT COUNTS
     *
     * The reviewer's repros: stopped by its user after checking all five
     * of its records; finished over a change log pruned below its opening
     * fence; stopped over a one-finding detail budget; and an empty group.
     * Each kept "its counts are shown once it has confirmed..." for good,
     * the stopped run's coverage line said "Some records could not be
     * checked" of a run that had checked every one, and the truncated one
     * still said findings had passed the budget.
     * ----------------------------------------------------------------- */
    $ended = function ($pid, $how) use ($groupModule) {
        $es = new ArrayScanStore();
        $em = $groupModule($es);
        $em->flagged['R02'] = true;
        if ($how === 'truncated') {
            $em->flagged['R04'] = true;
            $em->proj['scan-max-detail-findings'] = '1';
        }
        $rid = (int) (new ScanService($em, $es))->start($pid)['run_id'];
        $em->readSleep = 3.2;
        $running = (new ScanService($em, $es))->work($pid, $rid);
        if ($how === 'pruned') {
            $em->bottom = '200';
            for ($i = 0; $i < 10 && $es->run($pid, $rid)['terminal'] === null; $i++) {
                (new ScanService($em, $es))->work($pid, $rid);
            }
        } else {
            (new ScanService($em, $es))->cancel($pid, $rid);
        }
        return ['running' => $running['status'], 'row' => $es->run($pid, $rid),
                'status' => (new ScanService($em, $es))->status($pid, $rid)];
    };
    $stopped = $ended(181, 'stopped');
    $pfS = \INSPIRE\UniversalValidator\ScanPageView::panelPrefill($stopped['status']);
    check('rv5b-2: a running group-scoped run is told its counts come once its scope is confirmed',
        $stopped['running']['withheld'] === true
        && $stopped['running']['withheldWhy'] === ScanAuthorization::WITHHELD);
    check('rv5b-2: a stopped one is told they cannot be shown, and the page says so',
        $stopped['row']['terminal'] === ScanOutcome::CANCELLED && (int) $stopped['row']['manifest_done'] === 5
        && $stopped['status']['withheld'] === true
        && $stopped['status']['withheldWhy'] === ScanAuthorization::WITHHELD_ENDED
        && $pfS['counts'] === ScanAuthorization::WITHHELD_ENDED);
    check('rv5b-2: and its coverage line says it was stopped, not that records could not be checked',
        strpos($pfS['done'], \INSPIRE\UniversalValidator\ScanPageView::STOPPED_WITHHELD) === 0
        && strpos($pfS['done'], 'could not be checked') === false);
    $pruned = $ended(182, 'pruned');
    $pfP = \INSPIRE\UniversalValidator\ScanPageView::panelPrefill($pruned['status']);
    check('rv5b-2: a run that finished over a change log that could not prove its window is not promised its counts',
        $pruned['row']['terminal'] !== null && empty($pruned['row']['fence_target'])
        && $pruned['status']['withheldWhy'] === ScanAuthorization::WITHHELD_ENDED);
    check('rv5b-2 control: and its coverage line is the one its coverage earns',
        strpos($pfP['done'], \INSPIRE\UniversalValidator\ScanPageView::coverageSentence($pruned['row']['coverage'])) === 0);
    $trunc = $ended(183, 'truncated');
    $pfT = \INSPIRE\UniversalValidator\ScanPageView::panelPrefill($trunc['status']);
    check('rv5b-6: a withheld status does not say whether findings passed the detail budget',
        $trunc['row']['detail'] === ScanOutcome::DETAIL_TRUNCATED
        && !array_key_exists('detail', $trunc['status'])
        && strpos($pfT['done'], \INSPIRE\UniversalValidator\ScanPageView::DETAIL_TRUNCATED) === false);
    // The empty group: planning ends the run before any fence.
    $eg = new ArrayScanStore();
    $egm = $groupModule($eg);
    $egm->records = [];
    $egm->dagOf = [];
    for ($i = 1; $i <= 2000; $i++) {
        $egm->records[] = sprintf('E%04d', $i);
        $egm->dagOf[sprintf('E%04d', $i)] = '31';
    }
    $egm->index = new \MoodySourceDb();
    $egm->index->slowFor = 3.2;
    $egId = (int) (new ScanService($egm, $eg))->start(184)['run_id'];
    $egW = (new ScanService($egm, $eg))->work(184, $egId);
    check('rv5b-2: a run over an empty group, which ends before any fence, is not promised its counts',
        $eg->run(184, $egId)['coverage'] === ScanOutcome::EMPTY_SCOPE
        && $egW['status']['withheldWhy'] === ScanAuthorization::WITHHELD_ENDED);

    /* -----------------------------------------------------------------
     * rvf1-1  A GROUP-SCOPED RUN THAT PASSED ITS TARGET FENCE AND IS THEN
     * STOPPED OR EXPIRED KEEPS ITS COUNTS
     *
     * The reviewer's repro: catch-up had walked the window, the run showed
     * its counts in unique-finalize, and Stop took them away with two
     * sentences saying it had never confirmed its group. A run stopped
     * inside catch-up is the control: it has confirmed nothing.
     * ----------------------------------------------------------------- */
    $afterFence = function ($pid, $leave, $end) use ($groupModule) {
        $ps = new ArrayScanStore();
        $pm = $groupModule($ps);
        $pm->flagged['R02'] = true;
        $rid = (int) (new ScanService($pm, $ps))->start($pid)['run_id'];
        $pm->readSleep = 3.2;
        (new ScanService($pm, $ps))->work($pid, $rid);
        $ep = (int) $ps->run($pid, $rid)['lease_epoch'];
        $ps->advancePhase($rid, $ep, ScanPhase::CATCH_UP);
        $ps->setProgressState($rid, $ep, ['fenceTarget' => '120', 'catchupCursor' => null,
                                          'catchupRound' => 1, 'catchupDirty' => 0]);
        if ($leave) $ps->advancePhase($rid, $ep, ScanPhase::UNIQUE);
        $before = (new ScanService($pm, $ps))->status($pid, $rid);
        if ($end === 'stopped') {
            (new ScanService($pm, $ps))->cancel($pid, $rid);
        } else {
            $ps->expireAbandoned($pid, 1, 'cron', time() + 2 * 86400);
        }
        $st = (new ScanService($pm, $ps))->status($pid, $rid);
        return ['before' => $before, 'row' => $ps->run($pid, $rid), 'status' => $st,
                'pf' => \INSPIRE\UniversalValidator\ScanPageView::panelPrefill($st)];
    };
    foreach (['stopped' => [191, ScanOutcome::CANCELLED], 'expired' => [192, ScanOutcome::EXPIRED]] as $end => $c) {
        $af = $afterFence($c[0], true, $end);
        check('rvf1-1: a group run past its target fence shows its counts in unique-finalize (' . $end . ' next)',
            $af['before']['phase'] === ScanPhase::UNIQUE && empty($af['before']['withheld'])
            && $af['before']['total'] === 5 && $af['before']['findings'] === 1);
        check('rvf1-1: and still shows them once ' . $end,
            $af['row']['terminal'] === $c[1] && !empty($af['row']['fence_target'])
            && $af['status']['ok'] === true && empty($af['status']['withheld'])
            && $af['status']['total'] === 5 && $af['status']['done'] === 5 && $af['status']['findings'] === 1
            && strpos($af['pf']['counts'], '5 of 5 records') === 0);
        check('rvf1-1: with no word of a group it never confirmed (' . $end . ')',
            strpos($af['pf']['counts'], ScanAuthorization::WITHHELD_ENDED) === false
            && strpos((string) $af['pf']['done'], \INSPIRE\UniversalValidator\ScanPageView::STOPPED_WITHHELD) === false
            && strpos((string) $af['pf']['done'],
                      \INSPIRE\UniversalValidator\ScanPageView::coverageSentence($af['row']['coverage'])) === 0);
    }
    $inCu = $afterFence(193, false, 'stopped');
    check('rvf1-1 control: a group run stopped inside catch-up is still not shown its counts',
        $inCu['row']['terminal'] === ScanOutcome::CANCELLED && !empty($inCu['row']['fence_target'])
        && $inCu['status']['withheld'] === true
        && $inCu['status']['withheldWhy'] === ScanAuthorization::WITHHELD_ENDED
        && strpos((string) $inCu['pf']['done'], \INSPIRE\UniversalValidator\ScanPageView::STOPPED_WITHHELD) === 0);

    /* -----------------------------------------------------------------
     * rvf1-2  A GROUP THAT LEFT IS NOT A GROUP THAT WAS DELETED
     *
     * The reviewer's repro: every record of the group moved to another
     * group after the run read it. The run ends as an empty scope, which
     * is right, and said its records had been deleted, under "Preparing".
     * ----------------------------------------------------------------- */
    $leftRun = function ($pid, array $moved, array $deleted) use ($groupModule) {
        $ls = new ArrayScanStore();
        $lm = $groupModule($ls);
        $lm->flagged['R02'] = true;
        $rid = (int) (new ScanService($lm, $ls))->start($pid)['run_id'];
        $lm->readSleep = 3.2;
        (new ScanService($lm, $ls))->work($pid, $rid);
        foreach ($moved as $id) {
            $lm->dagOf[$id] = '31';
            $lm->version[$id] = '110';
            $lm->changes[$id] = '110';
        }
        foreach ($deleted as $id) {
            $lm->records = array_values(array_diff($lm->records, [$id]));
            $lm->changes[$id] = '111';
        }
        $lm->top = '120';
        for ($i = 0; $i < 15 && $ls->run($pid, $rid)['terminal'] === null; $i++) {
            (new ScanService($lm, $ls))->work($pid, $rid);
        }
        $st = (new ScanService($lm, $ls))->status($pid, $rid);
        return ['row' => $ls->run($pid, $rid), 'states' => $ls->recordStates($rid), 'status' => $st,
                'pf' => \INSPIRE\UniversalValidator\ScanPageView::panelPrefill($st)];
    };
    $V = '\INSPIRE\UniversalValidator\ScanPageView';
    $gone = $leftRun(194, ['R01', 'R02', 'R03', 'R04', 'R05'], []);
    check('rvf1-2: a run whose whole group moved away ends as an empty scope',
        $gone['row']['terminal'] === ScanOutcome::PARTIAL && $gone['row']['coverage'] === ScanOutcome::EMPTY_SCOPE
        && isset($gone['states'][ScanStore::REC_DEPARTED]) && $gone['states'][ScanStore::REC_DEPARTED] === 5);
    check('rvf1-2: and its stored reason says they moved, not that they were deleted',
        strpos((string) $gone['row']['terminal_reason'], 'moved to another Data Access Group while it ran') !== false
        && strpos((string) $gone['row']['terminal_reason'], 'deleted') === false);
    check('rvf1-2: the status says records left the group, and counts none of them',
        $gone['status']['leftGroup'] === true && $gone['status']['total'] === 0
        && $gone['status']['removed'] === 0);
    check('rvf1-2: the panel says every record moved to another group, and 0 records, not Preparing',
        strpos((string) $gone['pf']['done'], $V::EMPTY_SCOPE_LEFT) === 0
        && strpos((string) $gone['pf']['done'], 'deleted') === false
        && $gone['pf']['counts'] === $V::COUNT_NONE);
    $mixed = $leftRun(195, ['R01', 'R02', 'R03'], ['R04', 'R05']);
    check('rvf1-2: a group that partly moved and was partly deleted says both',
        $mixed['row']['coverage'] === ScanOutcome::EMPTY_SCOPE
        && strpos((string) $mixed['row']['terminal_reason'], 'moved to another Data Access Group or was deleted') !== false
        && strpos((string) $mixed['pf']['done'], $V::EMPTY_SCOPE_LEFT_DELETED) === 0
        && $mixed['status']['removed'] === 2);
    $kept = $leftRun(196, ['R03'], []);
    check('rvf1-2 control: a group one record left still finishes on the records it kept',
        $kept['row']['coverage'] === ScanOutcome::FENCED && $kept['status']['leftGroup'] === true
        && strpos((string) $kept['pf']['done'], $V::coverageSentence(ScanOutcome::FENCED)) === 0);

    /* -----------------------------------------------------------------
     * rvf2-1  A START THAT SAVES ITS RUN WHILE THE MODULE IS BEING
     * SWITCHED OFF ENDS THAT RUN
     *
     * The reviewer's repro: a Start past available() when the disable hook
     * wrote its marker saved its run after the hook's walks, and the run
     * held the project's slot through the disable. Here the marker is
     * written while Start plans, after available() said yes and before the
     * run is saved. A run somebody stopped before Start asked again keeps
     * that stop.
     * ----------------------------------------------------------------- */
    $offStart = function ($pid, $by, $stopBy = null) {
        $os = new ArrayScanStore();
        $om = new class($os) extends \E2eModule {
            /** @var ?string 'server' or 'project': the disable marked while Start plans */
            public $offBy = null;
            public $offAt = null;
            /** @var ?string a user who stops the run before Start asks again */
            public $stopBy = null;
            public function durableScanContext($pid, array $opts = [], $dag = null)
            {
                if ($this->offBy !== null && array_key_exists('generation', $opts) && $opts['generation'] === null) {
                    $this->offAt = (string) time();
                    if ($this->offBy === 'server') $this->sys[ScanService::DISABLED_AT] = $this->offAt;
                    else $this->proj[ScanService::PROJECT_DISABLED_AT] = $this->offAt;
                }
                return parent::durableScanContext($pid, $opts, $dag);
            }
            public function getProjectSetting($k, $pid = null)
            {
                if ($k === ScanService::PROJECT_DISABLED_AT && $this->stopBy !== null) {
                    foreach ($this->store->allAudits() as $a) {
                        if ($a['event'] !== 'start') continue;
                        $this->store->cancel($a['pid'], $a['run_id'], $this->stopBy);
                        $this->stopBy = null;
                    }
                }
                return parent::getProjectSetting($k, $pid);
            }
        };
        $om->offBy = $by;
        $om->stopBy = $stopBy;
        $start = (new ScanService($om, $os))->start($pid);
        $audits = $os->allAudits();
        $rid = $audits ? (int) $audits[0]['run_id'] : 0;
        $row = $rid > 0 ? $os->run($pid, $rid) : null;
        // The disable over: its enable marker lets the next Start run.
        $om->offBy = null;
        if ($by === 'server') $om->sys[ScanService::ENABLED_AT] = $om->offAt;
        else $om->proj[ScanService::PROJECT_ENABLED_AT] = $om->offAt;
        $again = (new ScanService($om, $os))->start($pid);
        $trail = [];
        foreach ($audits as $a) $trail[] = $a['event'] . ':' . $a['actor'];
        return ['start' => $start, 'row' => $row, 'trail' => $trail, 'again' => $again, 'rid' => $rid];
    };
    foreach (['server' => [197, ScanService::OFF_SERVER, ScanMaintenance::DISABLED_WHY],
              'project' => [198, ScanService::OFF_PROJECT, ScanMaintenance::PROJECT_DISABLED_WHY]] as $by => $c) {
        $os1 = $offStart($c[0], $by);
        check('rvf2-1: a Start that saves its run while the module is being switched off (' . $by . ') is refused, and says why',
            $os1['start']['ok'] === false && $os1['start']['run_id'] === null && $os1['start']['why'] === $c[1]);
        check('rvf2-1: and its run is ended as the disable ends one: cancelled, with the disable\'s sentence (' . $by . ')',
            $os1['rid'] > 0 && $os1['row']['phase'] === ScanPhase::TERMINAL
            && $os1['row']['terminal'] === ScanOutcome::CANCELLED && $os1['row']['terminal_reason'] === $c[2]);
        check('rvf2-1: its trail names the starter, then the system\'s stop (' . $by . ')',
            $os1['trail'] === ['start:alice', 'cancel:system']);
        check('rvf2-1: and the project\'s slot is free once the disable is over (' . $by . ')',
            $os1['again']['ok'] === true && (int) $os1['again']['run_id'] > $os1['rid']);
    }
    $os2 = $offStart(199, 'project', 'bob');
    check('rvf2-1: a run somebody stopped before the Start asked again keeps their stop',
        $os2['start']['ok'] === false && $os2['start']['why'] === ScanService::OFF_PROJECT
        && $os2['row']['terminal'] === ScanOutcome::CANCELLED
        && $os2['row']['terminal_reason'] === ScanOutcome::derive(['cancelled' => true])['why']
        && $os2['trail'] === ['start:alice', 'cancel:bob']);

    /* -----------------------------------------------------------------
     * rv5b-4  A RUN OUTSIDE THE READER'S GROUP IS "NO SUCH RUN", WORD FOR
     * WORD, IN STATUS, WORK AND CANCEL
     *
     * The reviewer's repro: a group-7 user asked about group 31's run, a
     * whole-project run and an id that is no run, and got three different
     * sentences - so run ids could be probed for other groups' scans. And a
     * context that could not be built for the run's group answered with its
     * own sentence before the scope was asked.
     * ----------------------------------------------------------------- */
    $os = new ArrayScanStore();
    $om = $groupModule($os);
    $om->user->rights['group_id'] = 31;
    $o31 = (int) (new ScanService($om, $os))->start(185)['run_id'];
    for ($i = 0; $i < 10 && $os->run(185, $o31)['terminal'] === null; $i++) {
        (new ScanService($om, $os))->work(185, $o31);
    }
    $om->user->rights['group_id'] = null;
    $oAll = (int) (new ScanService($om, $os))->start(185)['run_id'];
    $om->user->rights['group_id'] = 7;
    $askAll = function ($id) use ($om, $os) {
        $svc = new ScanService($om, $os);
        return ['status' => $svc->status(185, $id), 'work' => $svc->work(185, $id),
                'cancel' => $svc->cancel(185, $id)];
    };
    $none = $askAll(999);
    check('rv5b-4: an id that is no run is refused as no such run, by every verb',
        $none['status']['why'] === ScanService::NO_RUN && $none['work']['why'] === ScanService::NO_RUN
        && $none['cancel']['why'] === ScanService::NO_RUN && ScanService::NO_RUN === ScanAuthorization::NO_RUN);
    check('rv5b-4: another group\'s run gets exactly the same answers',
        $o31 > 0 && $askAll($o31) === $none);
    check('rv5b-4: and so does a run over the whole project',
        $oAll > 0 && $oAll !== $o31 && $askAll($oAll) === $none);
    // The context for another group's run cannot be built just now: the
    // reader is still told only that there is no such run.
    $broken = new class($os) extends \E2eModule {
        public function durableScanContext($pid, array $opts = [], $dag = null)
        {
            return ['ok' => false, 'why' => 'this project has no rules this scan can evaluate',
                    'plan' => null, 'evaluate' => null, 'read' => null, 'rules' => [],
                    'ownership' => [], 'problems' => []];
        }
    };
    $broken->user->rights['group_id'] = 7;
    $bsvc = new ScanService($broken, $os);
    check('rv5b-4: a context that cannot be built for another group\'s run does not say so',
        $bsvc->status(185, $o31) === $none['status'] && $bsvc->cancel(185, $o31) === $none['cancel']
        && $bsvc->work(185, $o31) === $none['work']);
    $broken->user->rights['group_id'] = 31;
    check('rv5b-4 control: the run\'s own group is told why its context could not be built',
        (new ScanService($broken, $os))->status(185, $o31)['why']
        === 'this project has no rules this scan can evaluate');

    // L8: THE READER IS DECIDED BEFORE THE RUN ROW. Every verb read the run
    // first, so an id that is no run said so, and a run that exists answered
    // with the reader's own refusal: the ids of the project's runs could be
    // told apart by a person the scan refuses.
    $om->user->rights['group_id'] = 31;
    $om->user->design = false;
    $refusedNone = $askAll(999);
    $refusedRun = $askAll($o31);
    check('L8: a reader without design rights is told the same about a run that exists and an id that is none, by every verb',
        $refusedRun === $refusedNone
        && strpos($refusedNone['status']['why'], 'design rights') !== false
        && $refusedNone['work']['why'] === $refusedNone['status']['why']
        && $refusedNone['cancel']['why'] === $refusedNone['status']['why']);
    $om->user->design = true;
    check('L8 control: with design rights the run is there for its own group',
        (new ScanService($om, $os))->status(185, $o31)['ok'] === true);
    $elsewhere = new ScanService($om, $os);
    $other = ['status' => $elsewhere->status(186, $o31), 'work' => $elsewhere->work(186, $o31),
              'cancel' => $elsewhere->cancel(186, $o31)];
    check('L8: another project\'s run id is no run, in the words every verb uses for one',
        $other['status']['why'] === ScanService::NO_RUN && $other['work']['why'] === ScanService::NO_RUN
        && $other['cancel']['why'] === ScanService::NO_RUN);

    /* -----------------------------------------------------------------
     * B5  scan-findings: ONE KEYSET PAGE OF A FINISHED RUN'S FINDINGS,
     * FOR THIS READER, AND NOTHING ELSE
     * ----------------------------------------------------------------- */
    $fs = new ArrayScanStore();
    $fm = $groupModule($fs);
    foreach ($fm->records as $id) {
        $fm->flagged[$id] = true;
        $fm->dagNames[$id] = $fm->dagOf[$id] === '7' ? 'site_a' : 'site_b';
    }
    $runTo = function ($pid) use ($fm, $fs) {
        $rid = (int) (new ScanService($fm, $fs))->start($pid)['run_id'];
        for ($i = 0; $i < 15 && $rid > 0 && $fs->run($pid, $rid)['terminal'] === null; $i++) {
            (new ScanService($fm, $fs))->work($pid, $rid);
        }
        return $rid;
    };
    $fm->user->rights['group_id'] = null;
    $fAll = $runTo(186);
    // A group-31 run in which one record still carries group 7's name, as a
    // finding written before a move would: the reader's scope must hold it out.
    $fm->dagNames['R06'] = 'site_a';
    $fm->user->rights['group_id'] = 31;
    $f31 = $runTo(186);
    $fm->dagNames['R06'] = 'site_b';
    $fm->user->rights['group_id'] = 7;
    $f7 = $runTo(186);
    $read = function ($rid, array $req = [], $group = null, $pid = 186) use ($fm, $fs) {
        $fm->user->rights['group_id'] = $group;
        return (new ScanService($fm, $fs))->findings($pid, $rid, $req);
    };
    $recs = function (array $page) {
        $out = [];
        foreach (isset($page['rows']) ? $page['rows'] : [] as $r) $out[] = $r['cells']['record'];
        sort($out);
        return $out;
    };
    $whole = $read($fAll, ['limit' => 100]);
    check('B5: the three fixture runs finished complete',
        $fs->run(186, $fAll)['terminal'] === ScanOutcome::COMPLETE && $fs->run(186, $f31)['terminal'] === ScanOutcome::COMPLETE
        && $fs->run(186, $f7)['terminal'] === ScanOutcome::COMPLETE);
    check('B5: a whole-project reader gets every finding of a whole-project run, with the columns of the export',
        !empty($whole['ok']) && $recs($whole) === $fm->records && $whole['next'] === null
        && $whole['columns'][0]['key'] === 'issue'
        && array_keys($whole['rows'][0]['cells']) === array_column($whole['columns'], 'key')
        && $whole['report']['schema'] === ScanReport::SCHEMA && $whole['report']['expectedRows'] === 10);
    $walked = [];
    $after = 0;
    $pages = 0;
    do {
        $p = $read($fAll, ['after' => $after, 'limit' => 3]);
        foreach ($p['rows'] as $r) $walked[] = $r['id'];
        $after = $p['next'];
        $pages++;
    } while ($after !== null && $pages < 10);
    check('B5: pages of 3 walk the run through `next`, every finding once',
        $pages === 4 && count($walked) === 10 && count(array_unique($walked)) === 10);
    $bySite = $read($fAll, ['filter' => ['group' => 'site_b']]);
    check('B5: filtered by group, it is that group\'s findings',
        $recs($bySite) === ['R06', 'R07', 'R08', 'R09', 'R10'] && $bySite['filter']['group'] === 'site_b');
    check('B5: filters by form, reason and check type narrow it as the columns say',
        count($read($fAll, ['filter' => ['form' => 'fa', 'reason' => 'required-blank', 'type' => 'required']])['rows']) === 10
        && $read($fAll, ['filter' => ['form' => 'zz']])['rows'] === []
        && $read($fAll, ['filter' => ['type' => 'range']])['rows'] === []);
    $facets = $whole['facets'];
    check('B5: the filters offer what the summary counted',
        $facets['form'] === [['value' => 'fa', 'label' => 'fa', 'count' => 10]]
        && $facets['group'] === [['value' => 'site_a', 'label' => 'site_a', 'count' => 5],
                                 ['value' => 'site_b', 'label' => 'site_b', 'count' => 5]]);
    check('B5: a filter that is not one value is refused',
        $read($fAll, ['filter' => ['form' => ['fa', 'fb']]])['ok'] === false);

    // THE GROUP. A group reader is held to the group's findings in their own
    // run, whatever filter or cursor they send, and a run that is not theirs
    // is no run.
    $mine = $read($f31, ['limit' => 100], 31);
    check('B5 group: a group reader sees their group\'s findings and not a row filed under another group',
        !empty($mine['ok']) && $recs($mine) === ['R07', 'R08', 'R09', 'R10']);
    check('B5 group (control): an unconfined reader of the same run sees the row filed under the other group',
        $recs($read($f31, ['limit' => 100])) === ['R06', 'R07', 'R08', 'R09', 'R10']);
    $crafted = $read($f31, ['filter' => ['group' => 'site_a'], 'limit' => 100], 31);
    check('B5 group: a filter naming another group reads nothing, rather than that group',
        !empty($crafted['ok']) && $crafted['rows'] === []);
    $cursor = $read($f31, ['after' => 0, 'limit' => 1], 31);
    $fromOther = $read($f31, ['after' => (int) $whole['rows'][0]['id'] - 1, 'limit' => 100], 31);
    check('B5 group: a cursor taken from another run reads only this run\'s group rows',
        !empty($cursor['ok']) && count($cursor['rows']) === 1
        && array_diff($recs($fromOther), ['R07', 'R08', 'R09', 'R10']) === []);
    check('B5 group: the group filter offers only the reader\'s group',
        array_column($mine['facets']['group'], 'value') === ['site_b']);
    $noneF = $read(999, [], 31);
    check('B5 group: a whole-project run read by a group reader is no run, word for word',
        $noneF === ['ok' => false, 'why' => ScanService::NO_RUN, 'final' => true]
        && $read($fAll, [], 31) === $noneF && $read($fAll, ['filter' => ['group' => 'site_b']], 31) === $noneF);
    check('B5 group: and so is another group\'s run', $read($f7, [], 31) === $noneF);
    $fm->user->design = false;
    $noDesign = $read(999, [], 31);
    check('B5 L8: a reader without design rights is told the same about a run and about no run',
        $read($f31, [], 31) === $noDesign && $noDesign['ok'] === false && $noDesign['why'] !== ScanService::NO_RUN);
    $fm->user->design = true;
    check('B5: another project\'s run is no run', $fm && (new ScanService($fm, $fs))->findings(187, $fAll)['why'] === ScanService::NO_RUN);
    // W11A-N2. A designer below Full Data Set export is refused every run.
    // Asked after the run row, the refusal named the export level for a run
    // and said NO_RUN for an id that is none.
    $verbs = function ($rid) use ($fm, $fs) {
        $fm->user->rights['group_id'] = null;
        return ['status' => (new ScanService($fm, $fs))->status(186, $rid),
                'work'   => (new ScanService($fm, $fs))->work(186, $rid),
                'cancel' => (new ScanService($fm, $fs))->cancel(186, $rid),
                'page'   => (new ScanService($fm, $fs))->findings(186, $rid)];
    };
    foreach (['2' => 'De-Identified', '0' => 'No Access'] as $level => $name) {
        $fm->user->rights['data_export_tool'] = $level;
        $none = $verbs(999);
        check('W11A-N2: a reader with ' . $name . ' export is told the same about a run and about no run, by every verb',
            $verbs($fAll) === $none && $none['status']['ok'] === false
            && strpos($none['status']['why'], 'Full Data Set') !== false
            && $none['work']['why'] === $none['status']['why'] && $none['cancel']['why'] === $none['status']['why']
            && $none['page']['why'] === $none['status']['why']);
    }
    $fm->user->rights['data_export_tool'] = '1';
    check('W11A-N2 (control): with Full Data Set back, the run is there and the id is not',
        !empty($verbs($fAll)['status']['ok']) && $verbs(999)['status']['why'] === ScanService::NO_RUN
        && $fs->run(186, $fAll)['terminal'] === ScanOutcome::COMPLETE);

    // A RUN THAT HAS NOT FINISHED HAS NO REPORT, AND ONE THAT HAS NOT PROVED
    // ITS GROUP SHOWS NO ROW.
    $fm->user->rights['group_id'] = null;
    $open = (int) (new ScanService($fm, $fs))->start(188)['run_id'];
    $unfinished = $read($open, [], null, 188);
    check('B5: an unfinished run is refused, as one to ask again about',
        $unfinished['ok'] === false && $unfinished['final'] === false
        && strpos($unfinished['why'], 'has not finished') !== false);
    (new ScanService($fm, $fs))->cancel(188, $open);
    $fm->user->rights['group_id'] = 31;
    $stopped = (int) (new ScanService($fm, $fs))->start(188)['run_id'];
    (new ScanService($fm, $fs))->cancel(188, $stopped);
    $held = $read($stopped, [], 31, 188);
    check('B5: a group run stopped before it proved its group answers with no rows, no facets, and why',
        $fs->run(188, $stopped)['terminal'] === ScanOutcome::CANCELLED
        && !empty($held['ok']) && $held['withheld'] === true && $held['rows'] === [] && $held['facets'] === null
        && $held['withheldWhy'] === ScanAuthorization::WITHHELD_ENDED && $held['next'] === null);
    check('B5: and its marks say it is incomplete, three ways',
        $held['marks']['incomplete'] === true && $held['marks']['banner'] !== null && $held['marks']['row'] !== null
        && strpos($held['marks']['suffix'], '_INCOMPLETE') === 0);
    check('B5 (control): a run that finished complete carries no incomplete mark, and is named not certified',
        $whole['marks']['incomplete'] === false && $whole['marks']['banner'] === null && $whole['marks']['suffix'] === '_NOT-CERTIFIED');

    // THE EXPORT: the same gate, with one question more before the run row.
    $csv = function ($rid, $group = null, $pid = 186, $store = null) use ($fm, $fs) {
        $fm->user->rights['group_id'] = $group;
        $x = (new ScanService($fm, $store === null ? $fs : $store))->exportOpen($pid, $rid);
        if (empty($x['ok'])) return $x;
        $buf = '';
        $flushes = 0;
        $x['result'] = $x['stream'](function ($s) use (&$buf) { $buf .= $s; },
                                    function () use (&$flushes) { $flushes++; });
        $x['text'] = $buf;
        $x['flushes'] = $flushes;
        unset($x['stream']);
        return $x;
    };
    $wholeCsv = $csv($fAll);
    check('B5 export: a whole-project reader downloads every finding of the run, ending on the trailer',
        !empty($wholeCsv['ok']) && $wholeCsv['result']['rows'] === 10 && $wholeCsv['result']['ok'] === true
        && strpos($wholeCsv['text'], '"# export_complete=1","rows=10"') !== false
        && strpos($wholeCsv['text'], '"# expected_rows","10"') !== false
        && strpos($wholeCsv['text'], '"# scope","whole project"') !== false
        && strpos($wholeCsv['text'], '"# run","' . $fAll . '"') !== false);
    check('B5 export: the file is named for the project, the run and the day, and says it does not certify',
        preg_match('/^uv-scan-186-run' . $fAll . '-\d{8}_NOT-CERTIFIED\.csv$/', $wholeCsv['filename']) === 1
        && strpos($wholeCsv['text'], 'INCOMPLETE') === false);
    $mineCsv = $csv($f31, 31);
    check('B5 export group: a group reader\'s file holds their group\'s findings and not the row filed under another group',
        !empty($mineCsv['ok']) && $mineCsv['result']['rows'] === 4 && strpos($mineCsv['text'], '"R06"') === false
        && strpos($mineCsv['text'], '"R07"') !== false);
    check('B5 export group: and the count it promises is the count it holds',
        strpos($mineCsv['text'], '"# expected_rows","4"') !== false
        && strpos($mineCsv['text'], '"# scope","Data Access Group site_b"') !== false);
    check('B5 group: the findings page promises a group reader the same count',
        $read($f31, [], 31)['report']['expectedRows'] === 4 && $read($f31)['report']['expectedRows'] === 5);
    $noneX = $csv(999, 31);
    check('B5 export group: a whole-project run, another group\'s run and no run are refused alike, as no run',
        $noneX === ['ok' => false, 'http' => 403, 'why' => ScanService::NO_RUN]
        && $csv($fAll, 31) === $noneX && $csv($f7, 31) === $noneX);
    check('B5 export: another project\'s run is no run', $csv($fAll, null, 187) === $noneX);
    $fm->user->rights['data_export_tool'] = '0';
    $noExport = $csv(999);
    check('B5 export L8: a reader without export rights is told the same about a run and about no run',
        $noExport['ok'] === false && $noExport['http'] === 403 && strpos($noExport['why'], 'Full Data Set') !== false
        && $csv($fAll) === $noExport);
    check('B5 export: a stored run is read on screen only with Full Data Set rights, so the page refuses them alike',
        $read($fAll) === ['ok' => false, 'why' => $noExport['why'], 'final' => true] && $read(999) === $read($fAll));
    $fm->user->rights['data_export_tool'] = '2';
    check('W11A-N2: so is a De-Identified exporter, whose download the framework would allow',
        $csv(999) === $csv($fAll) && $csv(999)['http'] === 403 && strpos($csv(999)['why'], 'De-Identified') !== false);
    $fm->user->rights['data_export_tool'] = '1';
    $fm->user->design = false;
    $noDesignX = $csv(999);
    check('B5 export L8: a reader without design rights is told the same about a run and about no run',
        $noDesignX['ok'] === false && $noDesignX['http'] === 403 && $csv($fAll) === $noDesignX
        && $noDesignX['why'] !== ScanService::NO_RUN);
    $fm->user->design = true;
    $fm->user->rights['group_id'] = null;
    $running = (int) (new ScanService($fm, $fs))->start(189)['run_id'];
    $notYet = $csv($running, null, 189);
    check('B5 export: an unfinished run is no download yet, and 503 says to ask again',
        $notYet['ok'] === false && $notYet['http'] === 503 && strpos($notYet['why'], 'has not finished') !== false);
    $heldX = $csv($stopped, 31, 188);
    check('B5 export: a group run that never proved its group is refused, not handed over empty',
        $heldX === ['ok' => false, 'http' => 403, 'why' => ScanAuthorization::WITHHELD_ENDED]);
    $fm->user->rights['group_id'] = null;
    $halted = (int) (new ScanService($fm, $fs))->start(190)['run_id'];
    (new ScanService($fm, $fs))->cancel(190, $halted);
    $haltedCsv = $csv($halted, null, 190);
    check('B5 export (item 10): a stopped run\'s file says it is incomplete three ways',
        !empty($haltedCsv['ok']) && $fs->run(190, $halted)['terminal'] === ScanOutcome::CANCELLED
        && preg_match('/_INCOMPLETE_CANCELLED\.csv$/', $haltedCsv['filename']) === 1
        && strpos($haltedCsv['text'], "\xEF\xBB\xBF\"# INCOMPLETE SCAN") === 0
        && strpos($haltedCsv['text'], "\r\n\"" . ScanService::INCOMPLETE_LABEL . '","This scan did not cover') !== false
        && $haltedCsv['result']['ok'] === true);
    check('B5 export (item 10): and promises no row count, because a stopped run has no verdict',
        strpos($haltedCsv['text'], '"# expected_rows","unknown"') !== false);
    $broken = new FaultyStore($fs);
    $fm->user->rights['group_id'] = null;
    $bx = (new ScanService($fm, $broken))->exportOpen(186, $fAll);
    $broken->failFrom('findings');
    $fm->logs = [];
    $bbuf = '';
    $bres = $bx['stream'](function ($s) use (&$bbuf) { $bbuf .= $s; }, function () {});
    check('B5 export: a store that fails partway ends the file on ROWS WERE LOST, without the trailer',
        $bres['ok'] === false && strpos($bbuf, 'ROWS WERE LOST') !== false && strpos($bbuf, 'export_complete') === false);
    check('B5 export: and the failure is in the module log, not in the file',
        in_array('scan storage failure', array_column($fm->logs, 0), true) && strpos($bbuf, 'Deadlock') === false);
    \REDCap::$groupNames = $keptNames;
}

/* =====================================================================
 * W11 REVIEW ROUND (rv11a1, rv11a2): THE STORED REPORT SAYS WHAT THE RUN
 * WAS, AND ONLY WHAT ITS READER MAY BE TOLD
 *
 * The reviewers' repros over the B5 fixture: ten records, R01-R05 in group
 * 7 (site_a) and R06-R10 in group 31 (site_b), the a_val rule, and the
 * change log the group module writes.
 * ===================================================================== */
{
    $keptNames = \REDCap::$groupNames;
    \REDCap::$groupNames = [7 => 'site_a', 31 => 'site_b'];
    $PV = '\INSPIRE\UniversalValidator\ScanPageView';
    $wv = function (array $flag) use ($groupModule) {
        $s = new ArrayScanStore();
        $m = $groupModule($s);
        foreach ($m->records as $id) $m->dagNames[$id] = $m->dagOf[$id] === '7' ? 'site_a' : 'site_b';
        foreach ($flag as $id) $m->flagged[$id] = true;
        return [$s, $m];
    };
    $wvRun = function ($m, $s, $pid, $group) {
        $m->user->rights['group_id'] = $group;
        $rid = (int) (new ScanService($m, $s))->start($pid)['run_id'];
        for ($i = 0; $i < 15 && $rid > 0 && $s->run($pid, $rid)['terminal'] === null; $i++) {
            (new ScanService($m, $s))->work($pid, $rid);
        }
        return $rid;
    };
    $wvCsv = function ($m, $s, $pid, $rid, $group) {
        $m->user->rights['group_id'] = $group;
        $x = (new ScanService($m, $s))->exportOpen($pid, $rid);
        if (empty($x['ok'])) return $x;
        $buf = '';
        $x['result'] = $x['stream'](function ($t) use (&$buf) { $buf .= $t; }, function () {});
        $x['text'] = $buf;
        unset($x['stream']);
        return $x;
    };
    $wvPage = function ($m, $s, $pid, $rid, $group, array $req = []) {
        $m->user->rights['group_id'] = $group;
        return (new ScanService($m, $s))->findings($pid, $rid, $req);
    };
    $headOf = function ($text, $key) {
        foreach (explode("\r\n", $text) as $l) {
            if (strpos($l, '"# ' . $key . '"') === 0) return str_getcsv($l)[1];
        }
        return null;
    };

    /* rv11a1-1  THE FILE'S SCOPE IS THE RUN'S, NOT THE READER'S. A clean
     * run over group 31 downloaded by an unconfined reader said "whole
     * project", "Every record was checked" and "certified yes" while group
     * 7's two findings were never examined. */
    list($s1, $m1) = $wv(['R01', 'R02']);
    $g31 = $wvRun($m1, $s1, 201, 31);
    $all = $wvRun($m1, $s1, 202, null);
    $unconf = $wvCsv($m1, $s1, 201, $g31, null);
    check('rv11a1-1: the group run is clean, and its file read by an unconfined reader names its group',
        (int) $s1->run(201, $g31)['clean'] === 1 && !empty($unconf['ok'])
        && $headOf($unconf['text'], 'scope') === 'Data Access Group site_b');
    check('rv11a1-1: its coverage line says the run covered that group only, and its certificate is that group\'s',
        $headOf($unconf['text'], 'coverage') === $PV::coverageSentence(ScanOutcome::FENCED) . ' '
            . sprintf(ScanService::GROUP_ONLY, 'Data Access Group site_b')
        && $headOf($unconf['text'], 'certified') === 'yes, for Data Access Group site_b only');
    $own = $wvCsv($m1, $s1, 201, $g31, 31);
    check('rv11a1-1: the group\'s own reader gets the same head lines',
        $headOf($own['text'], 'scope') === 'Data Access Group site_b'
        && $headOf($own['text'], 'certified') === $headOf($unconf['text'], 'certified')
        && $headOf($own['text'], 'coverage') === $headOf($unconf['text'], 'coverage'));
    $wholeX = $wvCsv($m1, $s1, 202, $all, null);
    check('rv11a1-1 (control): a whole-project run says whole project, with no group sentence and a plain no',
        $headOf($wholeX['text'], 'scope') === 'whole project'
        && $headOf($wholeX['text'], 'coverage') === $PV::coverageSentence(ScanOutcome::FENCED)
        && $headOf($wholeX['text'], 'certified') === 'no');
    $p31 = $wvPage($m1, $s1, 201, $g31, null);
    $pAll = $wvPage($m1, $s1, 202, $all, null);
    check('rv11a1-1: the findings answer carries the run\'s scope, for the page to say the same',
        $p31['runScope'] === ['kind' => 'group', 'group' => 'site_b', 'label' => 'Data Access Group site_b']
        && $wvPage($m1, $s1, 201, $g31, 31)['runScope'] === $p31['runScope']
        && $pAll['runScope'] === ['kind' => 'project', 'group' => null, 'label' => 'whole project']);

    /* rv11a1-3  A RUN THAT NEVER PROVED ITS GROUP TELLS THE PAGE NO MORE
     * THAN ITS STATUS DOES. The reviewer's repro: a detail limit of 2, one
     * budgeted request, then Stop; the counts promotion would have kept are
     * planted on the row. status() withheld detail, ruleProblems and
     * notExamined; scan-findings handed out all three. */
    list($s3, $m3) = $wv(['R06', 'R07', 'R08', 'R09', 'R10']);
    $m3->proj['scan-max-detail-findings'] = '2';
    $m3->user->rights['group_id'] = 31;
    $w3 = (int) (new ScanService($m3, $s3))->start(203)['run_id'];
    $m3->readSleep = 3.2;
    (new ScanService($m3, $s3))->work(203, $w3);
    (new ScanService($m3, $s3))->cancel(203, $w3);
    \Closure::bind(function () use ($w3) {
        $this->runs[$w3]['rule_problem_count'] = 2;
        $this->runs[$w3]['not_examined'] = 3;
    }, $s3, ArrayScanStore::class)();
    $row3 = $s3->run(203, $w3);
    $st3 = (new ScanService($m3, $s3))->status(203, $w3);
    $pg3 = $wvPage($m3, $s3, 203, $w3, 31);
    check('rv11a1-3: the fixture is a stopped group run that kept only part of its findings and never proved its group',
        $row3['terminal'] === ScanOutcome::CANCELLED && $row3['detail'] === ScanOutcome::DETAIL_TRUNCATED
        && !ScanAuthorization::scopeProved($row3) && $st3['withheld'] === true
        && !array_key_exists('detail', $st3) && !array_key_exists('ruleProblems', $st3));
    check('rv11a1-3: its findings answer is withheld and carries neither count nor its detail',
        $pg3['ok'] === true && $pg3['withheld'] === true && $pg3['rows'] === [] && $pg3['facets'] === null
        && !array_key_exists('ruleProblems', $pg3) && !array_key_exists('notExamined', $pg3)
        && !array_key_exists('detail', $pg3['report']) && $pg3['clean'] === $st3['clean']);
    check('rv11a1-3: and no sentence or suffix in it says that findings were not kept',
        strpos($pg3['report']['coverageSentence'], $PV::DETAIL_TRUNCATED) === false
        && strpos($pg3['marks']['row'], $PV::DETAIL_TRUNCATED) === false
        && strpos($pg3['report']['suffix'], 'TRUNCATED') === false
        && strpos($pg3['marks']['suffix'], 'TRUNCATED') === false
        && strpos(json_encode($pg3), 'truncated') === false);
    check('rv11a1-3: what it does carry is the run\'s verdict and marks, as its status keeps them',
        $pg3['marks']['incomplete'] === true && $pg3['marks']['suffix'] === '_INCOMPLETE_CANCELLED'
        && $pg3['report']['coverage'] === $st3['coverage'] && $pg3['runScope']['kind'] === 'group'
        && $pg3['withheldWhy'] === ScanAuthorization::WITHHELD_ENDED);
    $m3->user->rights['group_id'] = null;
    check('rv11a1-3 (control): the same run read by an unconfined reader is withheld the same way',
        $wvPage($m3, $s3, 203, $w3, null)['report'] === $pg3['report']);

    /* rv11a1-5 / rv11a2-1  A REPORT ANOTHER RUN HAS REPLACED IS REFUSED,
     * AND A FILE EARNS ITS TRAILER AT THE END. Run A over the whole
     * project, then B: once B completes, A's findings are closed a page at
     * a time and A read as an empty finished report; a download under way
     * when that happened, or when retention began removing the run, ended
     * on export_complete=1 over the rows it happened to read. */
    $all10 = ['R01', 'R02', 'R03', 'R04', 'R05', 'R06', 'R07', 'R08', 'R09', 'R10'];
    list($s5, $m5) = $wv($all10);
    $a5 = $wvRun($m5, $s5, 204, null);
    $before5 = $wvPage($m5, $s5, 204, $a5, null, ['limit' => 100]);
    $m5->user->rights['group_id'] = null;
    $openA = (new ScanService($m5, $s5))->exportOpen(204, $a5);
    $b5 = $wvRun($m5, $s5, 204, null);
    $refusedA = $wvPage($m5, $s5, 204, $a5, null);
    check('rv11a1-5: before a later run completes, A is a report of its ten findings',
        count($before5['rows']) === 10 && $before5['report']['expectedRows'] === 10 && !empty($openA['ok']));
    check('rv11a1-5: once B has completed, A is refused, final, naming the run that replaced it',
        $s5->run(204, $b5)['terminal'] === ScanOutcome::COMPLETE
        && $refusedA === ['ok' => false, 'why' => sprintf(ScanService::SUPERSEDED_BY, $b5), 'final' => true]);
    check('rv11a1-5: and so is its file, before a byte is written',
        $wvCsv($m5, $s5, 204, $a5, null) === ['ok' => false, 'http' => 403, 'why' => sprintf(ScanService::SUPERSEDED_BY, $b5)]);
    $lostOf = function ($x) {
        $buf = '';
        $res = $x['stream'](function ($t) use (&$buf) { $buf .= $t; }, function () {});
        return ['res' => $res, 'text' => $buf, 'lost' => strpos($buf, '"# ' . ScanService::ROWS_LOST . '"') !== false
            && strpos($buf, 'export_complete') === false && strpos($buf, '"' . ScanService::LOST_LABEL . '"') !== false];
    };
    $midA = $lostOf($openA);
    check('rv11a2-1: a download of A opened before B completed ends as a file that lost rows, not a whole one',
        $midA['res']['ok'] === false && $midA['lost']);
    do { $sp5 = $s5->supersedePrevious(204, $b5, 100); } while (!$sp5['done']);
    check('rv11a1-5: after the supersede has closed A\'s rows, A is still refused the same way',
        $wvPage($m5, $s5, 204, $a5, null) === $refusedA);
    $wholeB = $wvCsv($m5, $s5, 204, $b5, null);
    check('rv11a1-5 (control): the run that replaced it is the report, and its file earns the trailer',
        !empty($wvPage($m5, $s5, 204, $b5, null)['ok']) && $wholeB['result']['ok'] === true
        && strpos($wholeB['text'], '"# export_complete=1","rows=10"') !== false);

    // A GROUP RUN replaced by a whole-project run: its own reader is refused
    // without the id of a run they may not read.
    list($s6, $m6) = $wv($all10);
    $g6 = $wvRun($m6, $s6, 205, 31);
    $w6 = $wvRun($m6, $s6, 205, null);
    check('rv11a1-5: a group run replaced by a whole-project run is refused to its reader, naming no run',
        $wvPage($m6, $s6, 205, $g6, 31) === ['ok' => false, 'why' => ScanService::SUPERSEDED, 'final' => true]
        && $wvCsv($m6, $s6, 205, $g6, 31) === ['ok' => false, 'http' => 403, 'why' => ScanService::SUPERSEDED]
        && strpos(ScanService::SUPERSEDED, (string) $w6) === false);
    check('rv11a1-5: and an unconfined reader is told which run replaced it',
        $wvPage($m6, $s6, 205, $g6, null)['why'] === sprintf(ScanService::SUPERSEDED_BY, $w6));
    check('rv11a1-5 (control): another group\'s reader is still told it is no run',
        $wvPage($m6, $s6, 205, $g6, 7) === ['ok' => false, 'why' => ScanService::NO_RUN, 'final' => true]);

    // THE RECHECK, EACH HALF ON ITS OWN. A stopped run promises no count, so
    // only the run read again can tell that B replaced it during its download;
    // a run retention begins removing mid-download still has every row; and
    // a store that hands back one row fewer than the summary counted is caught
    // by the count alone.
    list($s7, $m7) = $wv($all10);
    $m7->user->rights['group_id'] = null;
    $c7 = (int) (new ScanService($m7, $s7))->start(206)['run_id'];
    $m7->readSleep = 3.2;
    (new ScanService($m7, $s7))->work(206, $c7);
    (new ScanService($m7, $s7))->cancel(206, $c7);
    $openC = (new ScanService($m7, $s7))->exportOpen(206, $c7);
    $d7 = $wvRun($m7, $s7, 206, null);
    $midC = $lostOf($openC);
    check('rv11a2-1: a stopped run replaced while it downloads, which promised no count, still ends as lost',
        $s7->run(206, $c7)['terminal'] === ScanOutcome::CANCELLED && strpos($midC['text'], '"# expected_rows","unknown"') !== false
        && $midC['res']['rows'] > 0 && $midC['res']['ok'] === false && $midC['lost']
        && strpos($midC['text'], '"' . ScanService::INCOMPLETE_LABEL . '"') !== false);
    $e7 = $wvRun($m7, $s7, 207, null);
    $m7->user->rights['group_id'] = null;
    $openE = (new ScanService($m7, $s7))->exportOpen(207, $e7);
    \Closure::bind(function () use ($e7) {
        $this->runs[$e7]['terminal_reason'] = ScanStore::REMOVING_WHY;
    }, $s7, ArrayScanStore::class)();
    $midE = $lostOf($openE);
    check('rv11a2-1: a run retention begins removing while it downloads ends as lost, though every row was read',
        $s7->run(207, $e7) === null && $midE['res']['rows'] === 10 && $midE['res']['ok'] === false && $midE['lost']);
    $f7 = $wvRun($m7, $s7, 208, null);
    $short = new class($s7) extends FaultyStore {
        public function findings($projectId, $generationId, array $scope, array $filter, $afterId, $limit,
                                 &$through = null)
        {
            $rows = parent::findings($projectId, $generationId, $scope, $filter, $afterId, $limit, $through);
            if ((int) $afterId === 0) array_shift($rows);
            return $rows;
        }
    };
    $m7->user->rights['group_id'] = null;
    $midF = $lostOf((new ScanService($m7, $short))->exportOpen(208, $f7));
    check('rv11a2-1: a file that wrote fewer rows than its head promised ends as lost',
        $midF['res']['rows'] === 9 && strpos($midF['text'], '"# expected_rows","10"') !== false
        && $midF['res']['ok'] === false && $midF['lost']);
    $okF = $lostOf((new ScanService($m7, $s7))->exportOpen(208, $f7));
    check('rv11a2-1 (control): the same run read whole earns the trailer',
        $okF['res']['ok'] === true && $okF['res']['rows'] === 10 && !$okF['lost']
        && strpos($okF['text'], '"# export_complete=1","rows=10"') !== false);

    /* rv11a1-4  A GROUP READER'S FILTERS COUNT THEIR GROUP'S ROWS. B5's own
     * fixture: a group-31 run in which R06 is filed under site_a, as a
     * duplicate emitted after a move would be. Rows, expectedRows and the
     * group facet held it out; the form, reason and type facets said 5. */
    list($s8, $m8) = $wv($all10);
    $m8->dagNames['R06'] = 'site_a';
    $g8 = $wvRun($m8, $s8, 209, 31);
    $mine8 = $wvPage($m8, $s8, 209, $g8, 31, ['limit' => 100]);
    $one = function ($v, $c) { return [['value' => $v, 'label' => $v, 'count' => $c]]; };
    check('rv11a1-4: the group reader is shown four rows, and every facet counts four',
        count($mine8['rows']) === 4 && $mine8['report']['expectedRows'] === 4
        && $mine8['facets']['form'] === $one('fa', 4) && $mine8['facets']['reason'] === $one('required-blank', 4)
        && $mine8['facets']['type'] === $one('required', 4) && $mine8['facets']['group'] === $one('site_b', 4));
    check('rv11a1-4: so a filter returns what its facet promised',
        count($wvPage($m8, $s8, 209, $g8, 31, ['limit' => 100, 'filter' => ['form' => 'fa']])['rows']) === 4);
    $other8 = $wvPage($m8, $s8, 209, $g8, null, ['limit' => 100]);
    check('rv11a1-4 (control): an unconfined reader of the same run counts all five, once per value',
        count($other8['rows']) === 5 && $other8['facets']['form'] === $one('fa', 5)
        && $other8['facets']['reason'] === $one('required-blank', 5)
        && $other8['facets']['group'] === [['value' => 'site_a', 'label' => 'site_a', 'count' => 1],
                                           ['value' => 'site_b', 'label' => 'site_b', 'count' => 4]]);
    $split8 = [];
    foreach ($s8->aggregates($g8) as $a) {
        if ($a['kind'] === RollupBuilder::K_FORM) $split8[$a['axis2']] = (int) $a['cnt'];
    }
    $w8 = $wvRun($m8, $s8, 210, null);
    $whole8 = [];
    foreach ($s8->aggregates($w8) as $a) {
        if ($a['kind'] === RollupBuilder::K_FORM) $whole8[$a['axis2']] = (int) $a['cnt'];
    }
    check('rv11a1-4: a group run\'s summary is kept per group, and a whole-project run\'s is not',
        $split8 === ['site_a' => 1, 'site_b' => 4] && $whole8 === ['' => 10]);

    /* rv11a1-2  A GROUP'S REPORT IS SHOWN WHILE IT STILL DESCRIBES THE
     * GROUP (H10 part 2). The reviewer's repro: a raw group-31 run, then
     * R07 moves to group 7. The site_b reader was still shown R07's row and
     * value on the page and in the file; nothing called
     * projectionStillValid(). */
    list($s9, $m9) = $wv($all10);
    $m9->proj['scan-value-storage'] = 'raw';
    foreach ($m9->records as $id) $m9->valueOf[$id] = 'value-of-' . $id;
    $g9 = $wvRun($m9, $s9, 211, 31);
    $drifted = ['ok' => false, 'why' => ScanAuthorization::projectionStillValid(0, 1)['why'], 'final' => true];
    $pg9 = $wvPage($m9, $s9, 211, $g9, 31, ['limit' => 100]);
    check('rv11a1-2 (control): a raw group-31 run, fenced at 100, is its reader\'s five rows while nothing moved',
        $s9->run(211, $g9)['terminal'] === ScanOutcome::COMPLETE && $s9->run(211, $g9)['fence_target'] === '100'
        && $s9->run(211, $g9)['values_state'] === 'raw' && $pg9['ok'] === true && count($pg9['rows']) === 5);
    $open9 = (new ScanService($m9, $s9))->exportOpen(211, $g9);
    $m9->dagOf['R07'] = '7';
    $m9->dagNames['R07'] = 'site_a';
    $m9->changes['R07'] = '150';
    $m9->version['R07'] = '150';
    $m9->top = '160';
    check('rv11a1-2: once R07 has moved to group 7, the site_b reader\'s page is refused, final',
        $wvPage($m9, $s9, 211, $g9, 31, ['limit' => 100]) === $drifted);
    $x9 = $wvCsv($m9, $s9, 211, $g9, 31);
    check('rv11a1-2: and so is their file, before a line of it is written',
        $x9 === ['ok' => false, 'http' => 403, 'why' => $drifted['why']]);
    $mid9 = $lostOf($open9);
    check('rv11a1-2: a file opened before the move ends as one that lost rows, not a whole one',
        !empty($open9['ok']) && $mid9['res']['ok'] === false && $mid9['lost']);
    $un9 = $wvPage($m9, $s9, 211, $g9, null, ['limit' => 100]);
    check('rv11a1-2 (control): an unconfined reader, who may open every group\'s records, still reads the run',
        $un9['ok'] === true && count($un9['rows']) === 5);
    $m9->dagOf['R07'] = '31';
    $m9->dagNames['R07'] = 'site_b';
    $m9->changes['R07'] = '170';
    $m9->top = '170';
    check('rv11a1-2: R07 moved back, the group holds what the run held, and the report is shown again',
        $wvPage($m9, $s9, 211, $g9, 31, ['limit' => 100])['ok'] === true);
    $m9->records[] = 'R11';
    $m9->dagOf['R11'] = '31';
    $m9->dagNames['R11'] = 'site_b';
    $m9->changes['R11'] = '180';
    $m9->firstSeen['R11'] = '180';
    $m9->top = '180';
    check('rv11a1-2: a record created in the group after the fence is a change after it, not a move',
        $wvPage($m9, $s9, 211, $g9, 31, ['limit' => 100])['ok'] === true);
    $m9->firstSeen['R11'] = '90';
    check('rv11a1-2: but a record logged before the fence and in the group only since moved in',
        $wvPage($m9, $s9, 211, $g9, 31, ['limit' => 100])
            === ['ok' => false, 'why' => ScanAuthorization::projectionStillValid(1, 0)['why'], 'final' => true]);
    $m9->firstSeen['R11'] = '180';
    $keptIndex9 = $m9->index;
    $m9->index = new class extends \FakeSourceDb {
        public function select($sql, array $params = [])
        {
            if (strpos($sql, 'redcap_record_list') !== false && strpos($sql, ' IN (') !== false) {
                throw new \RuntimeException('the record list could not be read');
            }
            return parent::select($sql, $params);
        }
    };
    check('rv11a1-2: a changed record whose group cannot be read just now refuses the report, not final',
        $wvPage($m9, $s9, 211, $g9, 31, ['limit' => 100])
            === ['ok' => false, 'why' => ScopeDrift::CANNOT_TELL, 'final' => false]);
    $m9->index = $keptIndex9;
    $m9->bottom = '120';
    check('rv11a1-2: a change log that no longer reaches back to the fence cannot confirm the group, final',
        $wvPage($m9, $s9, 211, $g9, 31, ['limit' => 100])
            === ['ok' => false, 'why' => ScopeDrift::UNCONFIRMED, 'final' => true]);
    $m9->bottom = '1';
    // THE WALK IS BOUNDED. Three entries since the fence, read two a page:
    // two pages walk them, and one page cannot.
    $m9->changes['R08'] = '175';
    $fenceOf = new \ReflectionMethod(ScanService::class, 'fence');
    $fenceOf->setAccessible(true);
    $log9 = $fenceOf->invoke(new ScanService($m9, $s9), 211);
    $yes = function (array $ids) { return array_fill_keys($ids, true); };
    check('rv11a1-2: a walk that reaches the top of the log in its pages confirms the group',
        ScopeDrift::check($log9, $s9, $s9->run(211, $g9), $yes, $yes, 2, 2)['ok'] === true);
    check('rv11a1-2: and one with more entries since the fence than it may read refuses, final',
        ScopeDrift::check($log9, $s9, $s9->run(211, $g9), $yes, $yes, 2, 1)
            === ['ok' => false, 'movedIn' => 0, 'movedOut' => 0, 'why' => ScopeDrift::UNCONFIRMED, 'final' => true]);

    \REDCap::$groupNames = $keptNames;
}

/* =====================================================================
 * M8  A PROJECT REDESIGNED UNDER A RUNNING SCAN ENDS IT
 *
 * Through ScanService: start() fingerprints the structure and choice sets
 * durableScanContext() reports (tests/scan_entitlement_php.php proves where
 * they come from), and every work request builds them again. A design that
 * moved between two requests ends the run failed, with the sentence; one
 * that did not lets the run finish.
 * ===================================================================== */
{
    $design = ['fields' => ['a_val' => ['form' => 'fa', 'type' => 'text', 'validation' => '']],
               'longitudinal' => false, 'events' => ['1' => ['fa']], 'repeating' => []];
    $codes = ['fields' => [], 'missing' => ['UNK']];
    $drive = function ($pid, $change) use ($design, $codes) {
        $store = new ArrayScanStore();
        $m = new \E2eModule($store);
        $m->structure = $design;
        $m->choices = $codes;
        $runId = (int) (new ScanService($m, $store))->start($pid)['run_id'];
        $change($m);
        $first = null;
        for ($i = 0; $i < 10 && $store->run($pid, $runId)['terminal'] === null; $i++) {
            $w = (new ScanService($m, $store))->work($pid, $runId);
            if ($first === null) $first = $w;
        }
        return [$store->run($pid, $runId), $first];
    };
    list($same, ) = $drive(170, function ($m) {});
    check('M8: an unchanged project lets the run finish',
        $same['terminal'] === ScanOutcome::COMPLETE && (int) $same['manifest_done'] === 3);
    list($row, $w) = $drive(171, function ($m) {
        $m->structure['fields']['a_val']['validation'] = 'integer';
    });
    check('M8: a read field given a validation between two requests ends the run failed, with the sentence',
        empty($w['ok']) && $w['why'] === ScanService::CONFIG_CHANGED && $w['phase'] === ScanPhase::TERMINAL
        && $row['terminal'] === ScanOutcome::FAILED && (int) $row['manifest_done'] === 0);
    check('M8: and the stored reason says the configuration changed, not that the run broke',
        strpos((string) $row['terminal_reason'], 'validation rules or design changed') !== false
        && strpos((string) $row['terminal_reason'], 'unrecoverably') === false);
    list($row, $w) = $drive(172, function ($m) {
        $m->choices['missing'][] = 'NA';
    });
    check('M8: so does a Missing Data Code added between them',
        $w['why'] === ScanService::CONFIG_CHANGED && $row['terminal'] === ScanOutcome::FAILED);
    list($row, $w) = $drive(173, function ($m) {
        $m->structure['events']['2'] = ['fa'];
    });
    check('M8: and an instrument designated to a new event',
        $w['why'] === ScanService::CONFIG_CHANGED && $row['terminal'] === ScanOutcome::FAILED);
}

/* =====================================================================
 * B7  ONE FINGERPRINT BAG, AND THE GUARD IT ARMS
 *
 * start() and every work request each built the fingerprint's inputs as a
 * literal of their own. Two literals agree until one is edited, and then an
 * unchanged project is ended on its first work pass (Risk 6). Both now take
 * ScanService::fingerprintInputs(), and the worker's own guard - which
 * workerDeps() never armed - is handed the fingerprint the service compared.
 * The design and the choice sets are not empty here, so a bag that dropped
 * either would disagree with the stored one.
 * ===================================================================== */
{
    $b7design = ['fields' => ['a_val' => ['form' => 'fa', 'type' => 'text', 'validation' => '']],
                 'longitudinal' => false, 'events' => ['1' => ['fa']], 'repeating' => []];
    $b7codes = ['fields' => ['a_val' => []], 'missing' => ['UNK']];
    $b7 = function ($pid) use ($b7design, $b7codes) {
        $inner = new ArrayScanStore();
        $m = new \E2eModule($inner);
        $m->structure = $b7design;
        $m->choices = $b7codes;
        $store = new \FlipFingerprintStore($inner);
        $st = (new ScanService($m, $store))->start($pid);
        return [$inner, $store, $m, (int) $st['run_id'], $st];
    };

    list($in, $fs, $m, $rid, $st) = $b7(190);
    $first = (new ScanService($m, $fs))->work(190, $rid);
    $row = $in->run(190, $rid);
    check('B7: an unchanged project never trips the guard on its first work pass',
        !empty($st['ok']) && !empty($first['ok']) && $first['why'] !== ScanService::CONFIG_CHANGED
        && (int) $first['worked'] === 3 && $row['terminal'] !== ScanOutcome::FAILED);
    $inputs = new \ReflectionMethod(ScanService::class, 'fingerprintInputs');
    $inputs->setAccessible(true);
    $ctxNow = $m->durableScanContext(190, ['generation' => (int) $row['generation_id']]);
    check('B7: the stored fingerprint is the one fingerprintInputs() makes from the work-time context',
        ScanPlanner::fingerprintMatches($row['fingerprint'], ScanPlanner::requestFingerprint(
            $inputs->invoke(new ScanService($m, $fs), $ctxNow, ScanPolicy::resolve([], [])))));

    // The worker the service builds holds the run to the same fingerprint.
    list($in, $fs, $m, $rid) = $b7(191);
    $fs->arm(2);
    $w = (new ScanService($m, $fs))->work(191, $rid);
    $row = $in->run(191, $rid);
    check('B7: the worker\'s own guard is armed with the fingerprint the service compared',
        empty($w['ok']) && $row['terminal'] === ScanOutcome::FAILED && (int) $row['manifest_done'] === 0);
    check('B7: and it ends the run through promotion, with the sentence the service\'s check stores',
        strpos((string) $row['terminal_reason'], 'validation rules or design changed') !== false);

    // A real change between the start and the first work pass trips it.
    list($in, $fs, $m, $rid) = $b7(192);
    $m->rules[0]['when'] = "[a_val] <> 'x'";
    $w = (new ScanService($m, $fs))->work(192, $rid);
    $row = $in->run(192, $rid);
    check('B7: a rule edited before the first work pass ends the run failed, with the sentence',
        empty($w['ok']) && $w['why'] === ScanService::CONFIG_CHANGED
        && $row['terminal'] === ScanOutcome::FAILED && (int) $row['manifest_done'] === 0);
    list($in, $fs, $m, $rid) = $b7(193);
    $m->rules[] = ['type' => 'required', 'fields' => ['a_val'], 'when' => "[a_val] = 'y'"];
    $w = (new ScanService($m, $fs))->work(193, $rid);
    check('B7: so does a rule added',
        $w['why'] === ScanService::CONFIG_CHANGED && $in->run(193, $rid)['terminal'] === ScanOutcome::FAILED);
    // rv10b1-1: a field added to a SETTINGS rule. Its name leaves the fields
    // out, so only the fingerprint's rule entry can notice the edit.
    $inner = new ArrayScanStore();
    $m = new \E2eModule($inner);
    $m->structure = $b7design;
    $m->choices = $b7codes;
    $m->rules = [['type' => 'required', 'fields' => ['a_val'], '_origin' => 'settings']];
    $rid = (int) (new ScanService($m, $inner))->start(196)['run_id'];
    $m->rules[0]['fields'][] = 'b_val';
    $w = (new ScanService($m, $inner))->work(196, $rid);
    check('rv10b1-1: a field added to a settings rule between two requests ends the run failed',
        $w['why'] === ScanService::CONFIG_CHANGED && $inner->run(196, $rid)['terminal'] === ScanOutcome::FAILED
        && (int) $inner->run(196, $rid)['manifest_done'] === 0);

    // rv10b1-6: AN UPGRADE IS SAID TO BE ONE. A run begun under an older
    // evaluator, on a project nobody touched, is ended - and its reason names
    // the upgrade, not the project's rules or design.
    $upgraded = function ($pid, $oldEngine) use ($b7design, $b7codes) {
        $inner = new ArrayScanStore();
        $m = new \E2eModule($inner);
        $m->structure = $b7design;
        $m->choices = $b7codes;
        $rid = (int) (new ScanService($m, $inner))->start($pid)['run_id'];
        $began = $inner->run($pid, $rid);
        $bag = new \ReflectionMethod(ScanService::class, 'fingerprintInputs');
        $bag->setAccessible(true);
        $in = $bag->invoke(new ScanService($m, $inner), $m->durableScanContext($pid, ['generation' => null]),
                           ScanPolicy::resolve([], []));
        $policy = json_decode($began['policy_json'], true);
        if ($oldEngine === null) unset($policy['engine']); else $policy['engine'] = $oldEngine;
        $p = new \ReflectionProperty(ArrayScanStore::class, 'runs');
        $p->setAccessible(true);
        $runs = $p->getValue($inner);
        $runs[$rid]['fingerprint'] = ScanPlanner::requestFingerprint(
            array_merge($in, ['engine' => $oldEngine === null ? 'engine-4' : $oldEngine]));
        $runs[$rid]['policy_json'] = json_encode($policy);
        $p->setValue($inner, $runs);
        $w = (new ScanService($m, $inner))->work($pid, $rid);
        return [$began, $inner->run($pid, $rid), $w];
    };
    list($began, $row, $w) = $upgraded(197, 'engine-4');
    check('rv10b1-6: a run keeps the evaluator revision it began under',
        ScanPromotion::engineOf($began) === ScanService::ENGINE_REVISION);
    check('rv10b1-6: a run begun under an older evaluator is ended failed, as before',
        $row['terminal'] === ScanOutcome::FAILED && (int) $row['manifest_done'] === 0);
    check('rv10b1-6: and its stored reason says the module was upgraded, not that the project changed',
        $row['terminal_reason'] === ScanPromotion::ENGINE_UPGRADED);
    check('rv10b1-6: and so does the answer to the request that ended it',
        empty($w['ok']) && $w['why'] === ScanService::ENGINE_CHANGED);
    list(, $row, $w) = $upgraded(198, null);
    check('rv10b1-6: a run whose policy names no revision was begun by an older build, and says so too',
        $row['terminal_reason'] === ScanPromotion::ENGINE_UPGRADED && $w['why'] === ScanService::ENGINE_CHANGED);
    check('rv10b1-6 (control): a run the project changed under, same evaluator, still says the project changed',
        strpos((string) $inner->run(196, $rid)['terminal_reason'], 'validation rules or design changed') !== false);
    // A setting that decides what is stored, loosened: not a privacy stop (it
    // shows more, it does not tighten), and still a different configuration.
    list($in, $fs, $m, $rid) = $b7(194);
    $m->proj['scan-value-storage'] = 'raw';
    $w = (new ScanService($m, $fs))->work(194, $rid);
    check('B7: and a value-storage setting changed between them',
        $w['why'] === ScanService::CONFIG_CHANGED && $in->run(194, $rid)['terminal'] === ScanOutcome::FAILED);
    // The same setting TIGHTENED moves the fingerprint and the policy revision
    // together. That is a stop (review R5-5), and the armed worker guard must
    // not turn it into a failure.
    $inner = new ArrayScanStore();
    $m = new \E2eModule($inner);
    $m->structure = $b7design;
    $m->choices = $b7codes;
    $m->proj['scan-value-storage'] = 'raw';
    $rid = (int) (new ScanService($m, $inner))->start(195)['run_id'];
    $m->proj['scan-value-storage'] = 'locations';
    $w = (new ScanService($m, $inner))->work(195, $rid);
    check('B7: a value-storage setting tightened between them stops the run rather than failing it',
        $inner->run(195, $rid)['terminal'] === ScanOutcome::CANCELLED
        && strpos((string) $w['why'], 'privacy settings') !== false);
}

/* =====================================================================
 * H9 + W7-N8  PAST THE DETAIL BUDGET A RUN KEEPS COUNTING AND STOPS STORING
 *
 * The setting says so; nothing did it. Every finding was stored, and
 * promotion called the run truncated afterwards from the counters - which
 * also called a run that stored exactly its budget, and dropped nothing,
 * truncated. Three records, each with one finding, end to end through the
 * real worker, summary and promotion.
 * ===================================================================== */
{
    $budgeted = function ($pid, $max) {
        $store = new ArrayScanStore();
        $m = new \E2eModule($store);
        $m->flagged = ['R001' => true, 'R002' => true, 'R003' => true];
        $m->proj['scan-max-detail-findings'] = (string) $max;
        $runId = (int) (new ScanService($m, $store))->start($pid)['run_id'];
        $last = null;
        $found = 0;
        for ($i = 0; $i < 10 && $store->run($pid, $runId)['terminal'] === null; $i++) {
            $last = (new ScanService($m, $store))->work($pid, $runId);
            $found += isset($last['findings']) ? (int) $last['findings'] : 0;
        }
        $active = 0;
        foreach ($store->allFindings() as $f) if ((int) $f['active_slot'] === 1) $active++;
        return [$store->run($pid, $runId), $last, $found, $active];
    };
    list($row, $last, $found, $active) = $budgeted(196, 2);
    check('H9: past its detail budget a run stores no more findings than the budget',
        (int) $row['detail_rows'] === 2 && $active === 2);
    check('H9: and keeps counting: the work answers and the finished run both say three',
        $found === 3 && isset($last['status']['findings']) && $last['status']['findings'] === 3);
    check('H9: every record was examined, and the run is truncated, so not clean',
        $row['terminal'] === ScanOutcome::PARTIAL && $row['coverage'] === ScanOutcome::FENCED
        && $row['detail'] === ScanOutcome::DETAIL_TRUNCATED && (int) $row['clean'] === 0
        && (int) $row['manifest_done'] === 3);
    list($row, $last, $found, $active) = $budgeted(197, 3);
    check('W7-N8: a run that stored exactly its budget and left nothing out is not truncated',
        $row['terminal'] === ScanOutcome::COMPLETE && $row['detail'] === ScanOutcome::DETAIL_COMPLETE
        && (int) $row['detail_rows'] === 3 && $active === 3 && $last['status']['findings'] === 3);
}

/* =====================================================================
 * W7-R4  THE FINALIZER IS GIVEN EACH RULE'S ORDINAL AND EACH RECORD'S GROUP
 *
 * The service builds the duplicate finalizer, so it is the one that has the
 * plan (the ordinals) and the scan's read (the groups). The groups a verify
 * read saw are kept for the emit after it, so that page reads nothing more.
 * ===================================================================== */
{
    $plan = ['ruleIds' => [0 => ['source_id' => 'src-a', 'revision' => 'r'],
                           1 => ['source_id' => 'src-b', 'revision' => 'r'],
                           2 => ['source_id' => 'src-a', 'revision' => 'r']]];
    check('W7-R4: a rule\'s ordinal is its place in the plan plus one, the lower where two share a source',
        ScanService::ruleOrdinals($plan) === ['src-a' => 1, 'src-b' => 2]
        && ScanService::ruleOrdinals([]) === []);

    // The group of a record node: event rows and repeat instances, in order.
    check('W7-R4: a record node\'s group is the first non-empty one on its rows',
        ScanService::dagOfNode(['1' => ['redcap_data_access_group' => ''], '2' => ['redcap_data_access_group' => 'site_b']]) === 'site_b'
        && ScanService::dagOfNode(['1' => ['a' => 'x'], 'repeat_instances' => ['1' => ['f' => ['2' => ['redcap_data_access_group' => 'site_c']]]]]) === 'site_c'
        && ScanService::dagOfNode(['1' => ['a' => 'x']]) === null);

    $reads = [];
    $read = function (array $ids) use (&$reads) {
        $reads[] = array_map('strval', $ids);
        $d = [];
        foreach ($ids as $id) {
            if ((string) $id === 'GONE') continue;
            $d[(string) $id] = ['1' => ['sid' => 'ID-7', 'redcap_data_access_group' => (string) $id === 'R1' ? 'site_a' : '']];
        }
        return ['ok' => true, 'data' => $d, 'why' => null];
    };
    $svcR = new ScanService(new \SourceModule());
    $finOf = new \ReflectionMethod(ScanService::class, 'finalizer');
    $finOf->setAccessible(true);
    $storeR = new ArrayScanStore();
    $finR = $finOf->invoke($svcR, 149, ['read' => $read, 'plan' => $plan], $storeR);
    $depsP = new \ReflectionProperty(UniqueFinalizer::class, 'deps');
    $depsP->setAccessible(true);
    $fd = $depsP->getValue($finR);
    check('rv10a1-1: the service gives its finalizer the worker\'s store to hold the run through',
        isset($fd['store']) && $fd['store'] === $storeR);
    check('W7-R4: the service gives its finalizer the plan\'s ordinals and a groups reader',
        isset($fd['ordinals']) && $fd['ordinals'] === ['src-a' => 1, 'src-b' => 2]
        && isset($fd['dags']) && is_callable($fd['dags']));
    // Verify reads R1 and R2; the emit after it reads nothing for them.
    $fd['read']([['record' => 'R1', 'event_id' => 1, 'instance' => 1, 'field' => 'sid', 'form' => null],
                 ['record' => 'R2', 'event_id' => 1, 'instance' => 1, 'field' => 'sid', 'form' => null]]);
    $g1 = $fd['dags'](['R1', 'R2']);
    check('W7-R4: the groups a verify read saw serve the emit after it, with no second read',
        $g1['ok'] === true && $g1['dags'] === ['R1' => 'site_a', 'R2' => null] && $g1['read'] === 0
        && count($reads) === 1);
    // A record not seen yet is read once; one the read does not return has no group.
    $g2 = $fd['dags'](['R1', 'R3', 'GONE']);
    check('W7-R4: the rest are read in one call, and a record the read does not return has no group',
        $g2['ok'] === true && $g2['dags'] === ['R1' => 'site_a', 'R3' => null, 'GONE' => null]
        && $g2['read'] === 2 && count($reads) === 2 && $reads[1] === ['R3', 'GONE']);
    // A read that fails is not "no group".
    $failing = ScanService::dagsFor(['R9'], function (array $ids) {
        return ['ok' => false, 'data' => [], 'why' => 'the records could not be read'];
    }, new \ArrayObject());
    check('W7-R4: a groups read that fails is ok:false, never a list of records with no group',
        $failing['ok'] === false && $failing['dags'] === []);
}
}

namespace {
    echo "scan_service_php: $n checks, $fail failure(s)\n";
    exit($fail ? 1 : 0);
}
