<?php
/**
 * tests/support/scan_facade.php - the legacy one-request scan, for the suites
 * (QUA-3).
 *
 * UniversalValidator::scanProject() ran every rule over every record of a
 * project in one request. pages/scan.php runs the durable scan
 * (Scan\ScanService) instead, and nothing in the module called scanProject()
 * any more, while 16 suites called it 201 times: 354 lines of shipped code
 * that only tests ran, holding every finding in memory (976 MB at 5,000
 * records with 500 tags, in the 2026-10-21 review). It lives here now, with
 * scanHalt(), its time and memory guard, and the two sinks only it and the
 * tests used.
 *
 * It is still a fair test of a verdict: each record goes through scanRecord()
 * and ruleFindings(), which the durable scan (durableEvaluateRecord) and the
 * save audit use too. It says nothing about the durable scan's batching,
 * leases or memory; tests/scan_service_php.php, tests/scan_worker_php.php and
 * the MySQL matrix (tests/mysql/run.php) cover those.
 *
 * Each suite's framework stub forwards $module->scanProject(...) to
 * scan_project() below, which runs the body as it was, in the module's scope
 * (Closure::bind), so it still reaches the module's private methods.
 */

namespace INSPIRE\UniversalValidator;

require_once __DIR__ . '/../../php/FindingSink.php';

/**
 * Collects violations in memory, reproducing exactly what scanProject returned
 * before the sink existed.
 *
 * This is the default, and it is what every existing test drives. Its continued
 * agreement with a streaming sink is the regression proof that the extraction
 * changed nothing — see the S-01 differential section in tests/hosting_php.php,
 * which runs every scenario through both and compares.
 */
final class ArrayFindingSink implements FindingSink
{
    /** @var array[] */
    public $violations = [];

    public function violation(array $v)
    {
        $this->violations[] = $v;
    }

    public function count()
    {
        return count($this->violations);
    }
}

/**
 * Counts violations and keeps none.
 *
 * The cheapest way to answer "how many would this project produce" without
 * paying for the answer — which is what sizing a report, or measuring a project
 * before deciding how to store its findings, actually needs.
 */
final class CountingFindingSink implements FindingSink
{
    /** @var int */
    private $n = 0;

    public function violation(array $v)
    {
        $this->n++;
    }

    public function count()
    {
        return $this->n;
    }
}

/**
 * Counts what it hands on to another sink. scan_project() reports how many
 * violations it found, which a sink that keeps no count (CallbackFindingSink)
 * cannot say: FindingSink asks for violation() alone.
 */
final class CountedFindingSink implements FindingSink
{
    /** @var FindingSink */
    private $inner;
    /** @var int */
    private $n = 0;

    public function __construct(FindingSink $inner)
    {
        $this->inner = $inner;
    }

    public function violation(array $v)
    {
        $this->n++;
        $this->inner->violation($v);
    }

    public function count()
    {
        return $this->n;
    }
}

/**
 * 'time' | 'memory' | null — why the chunk loop must stop now.
 *
 * Split out from the loop so the decision can be tested WITHOUT asking PHP
 * to enforce a real limit: setting max_execution_time inside a test kills
 * the test process (the timer is wall-clock on Windows and does not reset),
 * and setting memory_limit low enough to trip is one allocation away from a
 * fatal. A null bound means "no limit known", which never halts — declining
 * to guess, because a guard that fires on a misread would stop healthy scans
 * and report them as incomplete.
 */
function scan_halt($deadline, $memCap, $now, $usage)
{
    if ($deadline !== null && $now >= $deadline) return 'time';
    if ($memCap !== null && $usage >= $memCap) return 'memory';
    return null;
}

