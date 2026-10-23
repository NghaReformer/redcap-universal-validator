<?php
/**
 * The Validation scan: the legacy one-request scan (scanProject), the durable
 * scan's per-record evaluation (durableScanContext, durableEvaluateRecord),
 * the scan plan (buildScanPlan), unique-candidate collection, and the scan's
 * AJAX actions (scanAction). Moved out of UniversalValidator.php unchanged
 * (QUA-2); the class keeps the constants (REPORT_VALUE_MAX, REPORT_REDACTED),
 * since a PHP 7.4 trait cannot hold one.
 */

namespace INSPIRE\UniversalValidator;

trait ScanRuns
{
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
    public function scanProject($pid, $dagFilter = null, $chunkSize = 200, ?FindingSink $sink = null, array $opts = [])
    {
        // 'status' is part of the contract: a scan that could not read everything
        // must never be presentable as a clean bill of health. 'complete' is
        // only ever set at the very end of the full path.
        $result = ['violations' => [], 'unconfigurable' => [], 'incomplete' => [],
                   'status' => 'failed',
                   'stats' => ['records' => 0, 'contexts' => 0, 'rules' => 0, 'violations' => 0, 'justified' => 0]];
        $this->scanJustified = 0;

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
            $why = self::scanHalt($deadline, $memCap, microtime(true), memory_get_usage(true));
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
                    $one = $this->scanRecord($plan, $pid, $rec, $data[$rec], $sink, $uniqueSeen, $unconf);
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
        // Open findings only: the ones a reason stands for (@UVJUSTIFY) are
        // counted apart, and the sink still holds every row.
        $result['stats']['justified'] = $this->scanJustified;
        $result['stats']['violations'] = max(0, $sink->count() - $this->scanJustified);
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
    }

    /**
     * The label snapshot a report needs, for THIS project.
     *
     * Public because the export page needs it and the pieces it is built from
     * are private. By the time a report asks, the scan has already read both the
     * dictionary and the rules, so this is a memory read rather than a second
     * pass over the project.
     */
    public function scanDimensions($pid, ?array $rules = null)
    {
        $dd = $this->dataDictionary($pid);
        // Prefer the snapshot the scan actually used. Re-reading here joined the
        // findings to a SECOND read by array position, so a rule added or
        // reordered between the two moved every label onto the wrong finding.
        if ($rules === null) {
            $rules = [];
            try { $rules = $this->getRules($pid); } catch (\Throwable $e) { $rules = []; }
        }
        return ScanDimensions::build($pid, is_array($dd) ? $dd : [], is_array($rules) ? $rules : []);
    }

    /** The record id as the report may show it, honouring the log-values posture. */
    private function reportRecordId(array $plan, $rec)
    {
        if (empty($plan['hashRecordIds'])) return (string) $rec;
        try {
            $h = $this->hashedIdentifier($plan['pid'], (string) $rec);
        } catch (\Throwable $e) {
            $h = null;
        }
        // hashedIdentifier RETURNS null when no key can be obtained - it catches
        // its own failure rather than throwing - so a catch alone never fired and
        // every Record cell rendered EMPTY. On screen that is a table of
        // violations with no way to reach any of them; in a CSV it reads as a
        // fault in the reader's own export. Never the raw id, but never blank
        // either: say that it was withheld.
        return ($h === null || $h === '') ? '[record id unavailable]' : $h;
    }

    /**
     * The project's record-id field, or '' when it cannot be established.
     *
     * ONE ANSWER FOR EVERY CALLER. The scan's record list, both scan reads, the
     * form path's read (H-06), the report's redaction of the record-id field and
     * the cross-project @UVEXISTS lookups all need this.
     *
     * THE PROJECT'S OWN DICTIONARY FIRST. REDCap's first data-dictionary field is
     * the record identifier, and the dictionary is read for the project asked
     * about. \REDCap::getRecordIdField() takes no project and answers for the
     * project of the request, so it is asked only when the dictionary cannot be
     * read AND the project asked about is that project; for another project it
     * would name the wrong field. Without either answer the legacy scan used to
     * ask for every rule field for every record in ONE unchunked call, which on
     * a large project is the whole project in memory before a record has been
     * examined.
     */
    private function recordIdFieldOf($pid)
    {
        try {
            $dd = $this->dataDictionary($pid);
        } catch (\Throwable $e) {
            $dd = null;
        }
        if (is_array($dd) && $dd) {
            reset($dd);
            return (string) key($dd);
        }
        if (defined('PROJECT_ID') && (int) PROJECT_ID !== (int) $pid) return '';
        try {
            $pk = is_callable(['\REDCap', 'getRecordIdField']) ? \REDCap::getRecordIdField() : null;
        } catch (\Throwable $e) {
            $pk = null;
        }
        return (is_string($pk) && $pk !== '') ? $pk : '';
    }

    /**
     * The value to show beside one finding, or null to show nothing.
     *
     * Four things can stop a value reaching the report, and they are NOT the
     * same and must not look the same to a reader:
     *   - policy says never          -> false, rendered as a marker
     *   - the finding has no value   -> null (a required-blank IS the blank)
     *   - the field is an Identifier -> a marker, so the reader knows a value
     *                                   exists and was withheld rather than absent
     *   - the bytes are not text     -> a marker with the length. The module's own
     *                                   L-01 comment records that values can carry
     *                                   invalid UTF-8 from a Latin-1 import, and
     *                                   pasting those into a CSV corrupts the file.
     */
    private static function reportValue(array $v, array $plan)
    {
        // Fail CLOSED on a missing key. This defaulted to 'raw', which put the
        // MOST disclosing option twenty lines above valueRank()'s docblock
        // promising that "anything unrecognised is treated as the least
        // disclosing option" - a fail-open default in the one function whose
        // entire job is to withhold. scanPlan() always sets the key today, so
        // this was latent; a default that is only safe because nobody takes it
        // is not a safe default.
        $mode = isset($plan['valueMode']) ? $plan['valueMode'] : 'locations';

        if (!array_key_exists('value', $v)) return null;
        $val = $v['value'];
        if (is_array($val)) $val = implode(', ', array_map('strval', $val));   // a checkbox
        $val = (string) $val;

        // NOTHING TO WITHHOLD and WITHHELD are different claims, and telling
        // them apart is the entire reason the marker exists. This branched on
        // the KEY existing - but the required path sets 'value' => ''
        // unconditionally (see ruleFindings above), so the key ALWAYS exists and
        // every required-blank finding rendered '[withheld by policy]'. That is
        // an affirmative false statement about a field that is empty, made on
        // the one finding type whose whole content is that the field is empty.
        // Branch on there being a value, which is what the sentence means.
        if ($val === '') return null;
        if ($mode === 'locations') return false;

        $field = isset($v['field']) ? (string) $v['field'] : '';
        $ids = isset($plan['identifiers']) ? $plan['identifiers'] : null;
        if (self::mustRedact($ids, $field, $mode)) return self::REPORT_REDACTED;
        // THE RECORD ID IS AN IDENTIFIER WHEREVER IT IS HASHED. Under
        // log-values = none the Record column carries an HMAC because the id
        // itself identifies the participant, and a finding on the record-id
        // field put that same id, raw, in the Value cell beside it. Withheld
        // under every mode, because the hashing is the project's posture and
        // the reader's ceiling cannot lift it.
        if (!empty($plan['hashRecordIds']) && isset($plan['recordIdField'])
            && $plan['recordIdField'] !== '' && $field === (string) $plan['recordIdField']) {
            return self::REPORT_REDACTED;
        }

        if (!mb_check_encoding($val, 'UTF-8')) {
            return '[' . strlen($val) . ' bytes, not valid text]';
        }
        if (mb_strlen($val, 'UTF-8') > self::REPORT_VALUE_MAX) {
            return mb_substr($val, 0, self::REPORT_VALUE_MAX, 'UTF-8') . '… (truncated)';
        }
        return $val;
    }

    /**
     * How disclosing a value mode is. Higher shows more. Unknown ranks LOWEST,
     * so anything unrecognised is treated as the least disclosing option rather
     * than the most.
     */
    private static function valueRank($mode, $unknown = 0)
    {
        $r = ['locations' => 0, 'identifier-redacted' => 1, 'raw' => 2];
        return isset($r[$mode]) ? $r[$mode] : $unknown;
    }

    /** The less disclosing of two modes. */
    private static function valueFloor($a, $b)
    {
        return self::valueRank($a) <= self::valueRank($b) ? $a : $b;
    }

    /**
     * How the scan report may show values: 'raw' | 'identifier-redacted' | 'locations'.
     *
     * A settings read that throws must not decide between showing values and
     * withholding them, so a failure lands on the most permissive documented
     * default rather than silently switching policy — the same posture logMode()
     * takes, and for the same reason: a quietly-changed privacy mode is worse
     * than a wrong one, because nobody can tell it happened.
     */
    private function scanValueMode($pid)
    {
        try {
            $m = $this->getProjectSetting('scan-value-storage', $pid);
            if (self::valueRank($m, -1) >= 0) return $m;
        } catch (\Throwable $e) {
        }
        // An External Modules dropdown stores NOTHING until the settings dialog
        // is saved, so this is null on every project nobody has reconfigured -
        // which is most of them, and all of them on upgrade. Landing on 'raw'
        // there would switch every existing installation from locations-only to
        // full disclosure with nobody having decided anything. Unknown or
        // unreadable settings fail toward LESS disclosure.
        return 'locations';
    }

    /**
     * TRUE when this field's value must NOT appear in the report.
     *
     * The INVERSE of isIdentifier()'s posture, deliberately. That helper answers
     * "is this field known to be an identifier", so an unreadable dictionary
     * means "nothing is" — right for refusing to enable a survey feature, and
     * catastrophic here, where it would mean "redact nothing". A dictionary we
     * cannot read is a dictionary that cannot clear a field, so in 'identifiers'
     * mode an unreadable one redacts EVERYTHING.
     *
     * @param array|null $ids projectIdentifierFields(), which returns null on a failed read
     */
    private static function mustRedact($ids, $field, $mode)
    {
        if ($mode === 'locations') return true;
        if ($mode === 'raw')       return false;
        if (!is_array($ids))       return true;     // cannot clear it -> withhold it
        return isset($ids[$field]);
    }

    /**
     * The rule problems of a plan or a record, each with the CODE it was filed
     * under.
     *
     * The list is keyed "<rule index>|<code>" while it is built, which is what
     * keeps one problem from being listed twice, and it used to leave as
     * array_values() of itself - so the code was thrown away at the door and the
     * durable scan, which records each problem ONCE PER RUN, had nothing to
     * recognise one by but its sentence. A sentence that names an event or an
     * instance is a different sentence on every record, and one fault became a
     * row per record.
     *
     * THE CODE IS THE SECOND SEGMENT, WHEN IT IS A CODE. Two kinds of key carry
     * more: "<i>|temporal|<context>", where the third segment is the context and
     * the problem is still `temporal`; and "<i>|<sentence>", filed by the
     * sentence itself because the rule kind that raised it had no code to give.
     * A sentence is not a code, and is left for Scan\ScanPromotion::problemKey()
     * to hash, as it always has been.
     */
    private static function problemList(array $unconf)
    {
        $out = [];
        foreach ($unconf as $key => $problem) {
            $parts = explode('|', (string) $key, 3);
            if (isset($parts[1]) && preg_match('/^[A-Za-z][A-Za-z0-9-]{0,63}$/', $parts[1])) {
                $problem['code'] = $parts[1];
            }
            $out[] = $problem;
        }
        return $out;
    }

