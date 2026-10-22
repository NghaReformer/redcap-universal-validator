<?php

namespace INSPIRE\UniversalValidator\Scan;

/**
 * One stored finding, turned into the finding the report's renderers take.
 *
 * WHY THERE IS AN ADAPTER. The report layer (ScanColumns, MessageCatalog,
 * ScanDimensions) was written against the shape the legacy scan hands its sink:
 * `record`, `instrument`, `type`, `reason`, `rule`, `value`. A stored finding is
 * a row of uv_finding: `record_id_bin`, `host_form`, `check_type`, `reason_code`,
 * `rule_ord`, `value_bin`. Every name differs, and three of the differences are
 * not renames:
 *
 *   - the VALUE is a decision, not a column. Whether a reader may see it depends
 *     on what the run kept, whether it has expired, what THIS reader is allowed,
 *     and whether the field is an Identifier - none of which the renderer knows;
 *   - the RECORD ID may have to leave as a hash, by the formula the audit log
 *     already uses, so one record has one name in both places;
 *   - the RULE is named by source id, which outlives a reordering, and by an
 *     ordinal, which does not.
 *
 * So the mapping is written once, here, and the renderers stay ignorant of
 * storage. No SQL, no I/O, no clock: everything that varies arrives through the
 * constructor, and the same row under the same options always maps the same way.
 *
 * A ROW IS NEVER DROPPED. A row this class cannot map is an exception, not a
 * shorter report: a findings page that quietly loses the rows it does not
 * understand reads as a project with fewer problems.
 */
final class FindingRowMapper
{
    /**
     * What a stored row must carry, BY NAME. This list is the contract with the
     * store: wave 8 gives both stores a findings read that returns exactly these
     * keys, built with array_combine() over this constant, so that a column
     * added to one side and not the other fails at the seam and not in a cell.
     *
     * Not here, on purpose: `value_binary`, `arm_id`, `severity` and
     * `status_key` exist in the table and have no producer. Reading a column
     * nothing writes is branching on a default.
     */
    const INPUT_KEYS = [
        'finding_id', 'project_id', 'record_id_bin', 'event_id', 'instance',
        'host_form', 'field', 'rule_source_id', 'rule_ord', 'check_type',
        'reason_code', 'reason_bits', 'dag_key',
        'value_bin', 'value_len', 'value_truncated', 'value_expires_at',
        // Schema versions 3 and 5: the day a value was judged against, and a
        // growth finding's z-score and reference (MessageCatalog's notes).
        'as_of', 'z_score', 'growth_reference',
    ];

    /** How record ids leave this class. */
    const IDS_RAW    = 'raw';
    const IDS_HASHED = 'hashed';

    /**
     * What the scan stores in place of an Identifier field's value. Written by
     * UniversalValidator::reportValue(); a row holding exactly this holds no
     * participant data, and says so.
     */
    const IDENTIFIER_MARKER = '[identifier withheld]';

    /** A hasher that cannot answer still may not leak the id, or blank the cell. */
    const ID_UNAVAILABLE = '[record id unavailable]';

    /** The code a pooled reason is stored under once its parts are a bitmask. */
    const POOLED_CODE = 'pooled';

    /**
     * The axes a findings page may be narrowed by, column => the column's
     * width in bytes, in the order a page picks its key: the first axis a
     * page names is the key it walks (ScanStore::findings()). The group comes
     * last because a group reader's scope names it on every page, and inside
     * a group's run nearly every row is the group's, so it narrows least.
     */
    const PAGE_AXES = ['host_form' => 128, 'reason_code' => 64, 'check_type' => 32, 'dag_key' => 255];