/**
 * Run every configured rule over EVERY saved record — the retrospective
 * sweep the per-save audit cannot give you: legacy data, Data Import Tool
 * and API writes (whose save-hook coverage is version-dependent), and
 * records entered before a rule existed.
 *
 * Reads records in CHUNKS (memory-safe on large projects) and evaluates
 * each record/event/instance context through ruleFindings() — the same
 * dispatch the save-hook audit uses, so the two can never disagree.
 * Unique rules are handled in ONE aggregate pass over the scanned data
 * (grouping by value + composite key + scope) instead of a whole-project
 * read per record.
 *
 * $dagFilter: a DAG GROUP ID — only records in that group are scanned (pass
 * ScanPageView::scanScope()['dag'], which produces it, so a DAG-bound user
 * never sees other groups' record ids). null scans everything. It USED to
 * be the unique name, and the module now has exactly one DAG axis: the id.
 * The name is derived from it internally, because this path's record groups
 * come from the export, which reports names. A group id that cannot be
 * resolved to a name refuses the scan rather than scanning unconfined.
 *
 * Returns ['violations' => [ ['record','event_id','instance','field',
 * 'type','reason','rule' => 1-based index], ... ], 'unconfigurable' =>
 * [ ['rule','fields','why'], ... ] (deduplicated), 'stats' => [...]].
 * Values are returned per the scan-value-storage project setting: shown,
 * redacted for fields REDCap marks as an Identifier, or withheld entirely.
 * Redaction fails CLOSED — an unreadable dictionary withholds everything,
 * because a dictionary that cannot be read cannot clear a field.
*/
function scan_project(UniversalValidator $m, $pid, $dagFilter = null, $chunkSize = 200, ?FindingSink $sink = null, array $opts = [])
{
    $run = function ($pid, $dagFilter, $chunkSize, $sink, $opts) {
        // 'status' is part of the contract: a scan that could not read everything
        // must never be presentable as a clean bill of health. 'complete' is
        // only ever set at the very end of the full path.
        $result = ['violations' => [], 'unconfigurable' => [], 'incomplete' => [],
                   'status' => 'failed',
                   'stats' => ['records' => 0, 'contexts' => 0, 'rules' => 0, 'violations' => 0]];

        // Violations are the only channel that grows with the DATA, so they are
        // the only thing handed out as they are found. Without a sink the
        // findings are collected here and returned, which is what every caller
        // before 1.7.0 expected and what every test still asserts against.
        $collect = ($sink === null);
        if ($collect) $sink = new ArrayFindingSink();

        // ONE AXIS FOR THE WHOLE MODULE, AND THIS IS THE CONVERSION.
        //
        // $dagFilter now arrives as the numeric group id, because that is what
        // ScanPageView::scanScope() produces and what every durable consumer
        // compares on. This path cannot compare ids: its record groups come from
        // \REDCap::getData(exportDataAccessGroups => true), whose
        // redcap_data_access_group field is the DAG's unique NAME. So the id is
        // resolved back to a name HERE, once, through the same resolver the page
        // uses - rather than leaving a second parameter on a second axis for
        // somebody to fill from the wrong producer. Verified: with the page
        // returning the id and this resolution absent, five checks in
        // tests/scan_page_php.php go red, all of them a scan that listed zero
        // records because it compared 'north' against '7'.
        //
        // AN UNRESOLVABLE GROUP REFUSES, exactly as scanScope() does. Scanning
        // on with a name we could not read means matching no record, and this
        // method's own H-10 note records what that produced: a green tick over
        // "Scanned 0 record(s)".
        $dagName = null;
        if ($dagFilter !== null && $dagFilter !== '') {
            $dagName = ScanPageView::dagNameOf($dagFilter);
            if ($dagName === null) {
                $result['incomplete'][] = 'the Data Access Group this scan was confined to could '
                    . 'not be resolved, so no record could be placed inside or outside it and '
                    . 'nothing was examined';
                return $result;                  // status stays 'failed'
            }
        }

        $plan = $this->scanPlan($pid, $opts, $dagFilter);
        if ($plan['fatal'] !== null) {
            $result['incomplete'][] = $plan['fatal'];
            return $result;                  // status stays 'failed'
        }
        $result['stats']['rules'] = count($plan['live']);
        // Attach the config-error notices NOW so EVERY subsequent return carries them
        // — the empty-idData and no-records early returns below would otherwise drop
        // them, reporting a clean project when a rule is actually inert (UV-1553-01,
        // the M-05 silent-failure the feature exists to prevent). The final assignment
        // on the full path re-attaches $unconf with any runtime additions.
        $unconf = $plan['unconf'];
        $result['unconfigurable'] = array_values($unconf);
        if ($plan['nothingToScan']) {
            $result['status'] = 'complete';
            $result['coverage'] = isset($plan['policy']['maxCompletion'])
                ? $plan['policy']['maxCompletion'] : 'manifest-complete';
            $result['limits'] = isset($plan['policy']['limits']) ? $plan['policy']['limits'] : [];
            return $result;
        }

        $live       = $plan['live'];
        $hostFields = $plan['hostFields'];
        $readSet    = $plan['readSet'];
        $dupes      = $plan['dupes'];

        // Record list first (ids only), then chunked full reads.
        $pk = $this->recordIdFieldOf($pid);
        if ($pk === '') {
            $result['incomplete'][] = 'the record identifier field could not be determined, so the '
                . 'record list could not be read without exporting the whole project';
            return $result;              // status stays 'failed'
        }
        // A throw here used to escape scanProject entirely, so the operator saw a
        // PHP error page rather than a scan result - and nothing recorded that
        // the project had not been examined.
        try {
            $idData = \REDCap::getData([
                'project_id' => $pid, 'return_format' => 'array',
                'fields' => [$pk],
                'exportDataAccessGroups' => true,
            ]);
        } catch (\Throwable $e) {
            $result['incomplete'][] = 'the record list could not be read: ' . get_class($e);
            return $result;
        }
        if (!is_array($idData)) {
            $result['incomplete'][] = 'the record list could not be read';
            return $result;
        }
        $ids = [];
        $ungrouped = 0;
        foreach ($idData as $rec => $node) {
            if ($dagFilter !== null) {
                // The SHAPE check is not part of the exclusion test. Written as
                // one conjunction - is_array($node) && dagOf($node) !== $filter -
                // a node REDCap did not return as an array failed the test and
                // was therefore ADMITTED: a record whose group could not be
                // established reached a DAG-scoped report, and its id was
                // printed under a header stating the file covers one group only.
                // A group that cannot be read is not this group.
                if (!is_array($node)) { $ungrouped++; continue; }
                // NAME AGAINST NAME. dagOfRecordNode() returns
                // redcap_data_access_group from the export, which is the unique
                // NAME; $dagName is the same name, resolved once above from the
                // group id the caller passed. Comparing $dagFilter here - the id
                // - excludes every record in the project and reports a clean,
                // complete scan of nothing.
                if (self::dagOfRecordNode($node) !== $dagName) continue;
            }
            $ids[] = $rec;
        }
        if ($ungrouped > 0) {
            // Counted, never listed, and never named. One string per record is
            // the unbounded accumulator this scan exists to avoid, and the id is
            // the exact thing that must not cross the group boundary - so the
            // note says how many and stops there.
            $result['incomplete'][] = $ungrouped . ' record(s) could not be read well enough to '
                . 'establish a Data Access Group, so they were left OUT of this group-scoped scan';
        }
        // The MANIFEST size. The headline count is set at the end, from what was
        // actually reached: a scan halted at the first chunk boundary used to
        // report the full manifest as "Scanned 400 record(s)" in bold while the
        // truth sat in a bullet inside a warning box. The whole point of the
        // 1.6.4 halt guard is that a stopped scan says so.
        $result['stats']['manifest'] = count($ids);
        $result['stats']['records'] = count($ids);
        unset($idData);                  // dead from here; it was held to the return
        if (!$ids) {
            // Zero records IN SCOPE is not a clean project, and the three ways
            // to reach it are indistinguishable from here: the group genuinely
            // has no records; exportDataAccessGroups was not honoured so no
            // record carried a group at all; or the DAG name and the exported
            // group label disagree. All three used to render the green tick over
            // "Scanned 0 record(s)". That is S-03 — the defect 1.6.2 exists to
            // fix — reached by a different route: 1.6.2 refused when the DAG
            // NAME could not be resolved, not when it resolved and matched
            // nothing.
            $result['incomplete'][] = $dagFilter === null
                ? 'the project contains no records, so there was nothing to examine'
                : 'no record was in scope for Data Access Group "' . $dagName . '", so nothing was '
                  . 'examined — this is not evidence that the group\'s data is clean';
            $result['status'] = 'incomplete';
            return $result;
        }

        $uniqueSeen = [];   // aggregate pass: groupKey => [ [record,event,instance,field,rule], ... ]
        // $unconf was declared above (it already holds any config-error rules)

        // The budget. This runs synchronously inside one page request, so the two
        // ways it actually dies are the execution limit and the memory limit —
        // and BOTH are uncatchable fatals. The process stops before the return
        // below, the page renders nothing, and NOTHING records that the project
        // was not examined: no status, no 'incomplete' entry, just a blank screen
        // that looks the same as a network failure. Stopping short and SAYING so
        // is the entire contract (M-03).
        //
        // Measured on a live 39-record project with 329 rules: ~20s warm. A
        // project an order of magnitude larger does not fit in a default
        // execution limit, so this is the expected exit on real data, not a
        // pathological one.
        $tStart   = microtime(true);
        $maxSec   = (int) ini_get('max_execution_time');          // 0 = no limit (CLI)
        $deadline = $maxSec > 0 ? $tStart + ($maxSec * 0.75) : null;
        $memLimit = self::memoryLimitBytes();
        $memCap   = $memLimit > 0 ? (int) ($memLimit * 0.70) : null;
        $reached  = 0;

        // Sliced, not array_chunk()'d. array_chunk builds a SECOND copy of every
        // id up front and holds it for the whole scan, so a 200,000-record
        // project paid for its record list twice before examining anything.
        // array_slice hands back one chunk at a time and the previous one is
        // released as the loop turns.
        $chunkSize = max(1, (int) $chunkSize);

        // A chunk read costs WIDTH x HEIGHT, and only the height was bounded.
        // Every rule field, every when/assert operand and every composite unique
        // partner goes into one getData() call, so a project with 1,500 ruled
        // fields built a 1,500-column export of 200 records at once - and the
        // 1.6.4 halt guard measures memory BETWEEN chunks, so it notices after
        // the allocation that caused the problem, not before.
        //
        // Narrower reads instead of a refusal: the same records are examined,
        // in more passes. CELL_BUDGET is a shape constant, not a tuning knob -
        // 200 records x 200 fields is what the previous behaviour cost on an
        // ordinary project, so nothing changes for one and a wide project stops
        // scaling its peak by rule count.
        $width = max(1, count($readSet));
        $cellBudget = 40000;
        if ($width * $chunkSize > $cellBudget) {
            $narrowed = max(1, (int) ($cellBudget / $width));
            $result['limits'][] = 'this project has ' . $width . ' fields under rules, so records are '
                . 'read ' . $narrowed . ' at a time instead of ' . $chunkSize . ' to keep one read '
                . 'inside memory';
            $chunkSize = $narrowed;
        }

        $total = count($ids);
        for ($offset = 0; $offset < $total; $offset += $chunkSize) {
            $chunk = array_slice($ids, $offset, $chunkSize);
            // Checked BETWEEN chunks and nowhere else. Stopping part-way through
            // a record would leave it half-checked with nothing written down,
            // which is the silent skip this guard exists to prevent (H-05).
            $why = scan_halt($deadline, $memCap, microtime(true), memory_get_usage(true));
            if ($why !== null) {
                $halt = ($why === 'time')
                    ? 'the scan stopped after ' . $reached . ' record(s) to stay inside the server '
                      . 'execution limit of ' . $maxSec . 's'
                    : 'the scan stopped after ' . $reached . ' record(s) to avoid exhausting the '
                      . 'server memory limit of ' . ini_get('memory_limit');
                $result['incomplete'][] = $halt . '; ' . (count($ids) - $reached)
                    . ' record(s) were not checked';
                // Duplicate detection is the one check that needs the WHOLE
                // project, so a short run under-reports it. That is a wrong
                // negative rather than a missing row, and the operator has to be
                // told which it is.
                $result['incomplete'][] = 'because the scan stopped early, duplicate values are '
                    . 'under-reported: a value is only seen as duplicated if both records were reached';
                break;
            }
            // A chunk that cannot be read is RECORDED, never skipped in silence:
            // skipping it produced a green "No violations found" for records that
            // were never examined, which is the worst possible failure for a tool
            // whose entire output is an assurance.
            // The record-id field rides along, as in durableScanContext(): without
            // it a record whose every read field is blank is omitted by getData
            // and reported as "requested but not returned" instead of examined.
            // So do the host forms' status fields, which say where each host was
            // started (R2O-2); see scanReadParams().
            try {
                $data = \REDCap::getData(self::scanReadParams($plan, $pid, $chunk, $pk));
            } catch (\Throwable $e) {
                $result['incomplete'][] = 'reading ' . count($chunk) . ' record(s) failed: '
                    . get_class($e);
                continue;
            }
            if (!is_array($data)) {
                $result['incomplete'][] = 'reading ' . count($chunk) . ' record(s) returned no usable data';
                continue;
            }
            foreach ($chunk as $rec) {
                if (!isset($data[$rec]) || !is_array($data[$rec])) {
                    // The SAME record-id posture the findings use. 'none' mode
                    // exists for sites where the record id is itself identifying,
                    // and these notes are rendered on the page and written into
                    // the CSV twice - for exactly the records a site is chasing.
                    $result['incomplete'][] = 'record ' . $this->reportRecordId($plan, $rec)
                        . ' was requested but not returned';
                    continue;
                }
                try {
                    $mine = [];
                    $one = $this->scanRecord($plan, $pid, $rec, $data[$rec], $sink, $uniqueSeen, $mine);
                    // A rule's problem is the same in every record and is listed once.
                    // An extended context that could not be resolved, or a spent budget,
                    // is this record's: keyed by rule and position alone, three unfinished
                    // records gave two notes and no incomplete line (wargame P5). The
                    // durable scan keeps them per record the same way.
                    foreach ($mine as $k => $u) {
                        $code = explode('|', (string) $k, 3)[1] ?? '';
                        if (!in_array($code, ['temporal', 'temporal-group', 'temporal-budget'], true)) {
                            if (!isset($unconf[$k])) $unconf[$k] = $u;
                            continue;
                        }
                        $who = $this->reportRecordId($plan, $rec);
                        $unconf[$k . '|' . $rec] = ['record' => $who] + $u;
                        if ($code === 'temporal-budget') {
                            $result['incomplete'][] = 'record ' . $who . ' was not fully checked: the extended evaluation budget ran out';
                        }
                    }
                } catch (\Throwable $e) {
                    // The SINK is a caller-supplied consumer - it writes to a
                    // spool, a socket, a table. When it threw, the exception
                    // escaped scanProject entirely and took the result with it:
                    // no status, no incomplete list, nothing recording that the
                    // project had not been examined, which is the one failure
                    // this contract exists to prevent (M-03).
                    $result['incomplete'][] = 'record ' . $this->reportRecordId($plan, $rec)
                        . ' could not be reported: ' . get_class($e);
                    continue;
                }
                if ($one['why'] !== null) {
                    $result['incomplete'][] = $one['why'];
                    continue;
                }
                $result['stats']['contexts'] += $one['contexts'];
            }
            // The chunk's rows are dead now. Releasing them before the next
            // getData allocates means the two do not coexist, which halves the
            // read peak; without it the last chunk is also held to the return.
            $reached += count($chunk);
            unset($data);
        }

        // Aggregate duplicate detection: a group is a violation when TWO OR
        // MORE DISTINCT RECORDS share the key (same-record repeats mirror the
        // endpoint/audit, which only compare against OTHER records). A lookup
        // (collectUniqueLookups) counts as one of those records but is not
        // itself reported: the rule checks its own fields where it applies.
        $emitted = [];
        foreach ($uniqueSeen as $entries) {
            $records = [];
            foreach ($entries as $e) $records[$e['record']] = true;
            if (count($records) < 2) continue;
            foreach ($entries as $e) {
                if (!empty($e['lookup'])) continue;
                // One row, one finding. Host scoping already stops a rule being
                // collected from contexts it does not live in; this is the belt to
                // that brace, so a row can never be listed twice for one rule
                // whatever the record shape (H-04).
                $at = $e['rule'] . '|' . $e['record'] . '|' . $e['event_id'] . '|' . $e['instance'] . '|' . $e['field'];
                if (isset($emitted[$at])) continue;
                $emitted[$at] = true;
                // Already filtered at collection time (see collectUniqueCandidates):
                // false means withheld by policy, null means there was nothing.
                $rv = array_key_exists('value', $e) ? $e['value'] : null;
                $sink->violation([
                    'record' => $this->reportRecordId($plan, $e['record']), 'event_id' => $e['event_id'],
                    'instance' => $e['instance'], 'field' => $e['field'],
                    'type' => 'unique', 'reason' => 'duplicate-value', 'rule' => $e['rule'],
                    'value' => ($rv === false) ? null : $rv,
                    'valueWithheld' => ($rv === false),
                    'instrument' => isset($e['instrument']) ? $e['instrument'] : null,
                    'dag' => isset($e['dag']) ? $e['dag'] : null,
                ] + (isset($e['branch']) ? ['branch' => (int) $e['branch']] : []));
            }
        }
        $result['unconfigurable'] = array_values($unconf);
        // The count is kept whatever the sink does with the rows, so a streaming
        // caller can still say how many findings there were — and so 'no
        // violations' is never inferred from an empty array that was never
        // filled in the first place (M-02).
        // What was EXAMINED, not what was listed. Set before the loop and never
        // revised, this reported the full manifest as the headline on a scan
        // that had halted at the first chunk boundary — "Scanned 400 record(s)"
        // in bold, with the truth in a bullet inside a warning box, and the same
        // 400 on the export's metadata line.
        $result['stats']['records'] = $reached;
        $result['stats']['violations'] = $sink->count();
        // COVERAGE is a separate axis from STATUS. Status says whether the sweep
        // finished; coverage says what finishing is worth on this installation.
        // A run that read every record on its opening list, on a server where no
        // change fence can be proved, is 'manifest-complete': it cannot know the
        // project did not move underneath it, and per the plan that must never
        // render as complete or clean.
        // MERGED, not overwritten. The installation's limits come from the
        // capability policy; the run's own limits are recorded during the sweep
        // (the narrowed chunk read above). Assigning here discarded the second
        // set, which is the only kind a reader can act on.
        $result['limits'] = array_merge(
            isset($plan['policy']['limits']) ? $plan['policy']['limits'] : [],
            isset($result['limits']) ? $result['limits'] : []);
        if ($collect) $result['violations'] = $sink->violations;
        // Only now can the scan claim it saw everything.
        $result['status'] = $result['incomplete'] ? 'incomplete' : 'complete';
        // AFTER the status, never before: read a line too early this consulted
        // the initial 'failed' and every run came back 'partial', which silently
        // withheld the tick from scans that had earned it.
        $maxCov = isset($plan['policy']['maxCompletion']) ? $plan['policy']['maxCompletion'] : 'manifest-complete';
        $result['coverage'] = ($result['status'] === 'complete') ? $maxCov : 'partial';
        // The rule list the ordinals in these findings refer to. A report that
        // re-read getRules() to resolve 'Rule 12' was joining two INDEPENDENT
        // reads by array position: add or reorder a rule between them and every
        // label lands on the wrong finding, silently. Bounded by the rule count,
        // never by the data.
        $result['rules'] = isset($plan['allRules']) ? $plan['allRules'] : [];
        return $result;
    
    };
    if ($sink !== null && !method_exists($sink, 'count')) $sink = new CountedFindingSink($sink);
    return \Closure::bind($run, $m, UniversalValidator::class)($pid, $dagFilter, $chunkSize, $sink, $opts);
}