    /**
     * The one seam between this framework adapter and the durable scan.
     *
     * WHY A SINGLE METHOD RATHER THAN PUBLIC ACCESSORS. Everything under
     * php/Scan/ is written to be testable without REDCap; the moment it can
     * reach into this class it stops being. So the durable side asks once, gets
     * closures, and never learns what a data dictionary is. scanPlan() and
     * scanRecord() stay private, which also means the legacy synchronous path
     * and the durable one cannot drift into two different ideas of what a rule
     * means - they run the same two methods.
     *
     * WHAT IT REFUSES. A plan with a fatal problem does not become a run with a
     * caveat: a scan that cannot resolve its own rules has nothing true to say
     * about the project, and starting one would produce a report whose emptiness
     * looks like good news.
     *
     * @return array{ok:bool, why:?string, plan:?array, evaluate:?callable,
     *               read:?callable, rules:array, ownership:array, problems:array,
     *               verifyFingerprint?:bool, structure?:array, choices?:array,
     *               unread?:string[]}
     *         the last four on success only (scanStructureFacts()); `unread`
     *         names the configuration reads that failed (review R5-4)
     */
    public function durableScanContext($pid, array $opts = [], $dagFilter = null)
    {
        $plan = $this->scanPlan($pid, $opts, $dagFilter);
        // THE SHAPE IS THE SAME ON EVERY RETURN, including the refusals. A
        // caller that has to know which branch answered before it knows which
        // keys exist is a caller that will read the wrong one.
        if ($plan['fatal'] !== null) {
            return ['ok' => false, 'why' => $plan['fatal'], 'plan' => null,
                    'evaluate' => null, 'read' => null, 'rules' => [], 'ownership' => [],
                    'problems' => self::problemList($plan['unconf'])];
        }
        if (!empty($plan['nothingToScan'])) {
            // The rule problems survive the refusal even though no run can carry
            // them today. scanPlan()'s own note says every rule barred is not
            // nothing to scan - the rule problems ARE the report, and they must
            // survive - and this return is where they stopped surviving. Closing
            // it completely needs a run that can exist with no live rules, which
            // is a later decision; handing them back is what makes it possible.
            return ['ok' => false, 'why' => 'this project has no rules this scan can evaluate',
                    'plan' => null, 'evaluate' => null, 'read' => null,
                    'rules' => [], 'ownership' => [],
                    'problems' => self::problemList($plan['unconf'])];
        }

        $key = $this->hmacKey();

        // THE GENERATION IS THE RUN'S, AND THERE IS NO DEFAULT.
        //
        // It used to be `isset($opts['generation']) ? ... : 1`, and no caller
        // anywhere passed one - so every run of every project wrote generation
        // 1. That single default is the root cause of five failed pilots: the
        // second scan of any project re-inserted identities that were already
        // active, the unique key refused them, and the batch rolled back
        // forever. A default is what let it ship, so there is no longer one.
        //
        // NULL is a legitimate value and means "planning": start() needs the
        // rules and the ownership map before a run exists to have a generation,
        // so it asks for a context with no evaluator rather than inventing a
        // number. Passing no key at all is still an error.
        if (!array_key_exists('generation', $opts)) {
            throw new \InvalidArgumentException(
                'durableScanContext requires the run generation (null for planning)');
        }
        $gen = ($opts['generation'] === null) ? null : (int) $opts['generation'];
        $runSeq = isset($opts['runSeq']) ? (int) $opts['runSeq'] : 0;

        // When a stored value preview expires, decided at WRITE time. The
        // policy method that computes this had no callers at all, so every
        // preview was written with a NULL expiry and the query that removes
        // them could never have matched one.
        $valueExpiry = Scan\ScanPolicy::valueExpiry(
            isset($opts['policy']) && is_array($opts['policy']) ? $opts['policy'] : [], time());

        // Taken from the plan, never re-derived. See scanPlan()'s note where
        // ruleIds is built: deriving it twice is what let the evaluator and the
        // planner disagree about which rule an ordinal named.
        $ids = isset($plan['ruleIds']) && is_array($plan['ruleIds']) ? $plan['ruleIds'] : [];

        // TAKEN FROM THE PLAN, exactly as $ids is one block above, and for the
        // same reason. It used to be built HERE by walking $plan['hostFields'],
        // which ruleHostForms() fills from $rule['fields'] alone - so the map
        // ScanService turns into an entitlement named the forms the RULES live
        // on while getData was asked for the read set, which also carries every
        // when/assert operand and every unique-composite partner. scanPlan()
        // derives it from $readSet now; a second derivation here is the drift
        // this file keeps paying for.
        $ownership = isset($plan['ownership']) && is_array($plan['ownership'])
                   ? $plan['ownership'] : [];

        $module = $this;
        // No generation, no evaluator. A caller that only needs the rule list
        // gets one it cannot accidentally scan with.
        $evaluate = ($gen === null) ? null : function ($recordId, array $node) use ($module, $plan, $pid, $gen, $key, $ids,
                                                          $runSeq, $valueExpiry) {
            return $module->durableEvaluateRecord($plan, $pid, $recordId, $node, $gen, $key, $ids,
                                                  $runSeq, $valueExpiry);
        };

        // The read the worker performs. Explicit records, and only the fields
        // the plan actually needs - the same narrowing the chunked legacy path
        // does, for the same reason.
        //
        // PLUS THE RECORD-ID FIELD, for the reason foldRuleConditions() gives
        // (H-06): getData omits a blank field, so a record whose every field in
        // the read set is blank comes back with no node at all. Since H18 the
        // worker asks whether such a record still exists, is told it does, and
        // files it UNREADABLE on every run - so the record @UVREQUIRED exists to
        // flag is never evaluated and the project can never finish complete.
        // The record id is stored for every record and is never blank. It is
        // added to the READ only: $plan['readSet'] also keys the entitlement,
        // and the record id is not form data the scan reports on.
        //
        // PLUS THE HOST FORMS' STATUS FIELDS (R2O-2), on the same terms and for
        // the reason scanPlan() gives: they say where each host was started.
        $pk = isset($plan['recordIdField']) ? (string) $plan['recordIdField'] : '';
        $read = function (array $recordIds) use ($pid, $plan, $pk) {
            try {
                if (!is_callable(['\REDCap', 'getData'])) {
                    return ['ok' => false, 'data' => [],
                            'why' => 'this installation does not expose a record read'];
                }
                $data = \REDCap::getData(self::scanReadParams($plan, $pid, $recordIds, $pk));
                if (!is_array($data)) {
                    return ['ok' => false, 'data' => [], 'why' => 'the records could not be read'];
                }
                return ['ok' => true, 'data' => $data, 'why' => null];
            } catch (\Throwable $e) {
                // A FAILED READ IS NOT AN EMPTY ONE. The worker requeues on
                // false and would commit "examined, nothing found" on an empty
                // success - which is the difference this return exists to keep.
                return ['ok' => false, 'data' => [],
                        'why' => 'the records could not be read (' . get_class($e) . ')'];
            }
        };

        // THE RULE PROBLEMS TRAVEL. They were computed by scanPlan() - config
        // errors, rules whose instrument cannot be resolved, rules on an
        // instrument no event collects, project-scope uniqueness under a group
        // scope - and then dropped right here, which is why ScanOutcome's
        // `ruleProblems` term had nothing to read and `clean` quietly meant "no
        // findings". array_values because the keys are the dedupe (rule|reason),
        // not data anything downstream should depend on.
        //
        // THE PROJECT'S DESIGN TRAVELS TOO (review M8): the structure and the
        // choice sets are two of the fingerprint's inputs, and this return
        // carried only the temporal digest - [] on a project with no
        // event/instance rule - and no choices at all. The guard that ends a
        // run when the project changes underneath it compared two empty
        // arrays, so a field's validation or a radio's codes could change
        // between two requests of one run and the run finished against both.
        $facts = $this->scanStructureFacts($pid, $plan);
        // WHAT THE CONFIGURATION ABOVE COULD NOT READ (review R5-4): the
        // structure, the codes and the rules' configuration errors are the
        // fingerprint's inputs, and a fact built from a failed read is not the
        // project's. Named, so the refusal can say what; ScanService refuses
        // to start or advance a run on a configuration with any.
        $pk = (int) $pid;
        $unread = isset($this->configUnread[$pk]) ? array_keys($this->configUnread[$pk]) : [];
        sort($unread, SORT_STRING);
        return ['ok' => true, 'why' => null, 'plan' => $plan, 'evaluate' => $evaluate,
                'read' => $read, 'rules' => $plan['live'], 'ownership' => $ownership,
                'verifyFingerprint' => true, 'structure' => $facts['structure'],
                'choices' => $facts['choices'], 'unread' => $unread,
                'problems' => self::problemList($plan['unconf'])];
    }

    /**
     * The project's design as the fingerprint guard compares it (review M8):
     * the structure and the choice sets a work request rebuilds and
     * ScanService::configurationNow() checks against the run's stored
     * fingerprint.
     *
     * WHAT DECIDES A FINDING, AND NOTHING ELSE. Per field, only the fields the
     * run reads: a field it reads cannot move instrument, change type or
     * change validation, and a choice field it reads cannot change its codes,
     * without ending the run; a field it never reads can be added or edited
     * freely. The instrument-event mapping and the repeating setup are the
     * project's, because they decide which contexts every rule is judged in.
     * The Missing Data Codes are the project's, because a value that is one is
     * not entered. Labels are left out; codes are sorted, because their order
     * on the form decides nothing.
     *
     * FROM \Project, NOT FROM THE REDCap STATICS. getInstrumentEventMappings()
     * and its siblings answer from the request's ambient project, which a cron
     * request does not have; two requests of one run that read the same design
     * two ways would end it for nothing.
     *
     * UNREADABLE IS A VALUE, never an empty list. A part of the project object
     * that could not be read is recorded as 'unreadable', so a run started on
     * a read that failed and worked on one that succeeded ends - the
     * fail-closed direction - while an empty list stays what a project with
     * nothing there reads as. The dictionary needs no such marker: scanPlan()
     * has refused the run already when it cannot be read.
     *
     * The temporal digest stays as temporalScanStructure() gives it, at the top
     * level: it covers the whole dictionary, and only for a project that has an
     * event/instance rule.
     *
     * @return array{structure:array, choices:array}
     */
    private function scanStructureFacts($pid, array $plan)
    {
        $readSet = (isset($plan['readSet']) && is_array($plan['readSet'])) ? array_keys($plan['readSet']) : [];
        // The dictionary was read: scanPlan() refuses the run when it cannot
        // be, and dataDictionary() keeps a successful read for the request.
        $dd = $this->dataDictionary($pid) ?: [];
        $fields = [];
        foreach ($readSet as $f) {
            $f = (string) $f;
            // A form's status field (fb_complete) is read and is not in the
            // dictionary; neither is a field that was deleted.
            if (!isset($dd[$f]) || !is_array($dd[$f])) {
                $fields[$f] = 'absent';
                continue;
            }
            $meta = $dd[$f];
            $fields[$f] = [
                'form' => isset($meta['form_name']) ? (string) $meta['form_name'] : '',
                'type' => isset($meta['field_type']) ? (string) $meta['field_type'] : '',
                'validation' => self::validationOf($meta)];
        }

        $longitudinal = 'unreadable';
        $events = 'unreadable';
        $repeating = 'unreadable';
        $project = $this->projectObject($pid);
        if ($project !== null) {
            if (isset($project->longitudinal)) $longitudinal = (bool) $project->longitudinal;
            // event id => the instruments designated to it.
            if (isset($project->eventsForms) && is_array($project->eventsForms)) {
                $events = [];
                foreach ($project->eventsForms as $ev => $forms) {
                    $events[(string) $ev] = self::sortedNames(is_array($forms) ? $forms : []);
                }
            }
            // event id => 'WHOLE' for a repeating event, else its repeating
            // instruments ([form => custom label] in REDCap's answer).
            try {
                if (is_callable([$project, 'getRepeatingFormsEvents'])) {
                    $map = $project->getRepeatingFormsEvents();
                    if (is_array($map)) {
                        $repeating = [];
                        foreach ($map as $ev => $node) {
                            if (!is_array($node)) {
                                $repeating[(string) $ev] = is_scalar($node) ? (string) $node : 'unreadable';
                                continue;
                            }
                            $forms = [];
                            foreach ($node as $k => $v) $forms[] = is_int($k) ? $v : $k;
                            $repeating[(string) $ev] = self::sortedNames($forms);
                        }
                    }
                }
            } catch (\Throwable $e) {
                $repeating = 'unreadable';
                $this->noteConfigUnread($pid, 'the repeating instruments and events');
            }
        }

        $sets = $this->projectFieldChoices($pid) ?: [];
        $codes = [];
        foreach ($readSet as $f) {
            if (isset($sets[$f]) && is_array($sets[$f])) $codes[(string) $f] = self::sortedNames($sets[$f]);
        }
        $missing = (isset($plan['missingCodes']) && is_array($plan['missingCodes']))
                 ? array_keys($plan['missingCodes']) : [];

        return [
            'structure' => array_merge($this->temporalScanStructure($pid, $plan['live']), [
                'fields' => $fields, 'longitudinal' => $longitudinal,
                'events' => $events, 'repeating' => $repeating]),
            'choices' => ['fields' => $codes, 'missing' => self::sortedNames($missing)],
        ];
    }

    /** Scalar names as distinct strings in byte order, so a list's order is no part of a comparison. */
    private static function sortedNames(array $names)
    {
        $out = [];
        foreach ($names as $n) if (is_scalar($n)) $out[(string) $n] = true;
        $out = array_map('strval', array_keys($out));
        sort($out, SORT_STRING);
        return $out;
    }