    /**
     * What one findings page is confined to: the scope, then the filter, as
     * column => value. ONE ANSWER FOR BOTH STORES, so a scope one store
     * refuses is refused by the other in the same words.
     *
     * THE SCOPE IS MANDATORY (B5). A read path that can be called without a
     * confinement is one that will eventually be called without one, so an
     * absent `dag` key throws rather than defaulting to the whole project.
     * `dag` is the reader's Data Access Group as a finding stores it - the
     * group's UNIQUE NAME, the dag_key axis - or null for a reader confined to
     * no group. An empty or non-string name is refused too: '' is what "the
     * group could not be named" looks like, and reading it as "no group"
     * would widen the page to the project.
     *
     * The filter takes the PAGE_AXES keys; any other key is ignored rather
     * than interpolated, and an empty value is no filter. A value that is not
     * a scalar throws. A filter on the group that differs from the scope's
     * group cannot match a row, so it answers `none` and no statement is sent.
     *
     * @return array{axes: array<string,string>, none: bool}
     * @throws \InvalidArgumentException when the scope has no dag key, or a value is not text
     */
    public static function pageAxes(array $scope, array $filter)
    {
        if (!array_key_exists('dag', $scope)) {
            throw new \InvalidArgumentException('a findings page needs the reader\'s scope; '
                . 'the scope has no dag key, and the whole project is not a default');
        }
        $dag = $scope['dag'];
        if ($dag !== null && (!is_string($dag) || $dag === '')) {
            throw new \InvalidArgumentException('a findings page scoped to a group needs the group\'s '
                . 'unique name; an empty or non-text group would read as the whole project');
        }
        $axes = [];
        foreach (self::PAGE_AXES as $col => $width) {
            if (!array_key_exists($col, $filter) || $filter[$col] === null || $filter[$col] === '') continue;
            if (!is_scalar($filter[$col])) {
                throw new \InvalidArgumentException('a findings filter on ' . $col . ' must be text');
            }
            $axes[$col] = (string) $filter[$col];
        }
        if ($dag !== null) {
            if (isset($axes['dag_key']) && $axes['dag_key'] !== $dag) return ['axes' => $axes, 'none' => true];
            $axes['dag_key'] = $dag;
        }
        // In PAGE_AXES order whatever order the caller wrote them in, so the
        // key a page walks never depends on how a request spelled its filter.
        $ordered = [];
        foreach (self::PAGE_AXES as $col => $width) {
            if (isset($axes[$col])) $ordered[$col] = $axes[$col];
        }
        return ['axes' => $ordered, 'none' => false];
    }

    /**
     * What a snapshot row's kind may be: lower-case ASCII, at most 32 bytes
     * (uv_scan_dim.kind is VARCHAR(32)). The column compares under the
     * table's case-blind collation and the in-memory store by byte, so a kind
     * that differs only in case would be one kind in one store and two in the
     * other; lower case only, and the two agree.
     */
    const DIM_KIND = '/^[a-z][a-z0-9_-]{0,31}$/';

    /** uv_scan_dim.dim_key is VARBINARY(191), compared by byte in both stores. */
    const DIM_KEY_BYTES = 191;

    /** uv_scan_dim.label is TEXT and meta MEDIUMTEXT. */
    const DIM_LABEL_BYTES = 65535;
    const DIM_META_BYTES = 16777215;

    /** Whether $kind may name a snapshot row. See DIM_KIND. */
    public static function isDimensionKind($kind)
    {
        return is_string($kind) && preg_match(self::DIM_KIND, $kind) === 1;
    }

    /**
     * A dimensions() cursor, checked: null, or [kind, key] as a page's last
     * row gives them. Anything else throws, as a malformed scope does.
     *
     * @return array{0:string, 1:string}|null
     */
    public static function dimensionAfter($after)
    {
        if ($after === null) return null;
        if (!is_array($after) || count($after) !== 2 || !isset($after[0], $after[1])
                || !self::isDimensionKind($after[0]) || !is_scalar($after[1])) {
            throw new \InvalidArgumentException('a label snapshot page starts after a [kind, key] pair, or at the start');
        }
        return [(string) $after[0], (string) $after[1]];
    }

