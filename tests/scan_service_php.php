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
            if (strpos($sql, ' IN (') !== false) {
                $asked = array_map('strval', array_slice($params, 1));
                $out = [];
                foreach ($this->rows as $r) if (in_array($r[0], $asked, true)) $out[] = [$r[0]];
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
        /** @var array the project's design as durableScanContext() reports it (M8) */
        public $structure = [];
        /** @var array the choice sets it reports beside it */
        public $choices = [];
        /** @var array the lookups into other projects the plan says the rules make */
        public $crossLookups = [];
        /** @var array pid => true: the other project refuses the reader, by decision */
        public $refusedBy = [];
        /** @var ?string what the run's lease reads as, or null for no lease */
        public $lease = null;
        /** @var int how many reads of the change log's top fail, as a blip does (H11) */
        public $logFails = 0;
        /** @var string[] the configuration reads durableScanContext() says failed (R5-4) */
        public $unread = [];
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
        public function crossLookupRefusals($pid, array $lookups)
        {
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
            return ['ok' => true, 'why' => null,
                'rules' => [['type' => 'required', 'fields' => ['a_val']]],
                'ownership' => ['a_val' => 'fa'], 'structure' => $this->structure, 'choices' => $this->choices,
                'problems' => $this->problems, 'unread' => $this->unread,
                'verifyFingerprint' => $this->verifyFingerprint,
                'plan' => ['hostFields' => ['a_val' => ['fa' => true]], 'crossRefused' => [], 'crossUnread' => [],
                           'crossLookups' => $this->crossLookups, 'crossRecordLookups' => false],
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
                        'instance' => 1, 'dag_key' => null, 'host_form' => 'fa', 'field' => 'a_val',
                        'rule_source_id' => 'r1', 'rule_revision' => str_repeat('c', 64),
                        'rule_ord' => 1, 'check_type' => 'required',
                        'reason_code' => 'required-blank']],
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
                    // The fence's own read, not the capability probe's MAX+COUNT.
                    if ($this->logFails > 0 && strpos($s, 'SELECT MAX(log_event_id) FROM') === 0) {
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
                foreach ($this->store->findings($params[0], $params[1], [], $params[2], $limit) as $f) {
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

    // -- COMPAT-1: a server without mbstring or ctype -------------------------
    $noMb = new class extends \FakeModule { public function missingPhpExtensions() { return ['mbstring']; } };
    $noMb->sys[ScanService::SYS_FLAG] = '1';
    $noMb->proj[ScanService::PROJ_FLAG] = '1';
    $nm = (new ScanService($noMb))->available(1);
    check('COMPAT-1: a server without mbstring cannot scan, and is told which extension',
        $nm['ok'] === false && strpos($nm['why'], 'the mbstring extension the scan needs') !== false);
    $noBoth = new class extends \FakeModule { public function missingPhpExtensions() { return ['mbstring', 'ctype']; } };
    $noBoth->sys[ScanService::SYS_FLAG] = '1';
    $noBoth->proj[ScanService::PROJ_FLAG] = '1';
    check('COMPAT-1: ...and names both when both are missing',
        strpos((new ScanService($noBoth))->available(1)['why'], 'the mbstring and ctype extensions') !== false);
    $noMbOff = new class extends \FakeModule { public function missingPhpExtensions() { return ['mbstring']; } };
    check('COMPAT-1: an installation that has not turned the scan on is not told about its PHP',
        strpos((new ScanService($noMbOff))->available(1)['why'], 'not enabled here') !== false);

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
    // The person who starts it is one project 300 refuses: the lookup is the
    // rules', not the starter's, so it is recorded all the same.
    $km->refusedBy = [300 => true];
    $k = $drive($km, $ks, 152);
    check('E2E cross: no verb threw' . ($k['threw'] ? ' (' . $k['threw'] . ')' : ''), $k['threw'] === null);
    $kp = is_array($k['row']) ? json_decode($k['row']['policy_json'], true) : null;
    check('E2E cross: the run keeps every lookup its rules make, whoever started it',
        is_array($kp) && isset($kp['crossLookups']) && $kp['crossLookups'] === [$kl]);
    check('E2E cross: and they are not part of the fingerprint, so every pass matched it and the run finished',
        is_array($k['row']) && $k['row']['terminal'] === ScanOutcome::COMPLETE);
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
}

namespace {
    echo "scan_service_php: $n checks, $fail failure(s)\n";
    exit($fail ? 1 : 0);
}