    /**
     * One record, turned into durable rows.
     *
     * Public only because the closure above needs it; it is not part of any
     * contract and takes the plan it was built from. Everything it maps is a
     * decision already made elsewhere - reportValue() decides disclosure, the
     * rule identities come from the planner - so this method chooses nothing and
     * exists to translate.
     *
     * @return array{findings:array, candidates:array, bytes:int, contexts:int,
     *               problems:array, why:?string}
     */
    public function durableEvaluateRecord(array $plan, $pid, $recordId, array $node, $gen, $key,
                                          array $ids, $runSeq = 0, $valueExpiry = null)
    {
        $found = [];
        $sink = new CallbackFindingSink(function (array $v) use (&$found) { $found[] = $v; });
        $seen = [];
        $unconf = [];
        $r = $this->scanRecord($plan, $pid, $recordId, $node, $sink, $seen, $unconf);
        if ($r['why'] !== null) {
            return ['findings' => [], 'candidates' => [], 'bytes' => 0, 'contexts' => 0,
                    'problems' => [], 'collapsed' => 0, 'why' => $r['why']];
        }

        $recHash = Scan\Hmac::raw(Scan\Hmac::P_RECORD, $pid, (string) $recordId, $key);
        $missed = [];
        $rule = function ($ord) use ($ids, &$missed) {
            $i = ((int) $ord) - 1;
            // A rule the planner could not name is still reported, under a name
            // that says so. Dropping the finding would be the silent skip.
            //
            // AND IT IS NOW LOUD. $ids is keyed by the same ordinals as
            // $plan['live'], so after the key-preserving fix this branch is
            // unreachable in normal operation - which means any occurrence is a
            // bug in us, not a fact about the project. It used to be reachable
            // on every project with one config-broken rule, and it produced a
            // plausible-looking name ('unnamed:7') that no reader would question.
            if (isset($ids[$i])) return $ids[$i];
            $missed[(int) $ord] = true;
            return ['source_id' => 'unnamed:' . (int) $ord, 'revision' => str_repeat('0', 64)];
        };

        $findings = [];
        $byIdentity = [];
        $collapsed = 0;
        $bytes = 0;
        foreach ($found as $v) {
            $id = $rule($v['rule']);
            $loc = ['record' => (string) $recordId, 'event_id' => $v['event_id'],
                    'instance' => $v['instance'], 'host_form' => $v['instrument'],
                    'field' => $v['field'], 'rule_source_id' => $id['source_id'],
                    'reason_code' => Scan\ReasonCode::code($v['reason']),
                    // The within-location discriminator. See Hmac::findingIdentity.
                    'locus' => isset($v['locus']) ? (string) $v['locus'] : ''];
            $val = empty($v['valueWithheld']) && isset($v['value']) ? $v['value'] : null;
            $blob = ($val === null) ? null : substr((string) $val, 0, 255);
            $identity = Scan\Hmac::findingIdentity($pid, $loc, $key);

            // BACKSTOP, NOT THE FIX. With the discriminator in place nothing
            // legitimate produces one identity twice, so a repeat here is a bug
            // in a rule kind rather than a fact about the project - and it must
            // be COUNTED rather than allowed to reach the unique key, where it
            // would roll back a whole batch of correctly examined records. The
            // count travels out so the worker can raise it; swallowing it in
            // SQL with ON DUPLICATE KEY UPDATE would hide exactly the thing
            // this release exists to make visible.
            $seenKey = bin2hex($identity);
            if (isset($byIdentity[$seenKey])) { $collapsed++; continue; }
            $byIdentity[$seenKey] = true;

            if ($blob !== null) $bytes += strlen($blob);
            $findings[] = [
                'project_id' => (int) $pid,
                'generation_id' => $gen,
                'identity' => $identity,
                // The RUN's sequence number, not a per-record ordinal. It used
                // to be `++$seq`, which put valid_from_seq and valid_to_seq in
                // different number spaces and made the interval columns the
                // schema is built around describe nothing.
                'valid_from_seq' => $runSeq,
                'record_hash' => $recHash,
                'record_id_bin' => (string) $recordId,
                'event_id' => $v['event_id'],
                'instance' => $v['instance'],
                'host_form' => (string) $v['instrument'],
                'field' => (string) $v['field'],
                'rule_source_id' => $id['source_id'],
                'rule_revision' => $id['revision'],
                'rule_ord' => (int) $v['rule'],
                'check_type' => (string) $v['type'],
                'reason_code' => Scan\ReasonCode::code($v['reason']),
                'dag_key' => isset($v['dag']) ? $v['dag'] : null,
                'value_bin' => $blob,
                'value_len' => ($val === null) ? null : strlen((string) $val),
                'value_truncated' => ($val !== null && strlen((string) $val) > 255) ? 1 : 0,
                'value_fingerprint' => ($val === null) ? null
                    : Scan\Hmac::raw(Scan\Hmac::P_VALUE, $pid, (string) $val, $key),
                // WRITTEN AT WRITE TIME. The expiry policy was computed by a
                // method with no callers, so every stored preview carried a NULL
                // here and the query that expires them - WHERE value_expires_at
                // IS NOT NULL - could never have matched a row even once it was
                // wired. Participant data was retained indefinitely in a table
                // any user with design rights can read.
                'value_expires_at' => ($blob === null) ? null : $valueExpiry,
                // The day a @UVWINDOW verdict was judged against, for the report.
                'as_of' => isset($v['asOf']) ? (string) $v['asOf'] : null,
                // A growth finding's z-score. It gives the measurement back (and
                // an age), so scanRecord passes it only when values are shown
                // raw: it is stored only beside a raw value preview, and cleared
                // with it (ScanRetention). And its reference, which is the rule's and
                // not participant data; a rule with branches per reference has
                // no one reference, so the finding carries its own.
                'z_score' => isset($v['z']) ? (string) $v['z'] : null,
                'growth_reference' => isset($v['reference']) ? substr((string) $v['reference'], 0, 64) : null,
                // A reason (@UVJUSTIFY) stands for it: counted apart, and no bar to clean.
                'justified' => empty($v['justified']) ? 0 : 1,
            ];
        }

        // Uniqueness produces CANDIDATES, never findings: no record is a
        // duplicate on its own evidence. The composite key built by the legacy
        // path is reused verbatim and then keyed, so the live check, the audit
        // and the scan all agree about what "the same value" means.
        $candidates = [];
        $candSeen = [];
        foreach ($seen as $groupKey => $rows) {
            $g = Scan\Hmac::raw(Scan\Hmac::P_UNIQUE, $pid, (string) $groupKey, $key);
            foreach ($rows as $row) {
                $id = $rule($row['rule']);
                // scope_key was the literal 'project' whatever the rule said, so
                // a rule scoped to a Data Access Group or to an event was stored
                // as though it were project-wide. The rule knows its own scope;
                // the store was being told something else.
                $scope = isset($row['scope']) && is_string($row['scope']) && $row['scope'] !== ''
                    ? (string) $row['scope'] : 'project';
                // The candidate key the store enforces, computed here so an
                // intra-record repeat is dropped before it can refuse a batch -
                // the same backstop the findings get, for the same reason.
                $ck = $g . '|' . $recHash . '|' . (string) $row['event_id'] . '|'
                    . (string) $row['instance'] . '|' . (string) $row['field'];
                if (isset($candSeen[$ck])) { $collapsed++; continue; }
                $candSeen[$ck] = true;
                $candidates[] = [
                    'project_id' => (int) $pid,
                    'generation_id' => $gen,
                    'rule_source_id' => $id['source_id'],
                    'rule_revision' => $id['revision'],
                    'group_hmac' => $g,
                    'scope_key' => $scope,
                    'record_hash' => $recHash,
                    'record_id_bin' => (string) $recordId,
                    'event_id' => $row['event_id'],
                    'instance' => $row['instance'],
                    'host_form' => (string) $row['instrument'],
                    'field' => (string) $row['field'],
                    // Evidence for the group, never a finding (collectUniqueLookups).
                    'lookup' => empty($row['lookup']) ? 0 : 1,
                ];
            }
        }

        // A rule ordinal the planner could not name is an INTERNAL fault, and it
        // travels as a rule problem rather than as a plausible name nobody
        // questions. It is reported per record, which is where it was noticed;
        // the aggregate that counts rule problems dedupes on the text.
        $problems = self::problemList($unconf);
        foreach (array_keys($missed) as $ord) {
            $problems[] = [
                'rule'   => (int) $ord,
                'code'   => 'no-identity',
                'fields' => [],
                'why'    => 'internal: this scan holds no identity for rule ' . (int) $ord
                    . ', so any finding it produced is recorded under a placeholder name',
            ];
        }

        return ['findings' => $findings, 'candidates' => $candidates, 'bytes' => $bytes,
                'contexts' => $r['contexts'], 'problems' => $problems,
                // Non-zero means a rule kind produced one identity twice. With
                // the locus discriminator in place nothing legitimate does, so
                // this is a bug report rather than a fact about the project.
                'collapsed' => $collapsed, 'why' => null];
    }

    /**
     * Everything a scan needs to know before it reads its first record: which
     * rules are live, where each one lives, what has to be read, and which rule
     * problems are already known. Computed once per scan.
     *
     * Lifted out of scanProject() whole in 1.7.0. It is the half that does not
     * depend on the data, so a caller that scans a project in slices computes it
     * once rather than per slice — and it is the half whose failures are FATAL,
     * which is why they come back as one 'fatal' string rather than being mixed
     * in with per-record notes.
     *
     * @return array{fatal: ?string, nothingToScan: bool, live: array, hostFields: array,
     *               ownership: array<string,?string> field => owning instrument, NULL when it
     *               could not be placed - the fail-closed encoding ScanService reads as
     *               unknown ownership and mayStart() refuses on,
     *               readSet: array, dupes: array, unconf: array,
     *               gateable: array<int,array<string,true>> rule index => the host forms
     *               whose contexts the started-form gate may drop for that rule,
     *               formStatusFields: array<string,string> gateable host form => its status field,
     *               formStartFields: array<string,string[]> gateable host form => the fields whose
     *               value says it was started (its status field, then the read fields it owns)}
     *
     * ONCE PER REQUEST, WHEN THE CALLER HANDS OVER A MEMO (M12). The durable scan
     * rebuilt this whole plan - rule discovery, the dictionary, host resolution,
     * the capability probes, every cross-project rights check - twice in every
     * work request, once for the work and once for the status it returns.
     * $opts['memo'] is an \ArrayObject the CALLER owns: ScanService makes one
     * per instance, and an instance lives for one request. The memo is never
     * kept on this object, because a cron process runs many projects through
     * one module instance and a plan from an earlier pass would describe rules
     * and rights as they were then. The key is every input that changes the
     * answer: the project, the group scope, the value ceiling, the rights gate,
     * and the reader - crossRefused, the instrument rights and the disclosure
     * cap are all about who is asking.
     *
     * $opts['capabilities']: ScanCapabilities::all() as the caller already
     * probed it in this request, so the probes are not sent twice (P1).
     */
    private function scanPlan($pid, array $opts = [], $dagFilter = null)
    {
        $memo = (isset($opts['memo']) && $opts['memo'] instanceof \ArrayObject) ? $opts['memo'] : null;
        if ($memo === null) return $this->buildScanPlan($pid, $opts, $dagFilter);
        $key = json_encode([(int) $pid, $dagFilter === null ? null : (string) $dagFilter,
            isset($opts['valueCeiling']) ? (string) $opts['valueCeiling'] : null,
            !empty($opts['enforceFormRights']), $this->currentUsername()]);
        if (!isset($memo[$key])) $memo[$key] = $this->buildScanPlan($pid, $opts, $dagFilter);
        return $memo[$key];
    }

