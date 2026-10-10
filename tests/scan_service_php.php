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
        /** @var ?string what the run's lease reads as, or null for no lease */
        public $lease = null;
        public $logs = [];
        private $count = 0;
        private $slotEpoch = 0;
        private $index;

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
                'ownership' => ['a_val' => 'fa'], 'structure' => [],
                'problems' => $this->problems,
                'verifyFingerprint' => $this->verifyFingerprint,
                'plan' => ['hostFields' => ['a_val' => ['fa' => true]], 'crossRefused' => [],
                           'crossRecordLookups' => false],
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
                if (strpos($s, 'SELECT MAX(log_event_id)') === 0) return [['100']];
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
    // CL-3. PLANNING is one synchronous request inside start(), timed from
    // created_at because progress_at stays NULL until the manifest freezes.
    $plan = ScanService::progressOf(['phase' => ScanPhase::PLANNING, 'terminal' => null,
        'created_at' => $ago(150), 'updated_at' => $ago(1), 'progress_at' => null,
        'lease_expires_at' => $held(299)], [ScanStore::REC_PENDING => 400000], $NOW);
    check('CL-3: a run still listing its records for two and a half minutes is not stalled',
        $plan['stalled'] === false && $plan['paused'] === false && $plan['stopping'] === false);

    // R2O-3. BUT ONLY FOR A WINDOW. When the request that was planning dies,
    // the run sits in `planning` holding the project's slot until the stale
    // reaper, Continue cannot move it, and CL-3's silence had no end.
    $planRow = function ($createdAgo, array $over = []) use ($ago) {
        return array_merge(['phase' => ScanPhase::PLANNING, 'terminal' => null,
            'created_at' => $ago($createdAgo), 'updated_at' => $ago($createdAgo),
            'progress_at' => null, 'lease_expires_at' => null], $over);
    };
    $dead = ScanService::progressOf($planRow(3 * 3600), [], $NOW);
    check('R2O-3: a run planning for three hours has stopped planning - and is not stalled or paused',
        $dead['planningStopped'] === true && $dead['stalled'] === false
        && $dead['paused'] === false && $dead['stopping'] === false
        && $dead['idleSeconds'] === 10800);
    check('R2O-3: one planning for two and a half minutes is still told nothing',
        $plan['planningStopped'] === false);
    $win = ScanPolicy::planningSeconds(null, null);
    check('R2O-3: the default window is the policy default of fifteen minutes',
        $win === ScanPolicy::D_PLANNING_MINUTES * 60 && $win === 900);
    check('R2O-3: a second inside the window is planning; at the window it has stopped',
        ScanService::progressOf($planRow($win - 1), [], $NOW)['planningStopped'] === false
        && ScanService::progressOf($planRow($win), [], $NOW)['planningStopped'] === true);
    check('R2O-3: the window is the caller\'s, as the stall threshold is',
        ScanService::progressOf($planRow(600), [], $NOW, ScanService::STALL_SECONDS, 300)['planningStopped'] === true
        && ScanService::progressOf($planRow(600), [], $NOW, ScanService::STALL_SECONDS, 3600)['planningStopped'] === false);
    check('R2O-3: only planning: a scanning run as old is paused, a finished one is neither',
        ScanService::progressOf($planRow(10800, ['phase' => ScanPhase::SCANNING]), [], $NOW)['planningStopped'] === false
        && ScanService::progressOf($planRow(10800, ['phase' => ScanPhase::TERMINAL, 'terminal' => 'complete']),
            [], $NOW)['planningStopped'] === false);
    check('R2O-3: a planning row with no readable clock is not given the alarming reading',
        ScanService::progressOf(['phase' => ScanPhase::PLANNING, 'terminal' => null,
            'created_at' => 'not a date'], [], $NOW)['planningStopped'] === false);
    // The window: minutes from the system setting, never shorter than the
    // request time limit, and malformed is the default.
    check('R2O-3 policy: the setting gives the minutes',
        ScanPolicy::planningSeconds('30', '30') === 1800 && ScanPolicy::planningSeconds(20, false) === 1200);
    check('R2O-3 policy: zero, negative or junk minutes are the default',
        ScanPolicy::planningSeconds('0', null) === 900 && ScanPolicy::planningSeconds(-5, null) === 900
        && ScanPolicy::planningSeconds('soon', '') === 900);
    check('R2O-3 policy: never shorter than the request time limit, which a planner may still be inside',
        ScanPolicy::planningSeconds('5', '3600') === 3600 && ScanPolicy::planningSeconds(null, 7200) === 7200);
    check('R2O-3 policy: no limit at all leaves the minutes alone',
        ScanPolicy::planningSeconds('20', '0') === 1200);
    // R3U-4: minutes past PHP_INT_MAX / 60 made the window a float, which the
    // (int) in progressOf() turned into 0 or PHP_INT_MIN, and max(1, ...) into
    // one second: every planning run read "stopped" five seconds in.
    foreach (['9999999999999999999', '153722867280912931', '999999999999999', PHP_INT_MAX] as $huge) {
        $w = ScanPolicy::planningSeconds($huge, '0');
        check('R3U-4: a huge planning setting (' . $huge . ') is held at a year, as an int',
            $w === ScanPolicy::MAX_PLANNING_MINUTES * 60 && is_int($w));
        check('R3U-4: and under it a run five seconds into planning is still planning (' . $huge . ')',
            ScanService::progressOf($planRow(5), [], $NOW, ScanService::STALL_SECONDS, $w)['planningStopped'] === false);
    }
    check('R3U-4: the cap is a year, and a setting under it is untouched',
        ScanPolicy::MAX_PLANNING_MINUTES === 525600
        && ScanPolicy::planningSeconds('525599', '0') === 525599 * 60);
    check('R2O-3 policy: a sentence threshold, so it is not frozen into the run\'s policy',
        !array_key_exists('planningSeconds', ScanPolicy::resolve([], []))
        && !array_key_exists('planningMinutes', ScanPolicy::resolve([], [])));
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
 * R2O-3  A RUN WHOSE PLANNING DIED IS TOLD SO, THROUGH status() ITSELF
 *
 * progressOf() decides; status() is what the page is sent. Asked through the
 * real verb, past its entitlement, over a run row whose clock is hours old -
 * so dropping the flag from the answer, or not passing the window, is caught
 * here and not only in the helper.
 * ===================================================================== */
{
    $V = 'INSPIRE\\UniversalValidator\\ScanPageView';
    $pm = new \VerbModule();
    $pm->runRow = \VerbModule::row(['phase' => ScanPhase::PLANNING, 'manifest_total' => 0,
        'created_at' => gmdate('Y-m-d H:i:s', time() - 3 * 3600),
        'updated_at' => gmdate('Y-m-d H:i:s', time() - 3 * 3600)]);
    $pst = (new ScanService($pm))->status(149, 77);
    check('R2O-3 status: a run planning for three hours reaches the page as planning stopped',
        $pst['ok'] === true && $pst['active'] === true && $pst['planningStopped'] === true
        && $pst['stalled'] === false && $pst['paused'] === false && $pst['stopping'] === false);
    $said = $V::stalledText($pst);
    check('R2O-3 status: and the panel says planning stopped, how long ago, and to press Stop',
        is_string($said) && strpos($said, 'This scan stopped while it was listing the records') === 0
        && strpos($said, 'for 180 minutes') !== false && strpos($said, 'Press Stop') !== false
        && strpos($said, 'Press Continue') === false);
    // A planner still inside the window is left alone, as CL-3 intended.
    $pm->runRow['created_at'] = gmdate('Y-m-d H:i:s', time() - 120);
    $fresh = (new ScanService($pm))->status(149, 77);
    check('R2O-3 status: one planning for two minutes is told nothing',
        $fresh['planningStopped'] === false && $V::stalledText($fresh) === null);
    // THE WINDOW IS THE SETTING'S, read by the verb.
    $pm->runRow['created_at'] = gmdate('Y-m-d H:i:s', time() - 40 * 60);
    check('R2O-3 status: forty minutes is past the default window',
        (new ScanService($pm))->status(149, 77)['planningStopped'] === true);
    $pm->sys[ScanService::PLANNING_SETTING] = '60';
    check('R2O-3 status: and inside a window the administrator set to an hour',
        (new ScanService($pm))->status(149, 77)['planningStopped'] === false);
    $win = new \ReflectionMethod(ScanService::class, 'planningSeconds');
    $win->setAccessible(true);
    $limit = (int) ini_get('max_execution_time');
    check('R2O-3 status: the verb reads the setting and the time limit, the larger winning',
        $win->invoke(new ScanService($pm)) === max(3600, $limit));
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
        $runId = (int) $store->startRun($pid, ['created_by' => 'alice'])['run']['run_id'];
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
    check('R2O-5: and a planner that wakes up cannot add to it',
        $store->appendManifest($runId, [['id_bin' => 'Z', 'hash' => $h('Z'), 'dag' => null]]) === 0
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
        $runId = (int) $store->startRun($pid, ['created_by' => 'alice'])['run']['run_id'];
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
    check('E2E stop: the run is stored cancelled, partial, with no verdict',
        is_array($row) && $row['terminal'] === ScanOutcome::CANCELLED && $row['coverage'] === ScanOutcome::COV_PARTIAL
        && $row['clean'] === null);
    check('E2E stop: and status() says it was cancelled, and that it is over',
        !empty($x['status']['ok']) && $x['status']['terminal'] === ScanOutcome::CANCELLED
        && $x['status']['active'] === false);

    // -- Stop while a worker holds the run --------------------------------------
    $hs = new ArrayScanStore();
    $hm = new \E2eModule($hs);
    $hm->lease = gmdate('Y-m-d H:i:s', time() + 300);
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
}
}

namespace {
    echo "scan_service_php: $n checks, $fail failure(s)\n";
    exit($fail ? 1 : 0);
}