    /**
     * A label snapshot's rows, checked for ScanStore::writeDimensions(): ONE
     * ANSWER FOR BOTH STORES, as pageAxes() is for a page. Each row comes back
     * as ['kind', 'key', 'label', 'meta'] with the key as a string.
     *
     * @throws \InvalidArgumentException naming the first row that is not a
     *         snapshot row: a kind not of the form DIM_KIND, a key that is
     *         empty, not text or wider than its column, a label or meta that
     *         is not null and not UTF-8 text that fits its column, or a
     *         (kind, key) the call already named
     */
    public static function dimensionRows(array $rows)
    {
        $out = [];
        $seen = [];
        foreach (array_values($rows) as $i => $r) {
            $bad = function ($why) use ($i) {
                return new \InvalidArgumentException('label snapshot row ' . $i . ' ' . $why);
            };
            if (!is_array($r)) throw $bad('is not a row');
            $kind = isset($r['kind']) ? $r['kind'] : null;
            $key = isset($r['key']) ? $r['key'] : null;
            if (!self::isDimensionKind($kind)) throw $bad('has no kind of the form ' . self::DIM_KIND);
            if (is_int($key)) $key = (string) $key;
            if (!is_string($key) || $key === '' || strlen($key) > self::DIM_KEY_BYTES) {
                throw $bad('has no key of 1 to ' . self::DIM_KEY_BYTES . ' bytes');
            }
            // What the columns hold, checked here so the in-memory store refuses
            // what a strict server refuses: TEXT and MEDIUMTEXT, utf8mb4.
            foreach (['label' => self::DIM_LABEL_BYTES, 'meta' => self::DIM_META_BYTES] as $c => $max) {
                if (!isset($r[$c])) continue;
                if (!is_string($r[$c]) || strlen($r[$c]) > $max || !mb_check_encoding($r[$c], 'UTF-8')) {
                    throw $bad('has a ' . $c . ' that is not UTF-8 text of at most ' . $max . ' bytes');
                }
            }
            $id = $kind . "\0" . $key;
            if (isset($seen[$id])) throw $bad('names ' . $kind . ' ' . $key . ' a second time');
            $seen[$id] = true;
            $out[] = ['kind' => $kind, 'key' => $key,
                      'label' => isset($r['label']) ? $r['label'] : null,
                      'meta' => isset($r['meta']) ? $r['meta'] : null];
        }
        return $out;
    }

    /**
     * The check types whose finding IS an empty field. Every other kind is
     * inert on a blank (UniversalValidator::ruleFindings() skips it), so for
     * them a stored row with no value is a value nobody stored, not an empty
     * field. A list, not a switch: a type added later and not named here is
     * told "not kept", which claims nothing about the data.
     */
    const EMPTY_IS_THE_FINDING = ['required' => true];

    private $pid;
    private $now;
    private $hashed;
    private $hasher;
    private $recordIdField;
    private $stored;
    private $effective;
    private $identifiers;
    /**
     * The LAST record id hashed, and what it is shown as. One slot, not a map:
     * a record's findings arrive together, and a map keyed by every record
     * the mapper has seen is a second copy of the project's ids held for the
     * life of an export (85 MiB at 500,000 records). A record met again after
     * another costs one more HMAC.
     */
    private $lastId = null;
    private $lastShown = null;

    /**
     * @param array $opts
     *   pid           int, required. The project the report is FOR; a row from
     *                 any other project throws.
     *   now           'Y-m-d H:i:s', required, in the form and zone the store's
     *                 value_expires_at is written in. Compared as the purge
     *                 compares it. There is no default: a mapper that reads a
     *                 clock is not pure, and one that guesses can show a value
     *                 the policy says has expired.
     *   recordIds     'raw' or 'hashed', required. No default: the only one
     *                 that needs nothing else is raw, the most disclosing.
     *   hashRecordId  callable(string $id): ?string. REQUIRED when hashed. It is
     *                 INJECTED because the formula belongs to the audit log
     *                 (hash_hmac over the id, keyed by the module key and the
     *                 project) and Hmac::P_RECORD is a different formula: a
     *                 report hashed with that one names every record something
     *                 the log has never heard of.
     *   recordIdField the project's record-id field. REQUIRED when hashed: a
     *                 finding on that field carries the id as its value, and
     *                 a hashed Record cell beside a raw Value cell is no hash.
     *   valuesState   the run's values_state: what the scan KEPT.
     *   readerCeiling the most this reader may see. Defaults to the least.
     *   identifiers   field => true for Identifier fields, or null when the
     *                 flags could not be read, which withholds.
     */
    public function __construct(array $opts)
    {
        $pid = isset($opts['pid']) ? (int) $opts['pid'] : 0;
        if ($pid < 1) {
            throw new \InvalidArgumentException('a finding mapper needs the project it is mapping for');
        }
        $now = isset($opts['now']) ? (string) $opts['now'] : '';
        if (!preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $now)) {
            throw new \InvalidArgumentException('a finding mapper needs the time as Y-m-d H:i:s; '
                . 'without it an expired value cannot be told from a kept one');
        }
        // REQUIRED, like the clock. This defaulted to raw - the most disclosing
        // posture, beside a readerCeiling, valuesState and identifiers that all
        // fail closed - so a caller that forgot it on a log-values=none project
        // got the raw ids and no exception. There is no safe default to pick
        // instead: hashed needs a hasher the caller has to supply anyway.
        if (!isset($opts['recordIds'])) {
            throw new \InvalidArgumentException('a finding mapper needs the record-id posture, raw or hashed; '
                . 'there is no default, because the only one that needs nothing else is raw');
        }
        $ids = (string) $opts['recordIds'];
        if ($ids !== self::IDS_RAW && $ids !== self::IDS_HASHED) {
            throw new \InvalidArgumentException('record ids are shown raw or hashed; there is no "' . $ids . '"');
        }
        $hasher = isset($opts['hashRecordId']) ? $opts['hashRecordId'] : null;
        if ($ids === self::IDS_HASHED && !is_callable($hasher)) {
            // Not a fallback to Hmac, and not a fallback to raw. Either would
            // produce a full, plausible report with the wrong ids in it.
            throw new \InvalidArgumentException('hashed record ids need the hasher the audit log uses; none was given');
        }
        $idField = isset($opts['recordIdField']) ? (string) $opts['recordIdField'] : '';
        if ($ids === self::IDS_HASHED && $idField === '') {
            // Without it the mapper cannot tell which Value cells hold the id.
            throw new \InvalidArgumentException('hashed record ids need the record-id field, '
                . 'whose findings carry the id as their value; none was given');
        }