    /** @see scanPlan() - the plan itself, built every time it is called. */
    private function buildScanPlan($pid, array $opts = [], $dagFilter = null)
    {
        $out = ['pid' => $pid, 'fatal' => null, 'nothingToScan' => false, 'live' => [], 'hostFields' => [],
                // Empty means "no started-form gate": every designated context is
                // kept, which is the behaviour before R2O-2. Filled at the end.
                'gateable' => [], 'formStatusFields' => [], 'formStartFields' => [],
                // Present on EVERY return, including the refusals. An entitlement
                // key that exists only on the success path is one a caller reads as
                // "no forms" on the path where it should read as "no answer".
                'ownership' => [],
                'crossRefused' => [], 'crossUnread' => [], 'crossLookups' => [],
                'readSet' => [], 'dupes' => [], 'unconf' => [],
                // A value that is a Missing Data Code is no candidate for a
                // @UVUNIQUE duplicate: ten records marked "UNK" share no ID.
                'missingCodes' => $this->missingDataCodes($pid),
                // How @UVUNIQUE keys each field's values (numberMarkOf): the
                // fields that hold numbers, field => decimal mark.
                'numberMarks' => array_filter($this->lookupMarks($pid, array_keys($this->dataDictionary($pid) ?: []))),
                // The fields whose every @UVUNIQUE rule and branch keeps letter
                // case, field => true (filled in below, once the rules are read).
                'uniqueExact' => [],
                // Resolved once: the policy cannot change mid-scan, and the
                // identifier set is a dictionary read we already paid for.
                // The PROJECT's setting, capped by what THIS READER is entitled
                // to see. The project decides how disclosing the report may be;
                // the reader's own export rights decide how disclosing it
                // actually is. Neither can raise the other.
                'valueMode' => self::valueFloor($this->scanValueMode($pid),
                                                // 'locations' when the caller says nothing. This
                                                // read 'raw' - the MOST disclosing option - as the
                                                // default of the one expression whose job is to cap
                                                // disclosure, and beside a valueRank() whose docblock
                                                // states that anything unrecognised ranks LOWEST. Both
                                                // pages pass a ceiling, so it was latent; a caller that
                                                // wants raw can say so.
                                                isset($opts['valueCeiling']) ? $opts['valueCeiling'] : 'locations'),
                'identifiers' => $this->projectIdentifierFields($pid),
                // 'none' is the log mode for sites where the RECORD ID is itself
                // identifying. The report is a new surface and must not
                // contradict the posture the audit already applies.
                'hashRecordIds' => ($this->logMode($pid) === 'none'),
                // So reportValue() can withhold a finding's value on the field
                // whose value IS the record id the column above hashes.
                'recordIdField' => $this->recordIdFieldOf($pid)];

        // What this installation can actually support, and therefore what a run
        // on it is ALLOWED TO CLAIM. ScanCapabilities computed this cap from the
        // start and nothing consulted it, so the module contained a correct,
        // tested implementation of its own central safety property and did not
        // call it - which is worse than not having written it, because the suite
        // reported the property as covered.
        try {
            $out['policy'] = ScanCapabilities::policy(ScanCapabilities::all($this, $pid,
                (isset($opts['capabilities']) && is_array($opts['capabilities'])) ? $opts['capabilities'] : []));
        } catch (\Throwable $e) {
            // A probe layer that fails cannot license a claim. Cap at the
            // weakest coverage rather than assume the strongest.
            $out['policy'] = ['mayScan' => true, 'maxCompletion' => 'manifest-complete',
                              'incremental' => false,
                              'limits' => ['the capabilities of this installation could not be '
                                           . 'established: ' . get_class($e)]];
        }

        // Rule DISCOVERY is a read like any other and can throw: a settings
        // backend failure used to escape scanProject entirely, so the operator
        // got a PHP error page instead of a scan result and nothing recorded
        // that the project had not been examined (M-03).
        try {
            $rules = $this->getRules($pid);
        } catch (\Throwable $e) {
            $out['fatal'] = 'the rule list could not be read: ' . get_class($e);
            return $out;
        }
        if (!is_array($rules)) $rules = [];

        // The dictionary is load-bearing twice: annotation rules are READ from
        // it, and every rule has to be located on an instrument before it can be
        // evaluated at all. Establish that independently of whether any rule
        // survived — a failed read that left one settings rule standing used to
        // scan that rule and report 'complete' while every annotation rule had
        // silently vanished from the list (H-05).
        if (!$this->dataDictionary($pid)) {
            $out['fatal'] = 'the project data dictionary could not be read, so the rule list is '
                . 'incomplete and no rule can be located on an instrument';
            return $out;
        }
        if (!$rules) { $out['nothingToScan'] = true; return $out; }

        $live = [];
        $unconf = [];   // dedupe rule-problem notes by rule+why (config errors AND runtime)
        foreach ($rules as $i => $r) {
            if (!empty($r['configError'])) {
                // A config-broken rule enforces NOTHING. Surface it in the scan
                // rather than imply a clean project — the module's rule is that
                // nothing fails silently (M-05). It also shows on the data-entry
                // form, but a scan operator would not otherwise know a rule is inert.
                $unconf[$i . '|configError'] = [
                    'rule'   => $i + 1,
                    'fields' => (isset($r['fields']) && is_array($r['fields'])) ? $r['fields'] : [],
                    'why'    => 'configuration error — this rule validates nothing: ' . $r['configError'],
                ];
                continue;
            }
            $live[$i] = $r;
        }
        $out['live']   = $live;
        $out['unconf'] = $unconf;
        $out['allRules'] = $rules;   // the list the ordinals in findings refer to
        // The durable scan re-reads a duplicate group's values and compares
        // them as the scan grouped them (ScanService::comparableValue): letter
        // case counts only on a field whose every unique rule and branch says so.
        //
        // An "also" field's values join the groups of the rule that searches
        // it, so it is compared the way that rule compares, and a field joined
        // by "also" to a field that ignores case ignores it too: one group can
        // hold values of both, and the re-read must compare them alike.
        $exact = [];
        $folded = [];
        $joined = [];
        foreach ($live as $r) {
            if (($r['type'] ?? null) !== 'unique' || !isset($r['fields']) || !is_array($r['fields'])) continue;
            $variants = (isset($r['branches']) && is_array($r['branches'])) ? $r['branches'] : [$r];
            foreach ($variants as $b) {
                $also = (isset($b['uniqueAlso']) && is_array($b['uniqueAlso'])) ? $b['uniqueAlso'] : [];
                foreach (array_merge($r['fields'], $also) as $f) {
                    if (empty($b['caseSensitive'])) $folded[$f] = true; else $exact[$f] = true;
                }
                foreach ($r['fields'] as $f) {
                    foreach ($also as $a) { $joined[$f][$a] = true; $joined[$a][$f] = true; }
                }
            }
        }
        for ($todo = array_keys($folded); $todo; ) {
            $f = array_pop($todo);
            foreach (isset($joined[$f]) ? array_keys($joined[$f]) : [] as $g) {
                if (!isset($folded[$g])) { $folded[$g] = true; $todo[] = $g; }
            }
        }
        $out['uniqueExact'] = array_diff_key($exact, $folded);
        if (!$live) { $out['nothingToScan'] = true; return $out; }

        $dupes = [];
        foreach (self::duplicateFields($rules) as $f) $dupes[$f] = true;
        $out['dupes'] = $dupes;

        // WHERE each rule lives, computed once. A rule whose field cannot be
        // located on any instrument is not evaluated in some arbitrary context
        // and hoped for — it is reported, because a guessed location produces
        // confident nonsense rather than a near miss (H-02).
        $hostFields = [];
        foreach ($live as $i => $r) {
            $h = $this->ruleHostForms($r, $pid);
            if ($h['unknown']) {
                $unconf[$i . '|unlocatable'] = ['rule' => $i + 1, 'fields' => $h['unknown'],
                    'why' => 'the instrument that owns this rule\'s field(s) could not be determined from the '
                           . 'data dictionary, so there is no context in which to check them — the field is not scanned'];
            }
            $hostFields[$i] = $h['forms'];
        }
        // An "also" field the dictionary places on no instrument is left out
        // of the rule's lookups (uniqueLookups, below), and the scan says so.
        foreach ($live as $i => $r) {
            if (($r['type'] ?? null) !== 'unique') continue;
            $also = [];
            foreach (self::uniqueVariants($r) as $b) {
                foreach ((isset($b['uniqueAlso']) && is_array($b['uniqueAlso'])) ? $b['uniqueAlso'] : [] as $a) $also[(string) $a] = true;
            }
            $h = $also ? $this->ruleHostForms(['fields' => array_keys($also)], $pid) : ['unknown' => []];
            if ($h['unknown']) {
                $unconf[$i . '|also-unlocatable'] = ['rule' => $i + 1, 'fields' => $h['unknown'],
                    'why' => 'the instrument that owns this rule\'s "also" field(s) could not be determined from the '
                           . 'data dictionary, so values saved there are not looked at by the scan'];
            }
        }
        // A rule whose instrument is designated for NO event can never run.
        // hostContextsFor() drops every context for an unmapped form, so the
        // rule yields no violation - and, because nothing ever reached the
        // evaluator, no rule problem either. The scan then reports the project
        // complete and clean while the rule has enforced nothing since the day
        // it was written. Every OTHER unevaluable condition in this module says
        // so out loud; this was the one that did not.
        //
        // Fails OPEN. A null map - a classic project, or a build that does not
        // expose the mapping - makes NO claim, because wrongly declaring an
        // instrument uncollected would suppress a rule that works. Only a
        // mapping that actually names instruments is trusted, and then only to
        // say that a form it does not name collects nothing.
        $mapped = $this->mappedInstruments($pid);
        if (is_array($mapped)) {
            foreach ($hostFields as $i => $forms) {
                $orphanForms = [];
                $orphanFields = [];
                foreach ($forms as $form => $ownFields) {
                    if (isset($mapped[$form])) continue;
                    $orphanForms[] = (string) $form;
                    foreach ((array) $ownFields as $f) $orphanFields[] = (string) $f;
                }
                if (!$orphanForms) continue;
                // Per HOST, not per rule: a rule spanning two instruments where
                // only one is unmapped is still checked on the other, and saying
                // the whole rule was skipped would be the opposite error (H-02).
                $unconf[$i . '|unmapped-instrument'] = [
                    'rule'   => $i + 1,
                    'fields' => $orphanFields,
                    'why'    => 'instrument ' . implode(', ', $orphanForms) . ' is not designated to any event, '
                              . 'so no record can hold these field(s) and the rule was NOT evaluated on them. '
                              . 'Assign the instrument to an event, or move the rule to an instrument that is.',
                ];
            }
        }
        // DESIGN RIGHTS ARE NOT INSTRUMENT RIGHTS.
        //
        // The scan reads through REDCap::getData() with a project id and no
        // user, so REDCap's own per-instrument access control never runs. A
        // designer with No Access to an instrument therefore received that
        // instrument's findings - and, on a project that had opted into raw
        // values, its values. The 1.8.x export-rights ceiling caps how much of a
        // value is shown; it says nothing about which instruments a reader may
        // see at all, and the docblock that introduced it names this exact case.
        //
        // Enforced by DROPPING the rule before it is ever evaluated, not by
        // filtering rows afterwards. A row filter still leaks: the finding count
        // moves, the instrument label appears in a summary, an aggregate over
        // the project reveals how many problems live on a form the reader cannot
        // open. A rule that never runs produces nothing to leak.
        //
        // The entitlement set is every host instrument PLUS every instrument
        // owning a field the rule references, because a `when` or `assert`
        // operand read from a barred form decides the verdict just as directly
        // as the field being checked.
        //
        // Opt-in per call. Only a request made BY a user can be scoped to that
        // user, and scanProject() is also reachable with no user context at all;
        // both pages pass it, which tests/scan_page_php.php asserts.
        if (!empty($opts['enforceFormRights'])) {
            // NULL means the rights could not be established, and a right that
            // cannot be read cannot clear an instrument - same posture as
            // mustRedact() and disclosableFields(), for the same reason.
            $formRights = $this->userFormRights($pid);
            $ddForms = $this->dataDictionary($pid);
            $whyUnreadable = 'your per-instrument rights could not be established, so no instrument '
                . 'could be cleared for reading and this rule was NOT evaluated - a right that cannot '
                . 'be read cannot grant access. Ask an administrator to check your user rights.';
            foreach ($live as $i => $r) {
                // TWO questions, because they have different answers.
                //
                // A rule's CONDITION is rule-wide: a `when` or `assert` operand
                // read from a barred instrument decides every host's verdict, so
                // one barred operand makes the whole rule unevaluable.
                //
                // A rule's HOSTS are independent. Annotation rules pool by
                // configuration, so one rule routinely spans several
                // instruments; barring the rule outright would throw away the
                // hosts the reader is perfectly entitled to, which is the
                // over-broad half of H-02. Bar the host, keep the rest.
                $own = [];
                foreach ((isset($r['fields']) && is_array($r['fields'])) ? $r['fields'] : [] as $f) {
                    $own[(string) $f] = true;
                }
                $condBarred = [];
                foreach (self::ruleRefFields($r) as $f) {
                    if (isset($own[$f])) continue;                  // a host field, handled below
                    if (!isset($ddForms[$f]['form_name']) || $ddForms[$f]['form_name'] === '') continue;
                    $form = (string) $ddForms[$f]['form_name'];
                    if (!self::mayReadForm($formRights, $form)) $condBarred[$form] = true;
                }
                $hostBarred = [];
                foreach ($hostFields[$i] as $form => $_) {
                    if (!self::mayReadForm($formRights, (string) $form)) $hostBarred[(string) $form] = true;
                }
                if (!$condBarred && !$hostBarred) continue;

                $whole = (bool) $condBarred || count($hostBarred) >= count($hostFields[$i]);
                $named = array_keys($condBarred ? $condBarred : $hostBarred);
                $unconf[$i . '|no-instrument-rights'] = [
                    'rule'   => $i + 1,
                    'fields' => array_keys($own),
                    'why'    => $formRights === null ? $whyUnreadable
                        : ($condBarred
                            ? 'this rule\'s condition reads instrument ' . implode(', ', $named)
                              . ', which you do not have access to, so the rule was NOT evaluated '
                              . 'anywhere. Nothing about that instrument appears in this report.'
                            : ($whole
                                ? 'you do not have access to instrument ' . implode(', ', $named)
                                  . ', which this rule checks, so it was NOT evaluated. Nothing about '
                                  . 'that instrument appears in this report.'
                                : 'you do not have access to instrument ' . implode(', ', $named)
                                  . ', so this rule was checked only on the instrument(s) you can '
                                  . 'open. Nothing about that instrument appears in this report.')),
                ];
                if ($whole) {
                    unset($live[$i], $hostFields[$i]);
                    continue;
                }
                foreach (array_keys($hostBarred) as $form) unset($hostFields[$i][$form]);
            }
        }

        // Reassigned, because $live and $hostFields are copies taken above and
        // the gate may have removed entries from both. Leaving the earlier
        // assignment standing would evaluate rules the gate had just barred.
        $out['live']       = $live;
        $out['hostFields'] = $hostFields;
        $out['unconf']     = $unconf;
        // ONE LIST, ONE OWNER. The rule identities are derived HERE, from the
        // final $live, and keyed identically to it by construction. They used
        // to be derived a second time in durableScanContext() from a copy that
        // had been re-indexed on the way, so the two disagreed about which rule
        // an ordinal named - and since rule_source_id is hashed into the
        // finding identity, the disagreement was silent and permanent. A second
        // derivation of the same thing is a second thing that can drift.
        $out['ruleIds'] = Scan\ScanPlanner::identifyAll($live);
        $out['uniqueLookups'] = $this->uniqueLookupSpecs($live, $hostFields, $dupes, $pid);
        if (!$live) {
            // Every rule barred is not "nothing to scan": the rule problems above
            // are the report, and they must survive. nothingToScan short-circuits
            // to a complete status, which unconfigurable[] then keeps off green.
            $out['nothingToScan'] = true;
            return $out;
        }

        // Everything the evaluation needs to read: rule fields + when/assert
        // refs + composite unique keys.
        $readSet = [];
        foreach ($live as $r) {
            if (TemporalRules::extended($r)) {
                foreach ($this->temporalReadFields([$r], $pid) as $f) $readSet[$f] = true;
            }
            foreach ($r['fields'] as $f) $readSet[$f] = true;
            foreach (ModeRegistry::refFields($r) as $f) $readSet[$f] = true;
            foreach ($this->requiredBranchingRefs($pid, $r) as $f) $readSet[$f] = true;
            // the fields a rule returns to, when the scan compares them (RNG-1)
            if ($this->returnsComparedByScan($r, $pid)) {
                foreach (ModeRegistry::returnFields($r) as $f) $readSet[$f] = true;
            }
        }
        // A project-scope unique rule cannot be evaluated from a DAG-confined
        // scan: the scan reads one group, so a value duplicated ACROSS groups is
        // invisible and the rule reports nothing. The live unique-check endpoint
        // queries the whole project and WOULD flag it, so the two disagree and
        // the scan is the one issuing certificates. Every other unevaluable
        // condition in this module lands in 'unconfigurable'; this one was
        // silent, which is the one outcome the contract forbids.
        //
        // @UVEXISTS has the same blind spot from the other side: a value saved
        // only in another group reads "not found" from a group-confined read,
        // and whether this request's read is confined depends on who runs it.
        // A rule that looks across groups is reported the same way.
        if ($dagFilter !== null) {
            foreach ($live as $i => $r) {
                $mode = ModeRegistry::modeOfType(isset($r['type']) ? $r['type'] : '');
                if ($mode === 'unique') {
                    $scope = isset($r['uniqueScope']) ? strtolower((string) $r['uniqueScope']) : 'project';
                    if ($scope !== 'project') continue;      // 'dag' and 'event' ARE evaluable here
                    $unconf[$i . '|dag-scoped-unique'] = [
                        'rule' => $i + 1,
                        'fields' => (isset($r['fields']) && is_array($r['fields'])) ? $r['fields'] : [],
                        'why' => 'this rule requires values to be unique across the WHOLE project, but this scan '
                               . 'is confined to one Data Access Group - a duplicate in another group cannot be '
                               . 'seen from here, so the rule was NOT evaluated. Run the scan without a group '
                               . 'scope to check it.',
                    ];
                } elseif ($mode === 'exists' && !self::existsConfinedToDag($r)) {
                    $out['skip'][$i] = true;
                    $unconf[$i . '|dag-scoped-exists'] = [
                        'rule' => $i + 1,
                        'fields' => (isset($r['fields']) && is_array($r['fields'])) ? $r['fields'] : [],
                        'why' => 'this rule looks for the value across every Data Access Group, but this scan is '
                               . 'confined to one group - a value saved only in another group would read as not '
                               . 'found, so the rule was NOT evaluated. Run the scan without a group scope to '
                               . 'check it.',
                    ];
                }
            }
            $out['unconf'] = $unconf;
        }

        // A rule that searches another project is checked only when that project
        // answers the person running the scan (crossScanProblem); otherwise it is
        // reported, never silently passed. A branched rule is judged per branch:
        // a refused branch is marked (existsScanRefused, read by findingsExists)
        // and the branches that search this project, or a project that answers,
        // are still checked. crossRefused lists the projects that do not answer
        // this person: ScanService refuses them a stored run's results, which
        // may hold answers those projects gave someone else.
        // crossUnread is the part of crossRefused whose refusal rests on a
        // read that failed (review R3U-1): refused all the same, but a
        // moment rather than a decision, so ScanService does not call it
        // final.
        $crossProjects = [];
        $out['crossRefused'] = [];
        $out['crossUnread'] = [];
        foreach ($live as $i => $r) {
            if (isset($out['skip'][$i]) || ModeRegistry::modeOfType(isset($r['type']) ? $r['type'] : '') !== 'exists') continue;
            $branched = isset($r['branches']) && is_array($r['branches']) && $r['branches'];
            $refused = [];
            $kept = 0;
            foreach (self::existsParts($r) as $bi => $p) {
                if (empty($p['existsPid'])) { $kept++; continue; }
                $why = $this->crossScanProblem($pid, $p, $unread);
                if ($why === null) {
                    $crossProjects[(int) $p['existsPid']] = true;
                    $kept++;
                    continue;
                }
                $refused[$bi] = $why;
                $out['crossRefused'][(int) $p['existsPid']] = true;
                if ($unread) $out['crossUnread'][(int) $p['existsPid']] = true;
            }
            if (!$refused) continue;
            $fields = (isset($r['fields']) && is_array($r['fields'])) ? $r['fields'] : [];
            if (!$branched || $kept === 0) {
                $out['skip'][$i] = true;
                $unconf[$i . '|cross-project-exists'] = [
                    'rule' => $i + 1, 'fields' => $fields,
                    'why'  => 'this rule looks the value up in another project, but ' . reset($refused) . ', so the rule was NOT evaluated.',
                ];
            } else {
                // Kept beside the rule, not written into it: the rule identities
                // were derived from $live above and must not move.
                foreach ($refused as $bi => $why) {
                    $out['existsScanRefused'][$i][$bi] = 'this branch looks the value up in another project, but '
                        . $why . ', so it was NOT evaluated for the entries it applies to.';
                }
            }
            $out['unconf'] = $unconf;
        }
        $out['crossRefused'] = array_keys($out['crossRefused']);
        $out['crossUnread'] = array_keys($out['crossUnread']);
        // EVERY lookup into another project that a live rule makes, whoever
        // asks (reviews R3U-N1, R4-1, R4-2). A started run keeps this list and
        // status() asks the other project about each entry for the reader.
        // Not only the lookups this person is answered for: whoever presses
        // Continue evaluates with their own plan, so a run started by someone
        // project 300 refuses can still hold 300's answers. The list is the
        // rules', and every work pass is held to the rules by the fingerprint
        // (rule revisions cover existsPid and the fields searched), so no pass
        // can consult a lookup the list does not name. Per lookup, not per
        // project: under a "rights" agreement the forms a lookup reads decide
        // who it answers.
        $lookups = [];
        foreach ($live as $r) {
            if (ModeRegistry::modeOfType(isset($r['type']) ? $r['type'] : '') !== 'exists') continue;
            foreach (self::existsParts($r) as $p) {
                if (empty($p['existsPid'])) continue;
                $l = self::crossLookupOf($p);
                $lookups[json_encode($l)] = $l;
            }
        }
        ksort($lookups);
        $out['crossLookups'] = array_values($lookups);

        $out['readSet'] = $readSet;

        // A @UVEXISTS verdict depends on OTHER records. The change fence and the
        // catch-up re-check the records that changed, not the records whose
        // lookup a change elsewhere moved, so a run with such a rule can vouch
        // for the list it read and no more.
        $out['crossRecordLookups'] = false;
        foreach ($live as $i => $r) {
            if (!isset($out['skip'][$i]) && ModeRegistry::modeOfType(isset($r['type']) ? $r['type'] : '') === 'exists') {
                $out['crossRecordLookups'] = true;
                break;
            }
        }
        if ($out['crossRecordLookups'] && isset($out['policy']['maxCompletion'])
                && $out['policy']['maxCompletion'] === 'complete-through-fence') {
            $out['policy']['maxCompletion'] = 'manifest-complete';
            $out['policy']['limits'][] = '@UVEXISTS rules were checked against the values saved when each part of '
                . 'the scan ran; a value saved or removed in another record during the scan was not re-checked';
        }
        // Whatever the run can vouch for here, nothing re-opens it for a change
        // in the other project, so the report says so whatever its coverage.
        if ($crossProjects && isset($out['policy']) && is_array($out['policy'])) {
            $out['policy']['limits'][] = '@UVEXISTS rules that search project ' . implode(', ', array_keys($crossProjects))
                . ' read it as it stood when each part of the scan ran; changes saved there do not re-open this scan';
        }

        // WHICH INSTRUMENT OWNS EACH FIELD THE RUN WILL READ - derived from the
        // READ SET, and from nothing else.
        //
        // It used to be built in durableScanContext() by walking
        // $plan['hostFields'], which ruleHostForms() fills from $rule['fields']
        // alone. The read is $readSet: that list PLUS every field a `when` or
        // `assert` operand references PLUS every unique-composite "with" field.
        // ScanService turns this map into the entitlement set
        // ScanAuthorization::mayStart() is asked about, so the gate was being
        // asked where the RULES LIVE while getData was being asked for the
        // OPERANDS. A designer with explicit No Access to an instrument could
        // therefore start a scan that read it, and whose findings differed by
        // its values - reproduced on every rule kind (@UVALIDATE, @UVASSERT,
        // @UVREQUIRED, @UVUNIQUE, @UVCHOICES), on branch operands, and on both
        // configuration channels. mayStart()'s own docblock already said the
        // entitlement is "every form the run will read"; the caller was the half
        // that was wrong.
        //
        // DERIVED FROM $readSet, NOT FROM ruleRefFields(). That helper computes
        // the same three sources per rule and would give the same answer today -
        // which is the problem: a second derivation of the same set is a second
        // thing that can drift, the same reason ruleIds is derived once from the
        // final $live and taken from the plan thereafter. $readSet is the array
        // durableScanContext() hands to getData, so $readSet is the only honest
        // answer to "what does this run read".
        //
        // NULL means "could not be placed", and it is the fail-closed direction:
        // ScanService reads a null or empty form as unknown ownership and
        // mayStart() refuses the run rather than dropping the field from the set.
        // Nothing could set that flag before - hostFields only ever contains
        // forms that WERE determined - so that arm of the control was dead in
        // production. A dictionary that came back unreadable is the same answer:
        // a form that cannot be read cannot clear an instrument.
        //
        // AND THAT ARM IS NOW REACHABLE FOR A RULE'S OWN FIELD, which is a real
        // behaviour change and was argued both ways. The `n|unlocatable` note at
        // the top of this method already reports such a field by name and says
        // the field is not scanned - so the case for excluding it here is that
        // the rule is already refused. That case is FALSE: $readSet above is
        // built from every live rule's `fields` unconditionally, the unlocatable
        // note does not remove the rule from $live, and $fields at :3122 is
        // array_keys($plan['readSet']). The value IS read. A field that is read
        // and cannot be placed is a field whose access cannot be checked, and
        // refusing is the only answer consistent with the rest of this file. The
        // refusal names the fields (ScanAuthorization::mayStart) so it is a
        // diagnosis rather than an undiagnosable no.
        //
        // WITH enforceFormRights ON, this map now includes a host form the
        // narrowing gate removed from $hostFields at the barred-host block
        // above. Harmless today - durableScanContext never passes that flag and
        // scanProject has no production caller - but whoever re-wires that gate
        // has to decide whether the narrowed run should be entitled to the form
        // it narrowed away, and this is where the two meet.
        $ddOwn = $this->dataDictionary($pid);
        $ownership = [];
        foreach (array_keys($readSet) as $f) {
            if (!isset($ddOwn[$f]) && substr($f, -9) === '_complete') { $ownership[(string)$f] = substr($f, 0, -9); continue; }
            $ownership[(string) $f] =
                (is_array($ddOwn) && isset($ddOwn[$f]['form_name']) && $ddOwn[$f]['form_name'] !== '')
                    ? (string) $ddOwn[$f]['form_name'] : null;
        }
        $out['ownership'] = $ownership;

        // WHERE EACH HOST INSTRUMENT WAS STARTED (review R2O-2).
        //
        // Both scan reads ask for the record-id field (EE-2), and REDCap stores
        // a record-id row on every event the record has any data in. So every
        // such event became a context, and hostContextsFor() kept it whenever
        // the host form was designated there: an adverse-event form designated
        // on every visit produced a @UVREQUIRED finding on every visit without
        // an adverse event, and the project could never certify clean.
        //
        // The decision: a rule's host instrument counts in a context only where
        // it was STARTED there - its <form>_complete holds a value, or a field
        // the scan reads from that form holds one. That is when the form path
        // enforces the rule (on save) and what REDCap's own missing-value checks
        // look at. The record-id field is not evidence, because it is stored on
        // every event whatever was opened.
        //
        // ONLY FOR A RULE WHOSE VERDICT ITS HOST FORM DECIDES ALONE (review
        // R3G-1). The gate dropped every rule's contexts alike, so `ae_term`
        // required "when [had_ae]='1'", with had_ae on the visit form, was never
        // checked where the AE form was never opened - which is the one place
        // that rule exists for. The scan certified the record clean while the
        // save audit, re-checking the rule as a reverse dependant when the visit
        // form was saved, logged the violation; auditRule() says the two must
        // not disagree. The save audit does not gate: a dependant is evaluated
        // in every designated host context of the saved event, an extended rule
        // in every one of the record, and the rule's own condition decides. So
        // the scan keeps every designated context for a (rule, host) pair whose
        // condition, operand or field list (any branch) reads a field another
        // form owns, and for every extended rule, which reads other events or
        // instances by construction (a qualified reference, a binding, or a
        // record-wide unique scope). Its condition decides there, as it does on
        // save. A field the dictionary does not place counts as another
        // form's: a context kept in error is a finding someone can see, one
        // dropped in error is a finding nobody sees.
        //
        // Nor a rule on the record-id field itself: the record id is stored
        // wherever the record exists, so it is always entered, and gating it
        // would stop checking the record id of a record whose first instrument
        // was never saved (an import).
        //
        // Computed HERE, once per plan, never per record: the gate runs per
        // record per context, on projects with hundreds of thousands of
        // records. scanRecord() only looks the pair up.
        //
        // The status fields are added to the READ only, never to $readSet: the
        // read set keys the entitlement, and every host form is entitled
        // already through the rule's own fields. They are read only for the
        // host forms the gate consults, so a project with no gateable rule
        // reads no status field and does not ask for blanks.
        //
        // REDCap reports a never-started form's status as '0' unless getData is
        // asked for blanks (returnBlankForGrayFormStatus), and the scan must
        // ask, or every designated form looks started. The blank changes no
        // verdict, because no rule can read a status VALUE (review R3G-3):
        // every condition, operand and field list, plain or qualified, must
        // name a data-dictionary field, and REDCap's dictionary holds no
        // <form>_complete row, so such a rule is a configuration error. The
        // status fields a temporal rule puts in the read set are existence
        // markers, and AddressResolver asks only whether their row or instance
        // is there. tests/hosting_php.php pins each refusal. A rule kind that
        // ever reads a status value must be shown the '0' the form path sees,
        // not the blank this read asks for.
        $pkOwn = (string) $out['recordIdField'];
        $gateable = [];
        $statusFields = [];
        foreach ($hostFields as $i => $forms) {
            if (TemporalRules::extended($live[$i])) continue;
            // The forms owning what the rule's configuration reads. A @UVEXISTS
            // "in" field is read in OTHER records ('lookup'), so it says nothing
            // about this record's other forms - the save audit skips it too.
            $readsFrom = [];
            foreach (ModeRegistry::refFields($live[$i], ['cond', 'operand', 'fieldList'], ['lookup']) as $f) {
                $readsFrom[isset($ddOwn[$f]['form_name']) ? (string) $ddOwn[$f]['form_name'] : ''] = true;
            }
            foreach ($forms as $form => $own) {
                $form = (string) $form;
                if ($readsFrom && (count($readsFrom) > 1 || !isset($readsFrom[$form]))) continue;
                if ($pkOwn !== '' && in_array($pkOwn, (array) $own, true)) continue;
                $gateable[$i][$form] = true;
                $statusFields[$form] = $form . '_complete';
            }
        }
        $startFields = [];
        foreach ($statusFields as $form => $sf) $startFields[$form] = [$sf];
        foreach ($ownership as $f => $form) {
            $f = (string) $f;
            // An existence marker is owned by its form but is no field of it:
            // the form's status field already leads the list.
            if ($form === null || $f === $pkOwn || !isset($ddOwn[$f]) || !isset($startFields[$form])) continue;
            $startFields[$form][] = $f;
        }
        $out['gateable'] = $gateable;
        $out['formStatusFields'] = $statusFields;
        $out['formStartFields'] = $startFields;
        return $out;
    }

