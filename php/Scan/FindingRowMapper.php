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
     * @return array the finding ScanColumns renders, plus valueState, ruleSource
     *               and findingId
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
            'findingId'     => $stored['finding_id'],
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
     */
    private static function wholeCharacters($bin)
    {
        $len = strlen($bin);
        for ($back = 1; $back <= 4 && $back <= $len; $back++) {
            $c = ord($bin[$len - $back]);
            if (($c & 0xC0) === 0x80) continue;              // a continuation byte
            $need = $c >= 0xF0 ? 4 : ($c >= 0xE0 ? 3 : ($c >= 0xC0 ? 2 : 1));
            return $need > $back ? (string) substr($bin, 0, $len - $back) : $bin;
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
     * A pooled reason stored as a mask comes back as its `;`-joined names, which
     * is what MessageCatalog::detail() gives a sentence each. BITS = 0 MEANS
     * "NOT RECORDED", never "no problems": nothing writes the mask yet, and the
     * joined names are in reason_code itself.
     */
    private static function reasonOf(array $stored)
    {
        $code = (string) $stored['reason_code'];
        $bits = (int) $stored['reason_bits'];
        // The engine gave no reason, and the column cannot hold nothing.
        if ($code === ReasonCode::UNSPECIFIED) return '';
        if ($code === self::POOLED_CODE && $bits > 0) {
            $names = ReasonCode::pooledNames($bits);
            if ($names) return implode(';', $names);
        }
        return $code;
    }
}