        $this->pid = $pid;
        $this->now = $now;
        $this->hashed = ($ids === self::IDS_HASHED);
        $this->hasher = $hasher;
        $this->recordIdField = $idField;
        $this->stored = ScanPolicy::floor(isset($opts['valuesState']) ? $opts['valuesState'] : null, 'raw');
        // THE READER CAN ONLY LOWER IT. floor() ranks an unknown mode lowest, so
        // a missing or misspelt ceiling shows locations only.
        $this->effective = ScanPolicy::floor($this->stored,
            isset($opts['readerCeiling']) ? $opts['readerCeiling'] : null);
        $flags = (isset($opts['identifiers']) && is_array($opts['identifiers'])) ? $opts['identifiers'] : null;
        if ($flags !== null) {
            // A MAP, field => true. A list of names ['a_val'] is keyed 0, 1, ...
            // and was read as a map naming no field, so an identifier-redacted
            // reader was shown the field it named. REDCap field names start
            // with a letter, so a numeric key can only be a list.
            foreach ($flags as $k => $unused) {
                if (!is_string($k)) {
                    throw new \InvalidArgumentException('identifiers is a map of field name => true; '
                        . 'a list of names would withhold nothing');
                }
            }
        }
        $this->identifiers = $flags;
    }

    /**
     * Refuse a run row this mapper was not built for. ScanReport calls it before
     * it says anything about the run.
     *
     * TWO FACTS THE REPORT TAKES FROM TWO PLACES. The heading's project and
     * valuesState come from the run row; the cells come from this mapper. A
     * report for this project could say "clean" from another project's run,
     * and a mapper told `raw` would show values under a heading that says the
     * run kept locations. The values state is compared as floor() reads it, so
     * a run row spelling the lowest mode another way ('none') is the same mode.
     *
     * @throws \RuntimeException         when the run is another project's
     * @throws \InvalidArgumentException when the run kept something else
     */
    public function checkRun(array $run)
    {
        $pid = isset($run['project_id']) ? (int) $run['project_id'] : 0;
        if ($pid !== $this->pid) {
            throw new \RuntimeException('a report for project ' . $this->pid . ' was handed the run of project '
                . ($pid ?: 'none') . '; refusing to report on it');
        }
        $kept = ScanPolicy::floor(isset($run['values_state']) ? $run['values_state'] : null, 'raw');
        if ($kept !== $this->stored) {
            throw new \InvalidArgumentException('the run kept "' . $kept . '" and this mapper was told "'
                . $this->stored . '"; build the mapper from the run row');
        }
    }

    /**
     * @param array $stored one row, keyed by INPUT_KEYS
     * @return array the finding ScanColumns renders, plus valueState, ruleSource,
     *               branch and findingId
     * @throws \InvalidArgumentException when a key is missing
     * @throws \RuntimeException         when the row belongs to another project
     */
    public function toFinding(array $stored)
    {
        $missing = [];
        foreach (self::INPUT_KEYS as $k) {
            // array_key_exists: most of these are legitimately NULL.
            if (!array_key_exists($k, $stored)) $missing[] = $k;
        }
        if ($missing) {
            throw new \InvalidArgumentException('a stored finding reached the report without: '
                . implode(', ', $missing));
        }
        if ((int) $stored['project_id'] !== $this->pid) {
            // The store already filters by project. This is the second lock on
            // the same door, because the first one is a WHERE clause somebody
            // will one day rewrite.
            throw new \RuntimeException('a finding from another project reached this report; refusing to show it');
        }

        $field = (string) $stored['field'];
        $value = $this->valueOf($stored, $field, (string) $stored['check_type']);

        return [
            'record'        => $this->recordOf((string) $stored['record_id_bin']),
            'dag'           => $stored['dag_key'],
            'event_id'      => $stored['event_id'],
            'instrument'    => (string) $stored['host_form'],
            'instance'      => $stored['instance'],
            'field'         => $field,
            'value'         => $value['value'],
            'valueWithheld' => $value['withheld'],
            'valueState'    => $value['state'],
            'type'          => (string) $stored['check_type'],
            'reason'        => self::reasonOf($stored),
            'rule'          => (int) $stored['rule_ord'],
            'ruleSource'    => (string) $stored['rule_source_id'],
            // The branch that judged it, which ScanDimensions::ruleOf() speaks
            // for (R3G-2); null on a rule without branches and on every row
            // stored before the branch was kept.
            'branch'        => ReasonCode::branchOf($stored['reason_code'], $stored['reason_bits']),
            'findingId'     => $stored['finding_id'],
            // The sink's own names for MessageCatalog's notes. A z-score gives
            // the measurement back, so it goes wherever the value cell may not
            // show the value.
            'asOf'          => $stored['as_of'],
            'z'             => $value['withheld'] ? null : $stored['z_score'],
            'reference'     => $stored['growth_reference'],
        ];
    }

    /**
     * What the Value cell may say. THE ORDER IS THE POLICY: each line answers
     * before any later line is allowed to look at the bytes.
     *
     * @return array{state:string, value:?string, withheld:bool}
     */
    private function valueOf(array $stored, $field, $type)
    {
        // 1. Nothing was stored. On a locations run that means nothing: the
        //    scan stores no length for a value it withholds, so empty and
        //    withheld are one row, and saying "blank" there would be a claim
        //    about data nobody recorded.
        //
        //    On a run that kept values it means the field was EMPTY only for a
        //    check whose finding is the empty field. This said "blank" for every
        //    type, and Scan\UniqueFinalizer writes its duplicates with no value
        //    column at all, so every @UVUNIQUE finding on such a run read as an
        //    empty field - the one thing a duplicate cannot be. The legacy
        //    report shows the duplicated value; this one cannot, and says so.
        if ($stored['value_len'] === null) {
            if ($this->stored !== 'locations' && isset(self::EMPTY_IS_THE_FINDING[$type])) {
                return self::cell('blank', false);
            }
            return self::cell('not-kept', true);
        }
        // 2. It was stored, and retention has removed it or is about to. The
        //    purge runs on a schedule; the date is the rule.
        if ($stored['value_bin'] === null) return self::cell('expired', true);
        $until = $stored['value_expires_at'];
        if ($until !== null && (string) $until <= $this->now) return self::cell('expired', true);

        // 3. It is there, and this reader may not have it.
        if ($this->effective === 'locations') return self::cell('withheld', true);

        $bin = (string) $stored['value_bin'];
        // 4. An Identifier. Either the scan already replaced it, or it kept the
        //    value and this reader is capped below raw. Flags that cannot be
        //    read withhold: "could not tell" is not "is not".
        if ($bin === self::IDENTIFIER_MARKER) return self::cell('identifier', true);
        //    The record id, when this report hashes record ids. A rule on the
        //    record-id field puts the id in the Value cell, beside a Record
        //    cell that hashed it. The hash is already in that Record cell, so
        //    withholding loses nothing; hashing a value that may have been cut
        //    at 255 bytes could name a record that does not exist.
        if ($this->hashed && $field === $this->recordIdField) return self::cell('identifier', true);
        if ($this->effective !== 'raw'
            && ($this->identifiers === null || !empty($this->identifiers[$field]))) {
            return self::cell('identifier', true);
        }

        // 5. The column is 255 BYTES, and the scan cuts at the byte. A value cut
        //    inside a multi-byte character ends in an invalid sequence that
        //    would fail the text test below for a reason that is ours, not the
        //    data's.
        $cut = !empty($stored['value_truncated']);
        if ($cut) $bin = self::wholeCharacters($bin);

        // 6. Not text. The scan replaces invalid bytes with a marker before it
        //    stores them, so this is a row something else wrote. It still may
        //    not reach a CSV as bytes.
        if (!mb_check_encoding($bin, 'UTF-8')) return self::cell('not-text', true);

        return ['state' => $cut ? 'truncated' : 'shown', 'value' => $bin, 'withheld' => false];
    }

    /**
     * Drop an incomplete UTF-8 sequence from the END of a string, and nothing
     * else.
     *
     * NOT mb_strcut(). It moves a cut point that falls INSIDE the string back to
     * a character boundary; asked for the whole of a string that already ends
     * mid-character it returns the whole string, half character included
     * (measured on 8.3). So the tail is read directly: walk back over at most
     * three continuation bytes to the lead byte, and if the lead byte announces
     * more bytes than are there, the sequence goes. A tail that is invalid for
     * any other reason is left alone, and the text test that follows refuses it.
     *
     * ONLY THE START OF A REAL CHARACTER GOES. Any byte >= 0xC0 was read as a
     * lead byte, so a stray 0xC0, 0xC1 or 0xF5-0xFF at the end was dropped and
     * the cell called "cut short" when the bytes were never text; so was a lead
     * byte whose next byte rules out every character (E0 80, ED A0, F4 90).
     * The partial sequence goes only if some continuation bytes would complete
     * it. Every lead byte's allowed second-byte range holds 0x80 or 0xBF, so
     * padding with each of those and asking the UTF-8 test settles it.
     */
    private static function wholeCharacters($bin)
    {
        $len = strlen($bin);
        for ($back = 1; $back <= 4 && $back <= $len; $back++) {
            $c = ord($bin[$len - $back]);
            if (($c & 0xC0) === 0x80) continue;              // a continuation byte
            $need = $c >= 0xF0 ? 4 : ($c >= 0xE0 ? 3 : ($c >= 0xC0 ? 2 : 1));
            if ($need <= $back) return $bin;                 // the last character is whole
            $part = substr($bin, $len - $back);
            $pad = $need - $back;
            if (mb_check_encoding($part . str_repeat("\x80", $pad), 'UTF-8')
                || mb_check_encoding($part . str_repeat("\xBF", $pad), 'UTF-8')) {
                return (string) substr($bin, 0, $len - $back);
            }
            return $bin;                                     // not the start of any character
        }
        return $bin;
    }

    private static function cell($state, $withheld)
    {
        return ['state' => $state, 'value' => null, 'withheld' => (bool) $withheld];
    }

    /** The record id as this report shows it. Hashed once per run of findings on one record. */
    private function recordOf($id)
    {
        if (!$this->hashed) return $id;
        if ($id !== $this->lastId) {
            $h = null;
            try {
                $h = call_user_func($this->hasher, $id);
            } catch (\Throwable $e) {
                $h = null;
            }
            // Never the raw id, and never an empty cell: a table of problems
            // nobody can reach reads as a fault in the reader's own export.
            $this->lastId = $id;
            $this->lastShown = (is_string($h) && $h !== '') ? $h : self::ID_UNAVAILABLE;
        }
        return $this->lastShown;
    }

    /**
     * The reason in the form the wording layer takes: the engine's own.
     *
     * A pooled reason stored as a mask comes back as its `;`-joined names, in
     * the order the engine emits them, which is what the legacy report and
     * MessageCatalog::detail() read, a sentence each. A MASK OF 0 MEANS "NOT
     * RECORDED", never "no problems": a row stored before the mask had a
     * producer (W7-R1) holds the joined names in reason_code itself.
     */
    private static function reasonOf(array $stored)
    {
        $code = (string) $stored['reason_code'];
        $mask = ReasonCode::maskOf($code, $stored['reason_bits']);
        // The engine gave no reason, and the column cannot hold nothing.
        if ($code === ReasonCode::UNSPECIFIED) return '';
        if ($code === self::POOLED_CODE && $mask > 0) {
            $joined = ReasonCode::pooledReason($mask);
            if ($joined !== '') return $joined;
        }
        return $code;
    }
}