    /**
     * The getData fields of one scan read: the read set, the record-id field
     * (EE-2) and the host forms' status fields (R2O-2). ONE derivation for both
     * reads, so the legacy chunk read and the durable read cannot drift.
     */
    private static function scanReadFields(array $plan, $pk)
    {
        $fields = isset($plan['readSet']) && is_array($plan['readSet']) ? $plan['readSet'] : [];
        if ((string) $pk !== '') $fields[(string) $pk] = true;
        foreach (isset($plan['formStatusFields']) ? (array) $plan['formStatusFields'] : [] as $sf) {
            $fields[(string) $sf] = true;
        }
        return array_keys($fields);
    }

    /**
     * The getData parameters of one scan read, for both reads.
     *
     * returnBlankForGrayFormStatus is what makes the status fields worth
     * reading: without it REDCap reports a form nobody opened as '0'
     * (Incomplete), the same as one saved incomplete, and every designated
     * form looks started. It is asked for only when the plan carries status
     * fields, and it moves no verdict: no rule can read a status value (see
     * scanPlan(), R3G-3). A build that ignores the key returns '0' and the
     * scan keeps every designated context, which flags more rather than less.
     */
    private static function scanReadParams(array $plan, $pid, array $records, $pk)
    {
        $p = ['project_id' => $pid, 'return_format' => 'array',
              'records' => array_values($records), 'fields' => self::scanReadFields($plan, $pk),
              'exportDataAccessGroups' => true];
        if (!empty($plan['formStatusFields'])) $p['returnBlankForGrayFormStatus'] = true;
        return $p;
    }

    /**
     * Whether $form was STARTED in one context (R2O-2): its status field, or a
     * field the scan reads from it, holds a value in the context's OWN row.
     *
     * $gate is ['fields' => the plan's formStartFields for $form]. The row
     * comes from the record node as read, never from the context's 'values':
     * a repeat context's values are the event's base row with the instance
     * merged over it, so a status or value on the base row would pass for this
     * instance's own.
     *
     * A row that cannot be found keeps the context, because a context dropped
     * in error is a finding nobody sees. A row that does not carry a signal
     * field at all reads it as blank, as cleanRow() does: getData leaves a
     * blank out, and the read asked for every signal field.
     */
    private static function formStartedIn(array $ctx, array $gate)
    {
        $rec = (isset($ctx['node']) && is_array($ctx['node'])) ? $ctx['node'] : [];
        $evt = $ctx['event_id'];
        $rk = array_key_exists('repeatKey', $ctx) ? $ctx['repeatKey'] : null;
        if ($rk === null) {
            $row = isset($rec[$evt]) ? $rec[$evt] : null;
        } else {
            $inst = $ctx['instance'];
            $row = isset($rec['repeat_instances'][$evt][$rk][$inst])
                 ? $rec['repeat_instances'][$evt][$rk][$inst] : null;
        }
        if (!is_array($row)) return true;
        foreach ($gate['fields'] as $f) {
            if (!array_key_exists($f, $row)) continue;
            $v = $row[$f];
            if (is_array($v)) {
                // A checkbox arrives as code => '0'/'1' on every row that exists,
                // ticked or not. Only a ticked box is something somebody entered.
                foreach ($v as $c) if ((string) $c === '1') return true;
                continue;
            }
            if ($v !== null && trim((string) $v) !== '') return true;
        }
        return false;
    }

    /**
     * Evaluate every live rule against ONE record, handing findings to the sink.
     *
     * Lifted out of scanProject()'s chunk loop in 1.7.0, unchanged. Everything
     * it needs that outlives the record — the whole-project unique candidates
     * and the deduped rule problems — is threaded by reference, because both are
     * bounded by the RULE list rather than by the data.
     *
     * @return array{contexts: int, why: ?string}  'why' is set when the record
     *         could not be examined at all, which is reported, never assumed clean.
     */
    private function scanRecord(array $plan, $pid, $rec, array $node, FindingSink $sink,
                                array &$uniqueSeen, array &$unconf)
    {
        $this->temporalBegin($pid);
        $ctxAll = self::recordContexts($node);
        if (!$ctxAll) {
            // REDCap returned the record with no event row at all. There is
            // nothing to evaluate, and nothing that says the record is
            // clean — certifying it was the same silent skip as an
            // unreadable chunk, one step further down (H-05).
            return ['contexts' => 0,
                    'why' => 'record ' . $this->reportRecordId($plan, $rec)
                           . ' was returned with no data rows, so it was not checked'];
        }
        $recDag = self::dagOfRecordNode($node);
        // Resolution is a property of the CONTEXT, not of the rule that
        // happens to be asking. Computing it per rule re-derived the same
        // ownership map contexts x rules times (M-05).
        $resCache = [];
        $hostCache = [];    // host form (+ gated?) => its contexts in THIS record; rules share hosts
        foreach ($plan['live'] as $i => $r) {
            // Reported as not evaluated by scanPlan(); evaluating it anyway would
            // add findings the same report says it cannot vouch for.
            if (isset($plan['skip'][$i])) continue;
            $mode = ModeRegistry::modeOfType(isset($r['type']) ? $r['type'] : '');
            // What the next save would write to the rule's returned fields,
            // compared with what they hold (returnsStale).
            $cmpReturns = $this->returnsComparedByScan($r, $pid);
            foreach ($plan['hostFields'][$i] as $hostForm => $ownFields) {
                $onForm = array_fill_keys($ownFields, true);
                // Only where the host was STARTED (R2O-2), and only for a pair
                // scanPlan() named gateable: a rule that reads another form or
                // event, or one on the record-id field, keeps every designated
                // context and its own condition decides (R3G-1). A plan that
                // does not name the pair, or did not ask for the host's status
                // field, keeps every designated context, as before.
                $gate = null;
                if (isset($plan['gateable'][$i][$hostForm], $plan['formStatusFields'][$hostForm],
                          $plan['formStartFields'][$hostForm])) {
                    $gate = ['fields' => $plan['formStartFields'][$hostForm]];
                }
                $hk = $hostForm . ($gate === null ? '|all' : '|started');
                if (!isset($hostCache[$hk])) {
                    $hostCache[$hk] = $this->hostContextsFor($ctxAll, $hostForm, $pid, $gate);
                }
                foreach ($hostCache[$hk] as $ck => $ctx) {
                    if (!isset($resCache[$ck])) {
                        $resCache[$ck] = $this->contextResolution($ctx, array_keys($plan['readSet']), $pid);
                    }
                    $evaluatedRule = $r;
                    $evaluatedMode = $mode;
                    // The branch an extended rule was flattened to, when it had branches.
                    $preparedBranch = null;
                    if (TemporalRules::extended($r)) {
                        if ($this->temporalBudget->exhausted()) {
                            $unconf[$i.'|temporal-budget']=['rule'=>$i+1,'fields'=>$ownFields,'why'=>'Extended record evaluation budget exhausted; this record was not fully checked.'];
                            break;
                        }
                        if (!isset($temporalShape)) $temporalShape = $this->temporalShape($pid);
                        $prepared = $this->temporalPrepared($r, $temporalShape, $node, $this->temporalContext($ctx, $hostForm));
                        if ($prepared['problems']) {
                            $unconf[$i . '|temporal|' . $ck] = ['rule'=>$i+1, 'fields'=>$ownFields,
                                'why'=>'Extended validation unavailable at event ' . $ctx['event_id'] . ', instance ' . $ctx['instance'] . ': ' . implode(', ', $prepared['problems'])];
                            continue;
                        }
                        // One branch group's problem: that group is reported and
                        // the rest of the field is still judged.
                        if (!empty($prepared['groupProblems'])) {
                            $unconf[$i . '|temporal-group|' . $ck] = ['rule'=>$i+1, 'fields'=>$ownFields,
                                'why'=>'Extended validation unavailable at event ' . $ctx['event_id'] . ', instance ' . $ctx['instance'] . ' for part of this field: ' . implode('; ', $prepared['groupProblems'])];
                        }
                        $evaluatedRule = $prepared['rule'];
                        $evaluatedMode = ModeRegistry::modeOfType($evaluatedRule['type'] ?? '');
                        if (isset($prepared['branch'])) $preparedBranch = (int) $prepared['branch'];
                    }
                    if ($evaluatedMode === 'unique' && !isset($evaluatedRule['uniqueRecordResults'])) {
                        self::collectUniqueCandidates($uniqueSeen, $unconf, $evaluatedRule, $i, $ctx, $rec, $recDag, $plan['dupes'], $onForm, $resCache[$ck], $hostForm, $plan, $preparedBranch);
                        continue;
                    }
                    // Branches scanPlan found another project will not answer for
                    // this person: findingsExists reports them instead of asking.
                    if (isset($plan['existsScanRefused'][$i]) && isset($evaluatedRule['branches'])) {
                        foreach ($plan['existsScanRefused'][$i] as $bi => $why) {
                            if (isset($evaluatedRule['branches'][$bi])) $evaluatedRule['branches'][$bi]['existsScanRefused'] = $why;
                        }
                    }
                    // A scan request answers @UVEXISTS from one index per rule, not one
                    // whole-project read per record (findingsExists).
                    $f = $this->ruleFindings($evaluatedRule, $i, $ctx['values'], $plan['dupes'], $onForm, $pid, $rec, $ctx['event_id'], null, $resCache[$ck],
                        ['dag' => $recDag, 'existsIndex' => true, 'instance' => $ctx['instance']] + ($cmpReturns ? ['returns' => true] : []));
                    // A returned value out of date: REDCap does not run the save
                    // hook for a Data Import Tool import, so an imported or
                    // corrected measurement leaves the old z-score (RNG-1).
                    // Reported on the returned field; opening the form and
                    // saving it writes the current values.
                    if ($cmpReturns && isset($f['returns']) && is_array($f['returns'])) {
                        foreach ($this->returnsStale($evaluatedRule, $f['returns'], $ctx['values'], $pid) as $rf => $held) {
                            $f['invalid'][] = ['field' => $rf, 'value' => $held,
                                'type' => isset($evaluatedRule['type']) ? (string) $evaluatedRule['type'] : 'range', 'reason' => 'returned-stale'];
                        }
                    }

                    if ($preparedBranch !== null) {
                        foreach ($f['invalid'] as $k => $v) if (!isset($v['branch'])) $f['invalid'][$k]['branch'] = $preparedBranch;
                    }
                    foreach ($f['invalid'] as $v) {
                        // Computed ONCE, and compared with === false. A truthiness
                        // test here would turn a legitimate value of '0' into null.
                        $rv = self::reportValue($v, $plan);
                        $sink->violation([
                            'record' => $this->reportRecordId($plan, $rec), 'event_id' => $ctx['event_id'],
                            'instance' => $ctx['instance'], 'field' => $v['field'],
                            'type' => $v['type'], 'reason' => $v['reason'], 'rule' => $i + 1,
                            'value' => ($rv === false) ? null : $rv,
                            'valueWithheld' => ($rv === false),
                            // RAW, and never through reportValue(). The
                            // discriminator is part of the LOCATION; routing it
                            // through the value path would null it under a
                            // withholding policy, and the collision this exists
                            // to prevent would come back on exactly the privacy
                            // setting the module recommends.
                            'locus' => isset($v['locus']) ? (string) $v['locus'] : '',
                            // $hostForm, NOT $ctx['instrument']: that is null for
                            // every base row (:2297) and deliberately null for a
                            // repeating-EVENT context (:2320), which between them
                            // is most projects.
                            'instrument' => $hostForm, 'dag' => $recDag,
                        ] + (isset($v['branch']) ? ['branch' => (int) $v['branch']] : [])
                          // the day a today-relative @UVWINDOW part was judged against
                          + (isset($v['asOf']) ? ['asOf' => (string) $v['asOf']] : [])
                          // what a mode's findings carry for the scan (php/modes.json findingKeys)
                          + self::scanFindingKeys($v)
                          // A growth finding's reference, and its z-score only
                          // when the scan shows values raw, as the audit log
                          // does. The z-score gives the measurement back, and
                          // with the measurement and the sex it gives back the
                          // age, so a date of birth the identifier mode
                          // withholds as well.
                          + (isset($v['z']) && is_string($rv) && isset($plan['valueMode']) && $plan['valueMode'] === 'raw'
                              ? ['z' => (string) $v['z']] : [])
                          + (isset($v['reference']) ? ['reference' => (string) $v['reference']] : [])
                          // a broken rule saved with a reason (@UVJUSTIFY), counted apart from the open ones
                          + (!empty($v['justified']) && isset($v['justifiedBy'])
                              ? ['justified' => true, 'justifiedBy' => (string) $v['justifiedBy']] : []));
                        if (!empty($v['justified']) && isset($v['justifiedBy'])) $this->scanJustified++;
                    }
                    foreach ($f['unconfigurable'] as $u) {
                        $key = $i . '|' . $u['why'];
                        if (!isset($unconf[$key])) {
                            $unconf[$key] = ['rule' => $i + 1, 'fields' => $u['fields'], 'why' => $u['why']];
                        }
                    }
                }
            }
            // What the rule's values are looked for in, from this record.
            if (isset($plan['uniqueLookups'][$i])) {
                self::collectUniqueLookups($uniqueSeen, $plan['uniqueLookups'][$i], $i, $ctxAll, $rec, $recDag, $plan);
            }
        }
        return ['contexts' => count($ctxAll), 'why' => null];
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
    private static function scanHalt($deadline, $memCap, $now, $usage)
    {
        if ($deadline !== null && $now >= $deadline) return 'time';
        if ($memCap !== null && $usage >= $memCap) return 'memory';
        return null;
    }

    /**
     * PHP's memory_limit in bytes, or 0 when there is no limit or it cannot be
     * read. Shorthand suffixes are case-insensitive and BINARY (1M = 1048576),
     * per PHP's own ini parser; a bare number is already bytes, and -1 means
     * unlimited. Returning 0 for "unknown" is deliberate: the caller then
     * imposes no cap, because a guard that fires on a misparse would stop
     * healthy scans and report them as incomplete.
     */
    private static function memoryLimitBytes()
    {
        return self::parseByteSize((string) ini_get('memory_limit'));
    }

    /**
     * One PHP shorthand byte size in bytes; 0 for unlimited or unreadable.
     * Pure, and separate from memoryLimitBytes() so it can be tested directly:
     * ini_set('memory_limit', ...) REFUSES any value below current usage, so a
     * test that went through the ini would silently assert against whatever the
     * limit already was.
     */
    private static function parseByteSize($raw)
    {
        $raw = trim($raw);
        if ($raw === '' || $raw === '-1') return 0;
        if (!preg_match('/^(\d+(?:\.\d+)?)\s*([KMG])?$/i', $raw, $m)) return 0;
        $n = (float) $m[1];
        switch (isset($m[2]) ? strtoupper($m[2]) : '') {
            case 'G': $n *= 1024; // fall through
            case 'M': $n *= 1024; // fall through
            case 'K': $n *= 1024;
        }
        return (int) $n;
    }

    /**
     * Every value context of one record node: the plain event rows, plus each
     * repeat instance merged over its event row (a repeat row wins where both
     * carry a field — the same precedence readValues applies).
     */
    private static function recordContexts(array $recordNode)
    {
        $out = [];
        foreach ($recordNode as $k => $node) {
            if ($k === 'repeat_instances' || !is_array($node)) continue;
            $out[] = ['event_id' => $k, 'instance' => 1, 'instrument' => null,
                      // 'repeatKey' is the raw bucket this row came from: null for
                      // the event's base row, '' for a repeating EVENT instance,
                      // the form name for a repeating FORM instance. 'instrument'
                      // cannot carry that distinction — it is deliberately null for
                      // the repeating-event bucket (every form shares it), which
                      // makes a repeating-event row indistinguishable from a base
                      // row. hostContextsFor() needs to tell them apart to decide
                      // where a rule actually lives (H-02).
                      'repeatKey' => null,
                      'node' => $recordNode, 'values' => self::cleanRow($node)];
        }
        if (isset($recordNode['repeat_instances']) && is_array($recordNode['repeat_instances'])) {
            foreach ($recordNode['repeat_instances'] as $evt => $byInstr) {
                if (!is_array($byInstr)) continue;
                $base = (isset($recordNode[$evt]) && is_array($recordNode[$evt])) ? self::cleanRow($recordNode[$evt]) : [];
                foreach ($byInstr as $formKey => $byInst) {
                    if (!is_array($byInst)) continue;
                    foreach ($byInst as $inst => $row) {
                        if (!is_array($row)) continue;
                        $out[] = ['event_id' => $evt, 'instance' => $inst,
                                  // '' is the repeating-EVENT bucket: shared by
                                  // every form, so nothing is instrument-scoped.
                                  'instrument' => ($formKey === '' ? null : $formKey),
                                  'repeatKey' => $formKey,
                                  'node'   => $recordNode,
                                  'values' => array_merge($base, self::cleanRow($row))];
                    }
                }
            }
        }
        return $out;
    }

    /**
     * The instruments that HOST one rule, as form_name => [its fields], plus the
     * fields whose owning form could not be determined.
     *
     * A rule lives where its OWN fields live — not wherever the caller happened
     * to be standing. Evaluating a rule in an arbitrary context is not a
     * near-miss, it produces confident nonsense: a populated repeating field
     * reported blank because the base row was examined, a populated event-1
     * field reported blank in event 2, and the same rule declared both
     * unconfigurable and hard-violated for one record (H-02). The save audit's
     * reverse-dependency pass had the same defect in a different shape, logging
     * one copy of a base-form violation per unrelated repeat row (H-03), and the
     * unique aggregator inherited it too (H-04).
     *
     * A rule may legitimately span forms (a pooled rule over fields on two
     * instruments); each host is returned separately so the rule is evaluated
     * once per host, over that host's fields only.
     */
    private function ruleHostForms(array $rule, $pid)
    {
        $out = ['forms' => [], 'unknown' => []];
        $dd = $this->dataDictionary($pid);
        foreach ((isset($rule['fields']) && is_array($rule['fields'])) ? $rule['fields'] : [] as $f) {
            $form = ($dd && isset($dd[$f]['form_name']) && $dd[$f]['form_name'] !== '') ? $dd[$f]['form_name'] : null;
            if ($form === null) { $out['unknown'][] = $f; continue; }
            $out['forms'][$form][] = $f;
        }
        return $out;
    }

    /**
     * Of one record's contexts, the ones in which $form's fields actually live,
     * keys preserved so the caller can reuse a per-context resolution cache.
     *
     * Three questions, answered from the SAME signals resolveOne() uses so the
     * two can never disagree: is $form designated for this event at all; does
     * the EVENT repeat; does $form itself repeat here.
     *
     * A fourth, for the scan only: was $form STARTED here (R2O-2). $gate is
     * what formStartedIn() takes, and null asks nothing - the save audit
     * passes none, because a save speaks for the form being saved and its
     * reverse-dependency read does not carry status fields.
     */
    private function hostContextsFor(array $contexts, $form, $pid, ?array $gate = null)
    {
        $out = [];
        $shape = [];    // event id => ['repeats' => bool|null, 'eventRepeats' => bool, 'mapped' => bool]
        foreach ($contexts as $k => $ctx) {
            $evt = $ctx['event_id'];
            if (!isset($shape[$evt])) {
                $rec = (isset($ctx['node']) && is_array($ctx['node'])) ? $ctx['node'] : [];
                $byEvent = (isset($rec['repeat_instances'][$evt]) && is_array($rec['repeat_instances'][$evt]))
                    ? $rec['repeat_instances'][$evt] : null;
                $eventForms = $this->formsForEvent($pid, $evt);
                $repeating  = $this->repeatingFormsForEvent($pid, $evt, [$form]);
                $byMeta   = is_array($repeating) ? isset($repeating[$form]) : null;
                $byBucket = is_array($byEvent) ? array_key_exists($form, $byEvent) : null;
                $repeats = null;
                if ($byMeta === true || $byBucket === true) $repeats = true;
                elseif ($byMeta === false || $byBucket === false) $repeats = false;
                $shape[$evt] = [
                    // A NULL mapping means "cannot tell" (classic project, or a
                    // build without the API) and must fail OPEN, exactly as
                    // contextResolution's own off-event check does.
                    'mapped'       => ($eventForms === null) ? true : isset($eventForms[$form]),
                    'eventRepeats' => is_array($byEvent) && array_key_exists('', $byEvent),
                    'repeats'      => $repeats,
                ];
            }
            $s = $shape[$evt];
            if (!$s['mapped']) continue;                       // this form is not collected in this event
            $rk = array_key_exists('repeatKey', $ctx) ? $ctx['repeatKey'] : null;
            if ($s['eventRepeats']) {
                // Every form in a repeating event is instance-scoped; the base row
                // is folded into each instance, so evaluating it too would double
                // every finding.
                if ($rk !== '') continue;
            } elseif ($s['repeats'] === true) {
                if ($rk !== $form) continue;                   // only this form's own instances
            } else {
                if ($rk !== null) continue;                    // base row only
            }
            // Designated is not started. Only the scan asks (see the docblock).
            if ($gate !== null && !self::formStartedIn($ctx, $gate)) continue;
            $out[$k] = $ctx;
        }
        return $out;
    }

    /**
     * Resolution states for ONE scan context, computed with the SAME resolver
     * the form hooks and the save audit use. Previously the scan had its own,
     * weaker rule (ambiguous only, inferred from which fields happened to
     * appear in repeat rows), so it reported hard violations for data the save
     * path declared unconfigurable, and missed off-event references entirely
     * (H-04). Divergence here is a correctness bug by construction, so there is
     * now exactly one implementation.
     */
    private function contextResolution(array $ctx, array $fields, $pid)
    {
        $res = [];
        if (!$fields) return $res;
        $rec = (isset($ctx['node']) && is_array($ctx['node'])) ? $ctx['node'] : [];
        $evt = $ctx['event_id'];
        $inst = (int) ($ctx['instance'] ?: 1);
        $instrument = isset($ctx['instrument']) ? $ctx['instrument'] : null;

        $byEvent = null;
        if (isset($rec['repeat_instances'][$evt]) && is_array($rec['repeat_instances'][$evt])) {
            $byEvent = $rec['repeat_instances'][$evt];
        }
        $formOf = [];
        $dd = $this->dataDictionary($pid);
        if ($dd) foreach ($fields as $f) {
            if (isset($dd[$f]['form_name'])) $formOf[$f] = $dd[$f]['form_name'];
        }
        $eventForms = $this->formsForEvent($pid, $evt);
        $repeating  = $this->repeatingFormsForEvent($pid, $evt, array_values($formOf));

        foreach ($fields as $f) {
            if ($eventForms !== null && isset($formOf[$f]) && !isset($eventForms[$formOf[$f]])) {
                $res[$f] = 'missing';
                continue;
            }
            $r = self::resolveOne($f, $rec, $byEvent, $formOf, $repeating, $evt, $instrument, $inst);
            if ($r['state'] !== 'ok') $res[$f] = $r['state'];
        }
        return $res;
    }


    /** Drop empty values from a data row (mirrors readValues: missing == empty). */
    private static function cleanRow(array $row)
    {
        $out = [];
        foreach ($row as $f => $v) {
            if ($v === null || $v === '') continue;
            $out[$f] = is_array($v) ? $v : (is_string($v) ? $v : (string) $v);
        }
        return $out;
    }

    /**
     * Collect one context's candidate values for a unique rule into the
     * aggregate map. The group key mirrors findCollision's semantics: the
     * trimmed value + composite "with" values, widened by the scope (event id
     * for scope=event, the record's DAG for scope=dag). Branch rules resolve
     * their active branch against this context first.
     */
    private static function collectUniqueCandidates(array &$seen, array &$unconf, array $rule, $ruleIndex, array $ctx, $rec, $recDag, array $dupes, $onForm = null, array $resolution = [], $hostForm = null, array $plan = [], $branch = null)
    {
        // Every reference this aggregation consumes goes through the SAME
        // resolution the rest of the scan uses. Without it the composite key was
        // built by substituting '' for anything unresolvable, so two records whose
        // composite field lives on an independently repeating instrument — with no
        // defined pairing between their instances — collapsed to the same key and
        // were reported as duplicates of each other, with nothing said about why
        // (H-04). An undefined pairing is refused, never guessed.
        $refuse = function ($why, $suffix) use (&$unconf, $ruleIndex, $rule) {
            $unconf[$ruleIndex . '|unique-' . $suffix] = ['rule' => $ruleIndex + 1,
                'fields' => $rule['fields'], 'why' => $why];
        };
        $unresolved = function (array $ast) use ($resolution) {
            foreach (Logic::referencedFields($ast) as $ref) {
                $state = isset($resolution[$ref[0]]) ? $resolution[$ref[0]] : 'ok';
                if ($state !== 'ok') return [$state, $ref[0]];
            }
            return null;
        };
        // the plan's map of fields that hold numbers (conditionNumbers)
        $numbers = (isset($plan['numberMarks']) && is_array($plan['numberMarks'])) ? $plan['numberMarks'] : [];

        $cfg = $rule;
        if (isset($rule['branches']) && is_array($rule['branches'])) {
            $active = [];
            $else = null;
            foreach ($rule['branches'] as $bi => $b) {
                if (!isset($b['when']) || !is_string($b['when']) || $b['when'] === '') { $else = $bi; continue; }
                $p = Logic::parse($b['when']);
                if (empty($p['ok'])) {
                    // A branch condition that no longer parses means the value is not
                    // checked here — surface it rather than a silent skip (M-05).
                    $refuse('a unique-rule branch "when" cannot be evaluated — the value is not checked', 'branch-unparseable');
                    return;
                }
                if (($u = $unresolved($p['ast'])) !== null) {
                    $refuse('a unique-rule branch "when" condition ' . self::resolutionProblem($u[0], $u[1])
                        . ' No branch can be chosen, so the value is not checked here.', 'branch-unresolved');
                    return;
                }
                if (Logic::evaluate($p['ast'], $ctx['values'], Logic::BLANK_INERT, !empty($b['caseSensitive']), $numbers)) $active[] = $bi;
            }
            if (count($active) === 1) {
                $pick = $active[0];
            } elseif (!count($active) && $else !== null) {
                $pick = $else;
            } else {
                if (count($active) > 1) {
                    // Two branch conditions true at once — mirror the non-unique scan
                    // and the live client, which report this rather than guess (M-05).
                    $refuse('more than one unique-rule "when" is true for a record (branch conflict) — the value is not checked', 'branch-conflict');
                }
                return;
            }
            $b = $rule['branches'][$pick];
            unset($b['when']);
            $cfg = array_merge(['type' => 'unique', 'fields' => $rule['fields']], $b);
            $branch = $pick;
        }
        if (isset($cfg['when']) && is_string($cfg['when']) && $cfg['when'] !== '') {
            $p = Logic::parse($cfg['when']);
            if (empty($p['ok'])) {
                $refuse('the unique rule\'s "when" condition cannot be evaluated — the value is not checked', 'when-unparseable');
                return;
            }
            if (($u = $unresolved($p['ast'])) !== null) {
                $refuse('the unique rule\'s "when" condition ' . self::resolutionProblem($u[0], $u[1]), 'when-unresolved');
                return;
            }
            if (!Logic::evaluate($p['ast'], $ctx['values'], Logic::BLANK_INERT, !empty($cfg['caseSensitive']), $numbers)) return;
        }
        $with  = (isset($cfg['uniqueWith']) && is_array($cfg['uniqueWith'])) ? $cfg['uniqueWith'] : [];
        $scope = isset($cfg['uniqueScope']) ? $cfg['uniqueScope'] : 'project';
        // A composite key is only meaningful when every part of it was actually
        // read for THIS row. One unreadable part makes the whole tuple undefined.
        foreach ($with as $w) {
            $state = isset($resolution[$w]) ? $resolution[$w] : 'ok';
            if ($state !== 'ok') {
                $refuse('the unique rule\'s composite key ' . self::resolutionProblem($state, $w), 'with-unresolved');
                return;
            }
        }
        // A 'dag' scope and a record in no group: the bucket key below appends
        // (string) $recDag, which is '' for such a record, so the records in no
        // group form one group of their own and are compared with each other
        // only. That is what README says, what @UVEXISTS does and what the live
        // check and the audit do (findCollision, UNQ-1). Earlier releases of
        // the scan refused these records instead (M1), and the three paths
        // gave three answers.
        foreach ($rule['fields'] as $field) {
            if (isset($dupes[$field])) continue;
            if ($onForm !== null && !isset($onForm[$field])) continue;
            $state = isset($resolution[$field]) ? $resolution[$field] : 'ok';
            if ($state !== 'ok') {
                $refuse('the unique rule ' . self::resolutionProblem($state, $field), 'field-unresolved');
                continue;
            }
            $v = isset($ctx['values'][$field]) ? $ctx['values'][$field] : null;
            if ($v === null || is_array($v) || Logic::lookupTrim($v) === '') continue;
            if (self::isMissingCode($v, isset($plan['missingCodes']) ? $plan['missingCodes'] : [])) continue;
            // Collision-free, LOSSLESS composite key (L-01, L01-UTF8-COLLAPSE): a raw
            // byte in a value (a 0x1F separator, or an invalid-UTF8 byte from a Latin-1
            // import) must not let two DISTINCT tuples share a key and read as a false
            // duplicate. bin2hex encodes every byte injectively and '.' cannot appear
            // in hex output, so the joined key round-trips uniquely — unlike
            // json_encode with JSON_INVALID_UTF8_SUBSTITUTE, which collapsed distinct
            // invalid-UTF8 values to U+FFFD. Each value is its Logic::lookupKey, as
            // findCollision compares them (keeps the scan and the audit in agreement).
            //
            // The key also names its shape (uniqueShape): two branches of one
            // rule that build keys differently never share a group.
            $fold = empty($cfg['caseSensitive']);
            $marks = (isset($plan['numberMarks']) && is_array($plan['numberMarks'])) ? $plan['numberMarks'] : [];
            $keyParts = [(string) $ruleIndex, self::uniqueShape($with, $scope, $fold), $field,
                         Logic::lookupKey($v, $fold, isset($marks[$field]) ? $marks[$field] : null)];
            foreach ($with as $w) {
                $wv = (isset($ctx['values'][$w]) && !is_array($ctx['values'][$w])) ? Logic::lookupTrim($ctx['values'][$w]) : '';
                $keyParts[] = $wv === '' ? '' : Logic::lookupKey($wv, $fold, isset($marks[$w]) ? $marks[$w] : null);
            }
            if ($scope === 'event') { $keyParts[] = 'evt'; $keyParts[] = (string) $ctx['event_id']; }
            elseif ($scope === 'dag') { $keyParts[] = 'dag'; $keyParts[] = (string) $recDag; }
            $key = '';
            foreach ($keyParts as $kp) $key .= bin2hex($kp) . '.';
            $seen[$key][] = ['record' => (string) $rec, 'event_id' => $ctx['event_id'],
                             'instance' => $ctx['instance'], 'field' => $field, 'rule' => $ruleIndex + 1,
                             // Kept RAW here and filtered at emit time: the value
                             // is already inside $key, so this costs nothing, and
                             // the report policy lives where the plan is in scope.
                             // The REPORTABLE form, decided now rather than kept
                             // raw to the end. The key above already carries
                             // bin2hex of $v's lookup key, so holding the raw value too was
                             // roughly three times the bytes per candidate,
                             // retained project-wide - including in locations
                             // mode, where it can never be shown at all. On a
                             // @UVUNIQUE rule over a Notes field that was the most
                             // expensive thing in the scan.
                             'value' => self::reportValue(['field' => $field, 'value' => $v], $plan),
                             'instrument' => $hostForm, 'dag' => $recDag, 'scope' => (string) $scope]
                           + ($branch !== null ? ['branch' => (int) $branch] : []);
        }
    }

    /**
     * The part of a @UVUNIQUE group key that says how the key was built: the
     * composite "with" fields, the scope, and whether letter case counts. The
     * branches of one rule can build keys differently, and a value keyed one
     * way is no evidence about a value keyed another way: an exact "ABC" must
     * not meet a case-folded "abc", as findCollision would never pair them.
     */
    private static function uniqueShape(array $with, $scope, $fold)
    {
        return json_encode([array_map('strval', array_values($with)), (string) $scope, $fold ? 1 : 0]);
    }

    /** A unique rule's branches, or the rule itself when it has none. */
    private static function uniqueVariants(array $r)
    {
        return (isset($r['branches']) && is_array($r['branches']) && $r['branches']) ? $r['branches'] : [$r];
    }

    /**
     * rule index => what collectUniqueLookups files for a unique rule: its
     * checked fields (hosts, the ones scanned on an instrument the reader may
     * open and not claimed twice), its "also" fields, each field's
     * instrument, the shapes its branches key values by, and whether its own
     * fields are looked in too (hostSlots: a "when" or a branch decides which
     * records are checked, and findCollision searches the others anyway).
     * A rule with nothing to look in has no entry.
     */
    private function uniqueLookupSpecs(array $live, array $hostFields, array $dupes, $pid)
    {
        $dd = $this->dataDictionary($pid) ?: [];
        $out = [];
        foreach ($live as $i => $r) {
            if (($r['type'] ?? null) !== 'unique' || empty($hostFields[$i])) continue;
            $shapes = [];
            $also = [];
            foreach (self::uniqueVariants($r) as $b) {
                $scope = isset($b['uniqueScope']) ? (string) $b['uniqueScope'] : 'project';
                // Record scope compares the fields of one record, never another's.
                if ($scope === 'record') continue;
                $with = (isset($b['uniqueWith']) && is_array($b['uniqueWith'])) ? array_values($b['uniqueWith']) : [];
                $fold = empty($b['caseSensitive']);
                $shapes[self::uniqueShape($with, $scope, $fold)] = [$with, $scope, $fold];
                foreach ((isset($b['uniqueAlso']) && is_array($b['uniqueAlso'])) ? $b['uniqueAlso'] : [] as $a) {
                    if (isset($dd[$a]['form_name']) && $dd[$a]['form_name'] !== '') $also[(string) $a] = (string) $dd[$a]['form_name'];
                }
            }
            $gated = !empty($r['branches']) || (isset($r['when']) && is_string($r['when']) && $r['when'] !== '');
            if (!$shapes || (!$also && !$gated)) continue;
            $hosts = [];
            $formOf = $also;
            foreach ($hostFields[$i] as $form => $own) {
                foreach ((array) $own as $f) {
                    if (isset($dupes[$f])) continue;
                    $hosts[] = (string) $f;
                    $formOf[(string) $f] = (string) $form;
                }
            }
            if (!$hosts) continue;
            $out[$i] = ['hosts' => $hosts, 'also' => array_keys($also), 'formOf' => $formOf,
                        'shapes' => array_values($shapes), 'hostSlots' => $gated];
        }
        return $out;
    }

    /**
     * File one record's values for a unique rule's lookups: what the rule's
     * values are looked for in, read the way findCollision reads another
     * record. Every merged context (recordContexts, as collisionIn walks
     * them), every "also" field, and the rule's own fields too when a "when"
     * or a branch decides which records are checked ($spec['hostSlots']):
     * findCollision searches every other record whatever its own "when" says.
     * Filed under each shape the rule can build a key with, so a value only
     * meets values keyed the same way.
     *
     * Marked 'lookup': counted as one of the records sharing a value, never
     * reported itself, because the rule checks its own fields only where its
     * "when" holds. One entry per group and record, and none where the record
     * already holds a checked value of that group.
     *
     * $spec is scanPlan's uniqueLookups entry: hosts, also, formOf, shapes,
     * hostSlots.
     */
    private static function collectUniqueLookups(array &$seen, array $spec, $ruleIndex, array $ctxAll, $rec, $recDag, array $plan)
    {
        $marks = (isset($plan['numberMarks']) && is_array($plan['numberMarks'])) ? $plan['numberMarks'] : [];
        $codes = isset($plan['missingCodes']) ? $plan['missingCodes'] : [];
        $rec = (string) $rec;
        $done = [];
        foreach ($spec['shapes'] as list($with, $scope, $fold)) {
            // A record in no group shares a value with the other records in
            // none ('' below), as collectUniqueCandidates keys them (UNQ-1).
            $shape = self::uniqueShape($with, $scope, $fold);
            foreach ($ctxAll as $ctx) {
                $row = $ctx['values'];
                $tail = [];
                foreach ($with as $w) {
                    $wv = (isset($row[$w]) && !is_array($row[$w])) ? Logic::lookupTrim($row[$w]) : '';
                    $tail[] = $wv === '' ? '' : Logic::lookupKey($wv, $fold, isset($marks[$w]) ? $marks[$w] : null);
                }
                if ($scope === 'event') { $tail[] = 'evt'; $tail[] = (string) $ctx['event_id']; }
                elseif ($scope === 'dag') { $tail[] = 'dag'; $tail[] = (string) $recDag; }
                $rk = array_key_exists('repeatKey', $ctx) ? $ctx['repeatKey'] : null;
                foreach ($spec['hosts'] as $h) {
                    foreach ($spec['hostSlots'] ? array_merge([$h], $spec['also']) : $spec['also'] as $s) {
                        $v = isset($row[$s]) ? $row[$s] : null;
                        if ($v === null || is_array($v) || Logic::lookupTrim($v) === '') continue;
                        if (self::isMissingCode($v, $codes)) continue;
                        // The key collectUniqueCandidates builds for host $h, bin2hex-joined.
                        $key = '';
                        foreach (array_merge([(string) $ruleIndex, $shape, $h,
                                              Logic::lookupKey($v, $fold, isset($marks[$s]) ? $marks[$s] : null)], $tail) as $kp) {
                            $key .= bin2hex($kp) . '.';
                        }
                        if (isset($done[$key])) continue;
                        $done[$key] = true;
                        // This record's own checked value is in the group already.
                        if (isset($seen[$key]) && $seen[$key][count($seen[$key]) - 1]['record'] === $rec) continue;
                        // Where the value is stored, for the durable scan's re-read: a
                        // base-row value merged into a repeat instance's context lives
                        // in the base row.
                        $form = isset($spec['formOf'][$s]) ? $spec['formOf'][$s] : null;
                        $inst = ($rk === null || ($rk !== '' && $rk !== $form)) ? 1 : $ctx['instance'];
                        $seen[$key][] = ['record' => $rec, 'event_id' => $ctx['event_id'],
                                         'instance' => $inst, 'field' => (string) $s, 'rule' => $ruleIndex + 1,
                                         'lookup' => true, 'instrument' => $form, 'dag' => $recDag,
                                         'scope' => (string) $scope];
                    }
                }
            }
        }
    }

    // -- uniqueness (@UVUNIQUE): live endpoint + shared lookup ---------------

    /**
     * The durable scan's AJAX verbs.
     *
     * Thin on purpose. Everything that decides anything - the feature flags,
     * the schema health, the rights, the scope, the run's own state - lives in
     * ScanService, so this method reads a run id, calls one of four things, and
     * hands back what it said. A handler that made decisions would be a second
     * place those decisions live.
     *
     * THE RUN ID IS A LOCATOR, NEVER AN AUTHORISATION. It is cast to an integer
     * and bound to $project_id inside the service before any answer can
     * distinguish "no such run" from "not yours" - which is why every refusal
     * below shares one sentence.
     */
    private function scanAction($action, $project_id, $payload)
    {
        // A FATAL MUST NOT BECOME AN EMPTY 200.
        //
        // The try/catch below handles anything that is a Throwable, which is
        // most things - but not memory exhaustion, and not the request simply
        // running out of time. Those end the process with no output at all, and
        // the client then gets HTTP 200 with an empty body: a success status
        // over nothing. The pilot lost a round to exactly that, because an empty
        // 200 is indistinguishable from a broken client and gives nobody a
        // thread to pull.
        //
        // The shutdown handler cannot rescue the request. It can make the
        // failure SAY something, which is the difference between a bug report
        // and a shrug.
        $answered = false;
        register_shutdown_function(function () use (&$answered) {
            if ($answered) return;
            $e = error_get_last();
            if ($e === null) return;
            $fatal = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR];
            if (!in_array($e['type'], $fatal, true)) return;
            // The MESSAGE is not echoed: it names paths and can quote the
            // statement. The KIND is, because "ran out of memory" and "ran out
            // of time" need different answers and the caller can act on neither
            // if told nothing.
            $why = (stripos($e['message'], 'memory') !== false)
                 ? 'this scan ran out of memory partway through a batch; nothing from it was kept'
                 : 'this scan stopped unexpectedly partway through a batch; nothing from it was kept';
            if (!headers_sent()) header('Content-Type: application/json');
            echo json_encode(['ok' => false, 'stop' => 'fatal', 'why' => $why]);
        });

        try {
            $svc = new Scan\ScanService($this);
            $runId = (isset($payload['run_id']) && is_scalar($payload['run_id']))
                   ? (int) $payload['run_id'] : 0;

            if ($action === 'scan-start') {
                $r = $svc->start($project_id);
                $answered = true;
                return $r;
            }
            if ($runId <= 0) {
                $answered = true;
                return ['ok' => false, 'why' => Scan\ScanService::NO_RUN];
            }
            if ($action === 'scan-work') {
                $r = $svc->work($project_id, $runId, 'browser');
                $answered = true;
                return $r;
            }
            if ($action === 'scan-status') { $answered = true; return $svc->status($project_id, $runId); }
            if ($action === 'scan-cancel') { $answered = true; return $svc->cancel($project_id, $runId); }
            $answered = true;
            return ['ok' => false, 'why' => 'unknown action'];
        } catch (\Throwable $e) {
            $answered = true;
            // Never leaks the exception. A class name or a message from here can
            // describe the installation's schema and its database user, to a
            // caller who has just been told the answer is no.
            return ['ok' => false,
                    'why' => 'the validation scan could not be reached; ask an administrator to '
                           . 'check the module log'];
        }
    }
}
