<?php
/**
 * scan_export_php.php — the CSV of a stored run, as streamFindings() writes it.
 *
 * Wave 11 (B5), item 3. pages/export.php hands ScanService::exportOpen()'s
 * stream an echo and a flush; this suite hands it a buffer and a counter and
 * reads the file back. It holds the file to five things:
 *
 *   1. THE SHAPE. A byte-order mark, the `# ` lines, the header, the rows,
 *      and an `export_complete=1` trailer with the row count; every line as
 *      wide as the header; a file that stopped early has no trailer and says
 *      that rows were lost.
 *   2. THE CELLS. The same columns and words as the findings page, quoted and
 *      formula-defused, UTF-8, record ids withheld as the audit log withholds
 *      them (RP-5), a stored duplicate's value unshown.
 *   3. THE READ. Keyset pages of the store, one flush after each.
 *   4. THE PROJECT AND THE GROUP. Another project's findings in the same
 *      generation, and another group's in the same run, never reach the file.
 *   5. THE MEMORY. 50,000 findings export with what the request holds flat.
 *
 * Run:  php tests/scan_export_php.php
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
    require_once __DIR__ . '/support/ArrayScanStore.php';
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
    require_once __DIR__ . '/../php/Logic.php';
    require_once __DIR__ . '/scan_fault_support.php';

    $n = 0; $fail = 0;
    function check($label, $cond) {
        global $n, $fail; $n++;
        if (!$cond) { $fail++; fwrite(STDERR, "FAIL: $label\n"); }
    }
}

namespace INSPIRE\UniversalValidator\Scan {
    use INSPIRE\UniversalValidator\ScanDimensions;

    const XPID = 77;
    const XNB = 78;

    /**
     * One run of $pid holding a finding per spec, each spec a set of overrides
     * on a plain finding. Fifty findings to a record, as the MySQL report case
     * plants them.
     *
     * @return array{run:int, gen:int, ok:bool}
     */
    function xplant(ArrayScanStore $store, $pid, array $specs, $prefix)
    {
        $r = $store->startRun($pid, ['created_by' => 'alice', 'values_state' => 'raw']);
        $rid = (int) $r['run']['run_id'];
        $gen = (int) $r['run']['generation_id'];
        $per = 50;
        $records = max(1, (int) ceil(count($specs) / $per));
        $recs = [];
        for ($i = 0; $i < $records; $i++) {
            $recs[] = ['id_bin' => $prefix . '-' . $i, 'hash' => hash('sha256', $prefix . '-' . $pid . '-' . $i, true),
                       'dag' => null];
        }
        $store->writeManifest($rid, $recs);
        $ep = (int) $store->run($pid, $rid)['lease_epoch'];
        $claims = $store->claim($rid, 'w', $ep, $records);
        $findings = [];
        $done = [];
        $k = 0;
        foreach ($claims as $c) {
            $done[] = ['ordinal' => $c['ordinal'], 'record_hash' => $c['hash'],
                       'state' => ScanStore::REC_DONE, 'claim' => $c['claim']];
            for ($j = 0; $j < $per && $k < count($specs); $j++, $k++) {
                $findings[] = $specs[$k] + ['ordinal' => $c['ordinal'], 'project_id' => $pid, 'generation_id' => $gen,
                    'identity' => hash('sha256', $prefix . '|' . $k, true),
                    'record_hash' => $c['hash'], 'record_id_bin' => $c['id_bin'], 'event_id' => 1, 'instance' => 1,
                    'host_form' => 'fa', 'field' => 'f' . $k,
                    'rule_source_id' => 'r1', 'rule_revision' => str_repeat('c', 64), 'rule_ord' => 1,
                    'check_type' => 'required', 'reason_code' => 'required-blank', 'dag_key' => null];
            }
        }
        $ok = $store->commitBatch($rid, 'w', $ep, ['bytes' => 0, 'records' => $done, 'findings' => $findings]);
        return ['run' => $rid, 'gen' => $gen, 'ok' => $ok];
    }

    /** A report over $store's run $rid, for a reader who may see raw values unless told otherwise. */
    function xreport(ArrayScanStore $store, $pid, $rid, array $over = [])
    {
        $d = new ScanDimensions();
        $d->forms = ['fa' => 'Form A'];
        $d->singleEventProved = true;
        $d->hasDags = true;
        $d->rules = [1 => ['type' => 'required', 'label' => 'F is required', 'message' => '',
                           'messages' => [], 'assert' => '', 'fields' => [], 'detail' => []]];
        $d->rulesBySource = ['r1' => 1];
        $mapper = new FindingRowMapper($over + ['pid' => $pid, 'now' => '2026-10-22 12:00:00',
            'valuesState' => 'raw', 'readerCeiling' => 'raw', 'identifiers' => [],
            'recordIds' => FindingRowMapper::IDS_RAW, 'recordIdField' => 'record_id']);
        return new ScanReport($mapper, $d, $store->run($pid, $rid));
    }

    /**
     * Run the stream into a buffer.
     *
     * @return array{out:array, text:string, flushes:int, lines:string[], peak:int}
     *         peak: the most memory_get_usage() rose over its value at the
     *         start, sampled at every write, when $keep is false (the text is
     *         then only hashed, as a browser would take it away)
     */
    function xstream(ScanStore $store, ScanReport $report, $pid, $gen, array $scope, $keep = true, array $marks = [])
    {
        $text = '';
        $flushes = 0;
        $bytes = 0;
        $hash = hash_init('sha256');
        $base = memory_get_usage();
        $peak = 0;
        $write = function ($s) use (&$text, &$bytes, &$peak, $base, $keep, $hash) {
            $bytes += strlen($s);
            if ($keep) { $text .= $s; } else { hash_update($hash, $s); }
            $m = memory_get_usage() - $base;
            if ($m > $peak) $peak = $m;
        };
        $flush = function () use (&$flushes) { $flushes++; };
        $head = [['schema', ScanReport::SCHEMA], ['expected_rows', 'unknown']];
        $out = ScanService::streamFindings($store, $report, $pid, $gen, $scope, $head, $marks, $write, $flush);
        $lines = $keep ? explode("\r\n", rtrim(substr($text, 3), "\r\n")) : [];
        return ['out' => $out, 'text' => $text, 'flushes' => $flushes, 'lines' => $lines,
                'peak' => $peak, 'bytes' => $bytes];
    }

    /* =====================================================================
     * 1. THE SHAPE OF THE FILE
     * ===================================================================== */
    {
        $s = new ArrayScanStore();
        $specs = [];
        for ($i = 0; $i < 250; $i++) $specs[] = [];
        $p = xplant($s, XPID, $specs, 'R');
        $rep = xreport($s, XPID, $p['run']);
        $x = xstream($s, $rep, XPID, $p['gen'], ['dag' => null]);
        $width = count(str_getcsv($rep->csvHeaderLine()));
        check('shape: the fixture committed', $p['ok'] === true);
        check('shape: the file opens with a UTF-8 byte-order mark', substr($x['text'], 0, 3) === "\xEF\xBB\xBF");
        check('shape: then the lines about the report, then the header',
            strpos($x['lines'][0], '"# schema"') === 0 && $x['lines'][2] === $rep->csvHeaderLine());
        check('shape: every finding is one line, 250 of them', count($x['lines']) === 2 + 1 + 250 + 1
            && $x['out']['ok'] === true && $x['out']['rows'] === 250);
        check('shape: the last line is the trailer, with the row count',
            end($x['lines']) === $rep->csvMetaLine('export_complete=1', 'rows=250'));
        $ragged = [];
        foreach ($x['lines'] as $i => $l) {
            if (count(str_getcsv($l)) !== $width) $ragged[] = $i;
        }
        check('shape: every line is as wide as the header', $ragged === []);
        check('shape: lines end CRLF, as RFC 4180 has them', substr_count($x['text'], "\r\n") === count($x['lines']));
        check('shape: the whole file is UTF-8', mb_check_encoding($x['text'], 'UTF-8'));

        // THE READ: three store pages of at most 100, a flush after each, one
        // after the head and one after the trailer.
        check('read: 250 findings are three keyset pages', $x['out']['pages'] === 3);
        check('read: with a flush after the head, after every page and after the trailer',
            $x['flushes'] === 1 + 3 + 1);
        $e = new ArrayScanStore();
        $ep = xplant($e, XPID, [], 'E');
        $ex = xstream($e, xreport($e, XPID, $ep['run']), XPID, $ep['gen'], ['dag' => null]);
        check('shape: a run with no findings still ends on the trailer, with rows=0',
            $ex['out']['ok'] === true && count($ex['lines']) === 4
            && strpos(end($ex['lines']), '"# export_complete=1","rows=0"') === 0);
    }

    /* =====================================================================
     * 1b. A STORE THAT FAILS PARTWAY: NO TRAILER, AND IT SAYS SO
     * ===================================================================== */
    {
        $inner = new ArrayScanStore();
        $specs = [];
        for ($i = 0; $i < 250; $i++) $specs[] = [];
        $p = xplant($inner, XPID, $specs, 'R');
        $rep = xreport($inner, XPID, $p['run']);
        $second = new class($inner) extends FaultyStore {
            public $calls = 0;
            public function findings($projectId, $generationId, array $scope, array $filter, $afterId, $limit,
                                     &$through = null)
            {
                if (++$this->calls === 2) throw new ScanStoreUnavailable('[2006] MySQL server has gone away');
                return parent::findings($projectId, $generationId, $scope, $filter, $afterId, $limit, $through);
            }
        };
        $x = xstream($second, $rep, XPID, $p['gen'], ['dag' => null]);
        check('lost: a store that fails on its second page ends the file without the trailer',
            $x['out']['ok'] === false && $x['out']['rows'] === 100
            && strpos($x['text'], 'export_complete') === false);
        check('lost: and its last line says rows were lost, and how many were written',
            end($x['lines']) === $rep->csvMetaLine(ScanService::ROWS_LOST, 'rows=100')
            && $x['out']['error'] instanceof ScanStoreUnavailable);
        check('lost: a data row says so too, for a reader who deleted the comment lines',
            $x['lines'][count($x['lines']) - 2] === $rep->csvNoticeLine(ScanService::LOST_LABEL, ScanService::ROWS_LOST));
        check('lost: the reason never reaches the file', strpos($x['text'], 'gone away') === false);

        // rv11a2-4: a STOPPED run's file that also lost rows keeps the
        // terminal row that says the scan was incomplete. It carried only
        // INCOMPLETE EXPORT, so with the comment lines deleted nothing said
        // the scan itself had been stopped.
        $second->calls = 0;
        $stoppedMarks = ScanService::incompleteMarks(['terminal' => ScanOutcome::CANCELLED,
            'coverage' => ScanOutcome::COV_PARTIAL, 'detail' => ScanOutcome::DETAIL_COMPLETE, 'clean' => null]);
        $sx = xstream($second, $rep, XPID, $p['gen'], ['dag' => null], true, $stoppedMarks);
        $data = array_values(array_filter($sx['lines'], function ($l) { return strpos($l, '"# ') !== 0; }));
        $scanRow = $rep->csvNoticeLine(ScanService::INCOMPLETE_LABEL, $stoppedMarks['row']);
        check('rv11a2-4: a stopped run\'s file that lost rows still says, in a data row, that the scan was incomplete',
            $sx['out']['ok'] === false && in_array($scanRow, $data, true)
            && $sx['lines'][count($sx['lines']) - 3] === $scanRow
            && $sx['lines'][count($sx['lines']) - 2] === $rep->csvNoticeLine(ScanService::LOST_LABEL, ScanService::ROWS_LOST));

        $stuck = new class($inner) extends FaultyStore {
            public function findings($projectId, $generationId, array $scope, array $filter, $afterId, $limit,
                                     &$through = null)
            {
                $rows = parent::findings($projectId, $generationId, $scope, $filter, $afterId, $limit, $through);
                $through = (int) $afterId;
                return $rows;
            }
        };
        $x = xstream($stuck, $rep, XPID, $p['gen'], ['dag' => null]);
        check('lost: a cursor that does not move stops the file after one page, without the trailer',
            $x['out']['ok'] === false && $x['out']['pages'] === 1 && strpos($x['text'], 'export_complete') === false);
    }

    /* =====================================================================
     * 2. THE CELLS
     * ===================================================================== */
    {
        $s = new ArrayScanStore();
        $p = xplant($s, XPID, [
            ['record_id_bin' => '=HYPERLINK("http://x")', 'field' => '+cmd', 'value_bin' => '@SUM(A1)', 'value_len' => 8],
            ['field' => 'note', 'value_bin' => "-2+3\tx", 'value_len' => 6],
            ['field' => 'name', 'value_bin' => 'Zoë Ünïcödé', 'value_len' => strlen('Zoë Ünïcödé')],
            ['record_id_bin' => 'PATIENT-0042', 'field' => 'record_id', 'check_type' => 'single',
             'reason_code' => 'format', 'value_bin' => 'PATIENT-0042', 'value_len' => 12],
            ['field' => 'dup', 'check_type' => 'unique', 'reason_code' => UniqueFinalizer::REASON],
        ], 'C');
        $rep = xreport($s, XPID, $p['run']);
        $x = xstream($s, $rep, XPID, $p['gen'], ['dag' => null]);
        $rows = array_slice($x['lines'], 3, 5);
        $cells = array_map('str_getcsv', $rows);
        $keys = str_getcsv($rep->csvHeaderLine());
        $col = function ($row, $key) use ($cells, $keys) {
            $i = array_search($key, $keys, true);
            return $i === false ? null : $cells[$row][$i];
        };
        $paged = $rep->present($s->findings(XPID, $p['gen'], ['dag' => null], [], 0, 100));
        check('cells: the same cells the findings page shows, in the export\'s column order',
            count($paged) === 5 && $cells[2] === array_values($paged[2]['cells'])
            && array_keys($paged[2]['cells']) === $keys);
        check('cells: a record id that starts a formula is defused',
            $col(0, 'record') === "'=HYPERLINK(\"http://x\")");
        check('cells: so is a field name and a value', $col(0, 'field') === "'+cmd" && $col(0, 'value') === "'@SUM(A1)");
        check('cells: a value starting with a minus is defused, and its tab is not dropped silently into a formula',
            strpos($col(1, 'value'), "'") === 0);
        check('cells: every cell is quoted', strpos($rows[2], '"') === 0 && strpos($rows[2], ',"') !== false);
        check('cells: UTF-8 text is carried as it is', $col(2, 'value') === 'Zoë Ünïcödé');
        check('cells (control): with raw ids a finding on the record-id field shows its value',
            $col(3, 'value') === 'PATIENT-0042');
        check('cells (item 12): a stored duplicate says its value was not kept, rather than reading as blank',
            $col(4, 'value') === '[not kept by this scan]');

        // RP-5 under hashed record ids: the id is in neither the Record cell
        // nor the Value cell of a finding on the record-id field.
        $hashed = xreport($s, XPID, $p['run'], ['recordIds' => FindingRowMapper::IDS_HASHED,
            'hashRecordId' => function ($id) { return hash_hmac('sha256', (string) $id, 'k|77'); }]);
        $hx = xstream($s, $hashed, XPID, $p['gen'], ['dag' => null]);
        check('RP-5: under hashed ids the raw record id appears nowhere in the file',
            strpos($hx['text'], 'PATIENT-0042') === false
            && strpos($hx['text'], hash_hmac('sha256', 'PATIENT-0042', 'k|77')) !== false);
        check('RP-5 (control): and the file is otherwise the same length in lines',
            count($hx['lines']) === count($x['lines']));
        // A reader capped at locations sees no value at all.
        $loc = xreport($s, XPID, $p['run'], ['readerCeiling' => 'locations']);
        $lx = xstream($s, $loc, XPID, $p['gen'], ['dag' => null]);
        check('disclosure: a reader capped at locations gets no value in the file',
            strpos($lx['text'], 'SUM(A1)') === false && strpos($lx['text'], 'Ünïcödé') === false
            && strpos($lx['text'], '[withheld by policy]') !== false);
    }

    /* =====================================================================
     * 4. ANOTHER PROJECT, ANOTHER GROUP
     * ===================================================================== */
    {
        $s = new ArrayScanStore();
        // The neighbour first, so its ids sit below this project's: every page
        // of this project reads on from inside the neighbour's range.
        $theirSpecs = [];
        for ($i = 0; $i < 120; $i++) $theirSpecs[] = ['dag_key' => $i % 2 ? 'north' : 'south'];
        $theirs = xplant($s, XNB, $theirSpecs, 'THEIRS');
        $mineSpecs = [];
        for ($i = 0; $i < 230; $i++) $mineSpecs[] = ['dag_key' => [null, 'north', 'south'][$i % 3]];
        $mine = xplant($s, XPID, $mineSpecs, 'MINE');
        check('leak: two projects share generation 1, as every installation does',
            $mine['gen'] === $theirs['gen'] && $mine['gen'] === 1);
        $rep = xreport($s, XPID, $mine['run']);
        $all = xstream($s, $rep, XPID, $mine['gen'], ['dag' => null]);
        check('leak: this project\'s file holds its 230 findings and none of the neighbour\'s',
            $all['out']['rows'] === 230 && strpos($all['text'], 'THEIRS') === false
            && substr_count($all['text'], '"MINE-') === 230);
        $north = xstream($s, $rep, XPID, $mine['gen'], ['dag' => 'north']);
        $at = array_search('dag', str_getcsv($rep->csvHeaderLine()), true);
        $groups = [];
        foreach (array_slice($north['lines'], 3, -1) as $l) $groups[str_getcsv($l)[$at]] = true;
        check('group: a north reader\'s file holds north\'s 77 findings and none of another project\'s',
            $north['out']['rows'] === 77 && strpos($north['text'], 'THEIRS') === false);
        check('group: and its group column names north and nothing else', array_keys($groups) === ['north']);
        $allGroups = [];
        foreach (array_slice($all['lines'], 3, -1) as $l) $allGroups[str_getcsv($l)[$at]] = true;
        check('group (control): the unconfined file holds all three', count($allGroups) === 3);
    }

    /* =====================================================================
     * 6. README'S THREE MARKS OF A FILE THAT CANNOT CERTIFY (item 10)
     *
     * A banner at the top, a terminal data row that survives deleting the
     * comment lines and sorting the sheet, and _INCOMPLETE in the filename.
     * ===================================================================== */
    {
        $m = function (array $o) {
            return ScanService::incompleteMarks($o + ['terminal' => ScanOutcome::COMPLETE,
                'coverage' => ScanOutcome::FENCED, 'detail' => ScanOutcome::DETAIL_COMPLETE, 'clean' => 0]);
        };
        $clean = $m(['clean' => 1]);
        check('marks: a certified run carries none, and no suffix',
            $clean['incomplete'] === false && $clean['certified'] === true && $clean['banner'] === null
            && $clean['row'] === null && $clean['suffix'] === '');
        $found = $m([]);
        check('marks: a complete run that found something is not incomplete, and is named not certified',
            $found['incomplete'] === false && $found['certified'] === false && $found['banner'] === null
            && $found['suffix'] === '_NOT-CERTIFIED');
        $cases = [
            ['cancelled', ['terminal' => ScanOutcome::CANCELLED, 'coverage' => ScanOutcome::COV_PARTIAL, 'clean' => null],
             '_INCOMPLETE_CANCELLED'],
            ['failed', ['terminal' => ScanOutcome::FAILED, 'coverage' => ScanOutcome::COV_FAILED, 'clean' => null],
             '_INCOMPLETE_FAILED'],
            ['expired', ['terminal' => ScanOutcome::EXPIRED, 'coverage' => ScanOutcome::COV_PARTIAL, 'clean' => null],
             '_INCOMPLETE_EXPIRED'],
            ['partial, manifest only', ['terminal' => ScanOutcome::PARTIAL, 'coverage' => ScanOutcome::MANIFEST],
             '_INCOMPLETE_MANIFEST_ONLY'],
            ['partial', ['terminal' => ScanOutcome::PARTIAL, 'coverage' => ScanOutcome::COV_PARTIAL], '_INCOMPLETE'],
            ['complete with findings cut', ['detail' => ScanOutcome::DETAIL_TRUNCATED], '_INCOMPLETE_TRUNCATED'],
        ];
        foreach ($cases as $c) {
            $x = $m($c[1]);
            check('marks: a ' . $c[0] . ' run carries all three, its name ending ' . $c[2],
                $x['incomplete'] === true && $x['certified'] === false && $x['suffix'] === $c[2]
                && strpos($x['banner'], 'INCOMPLETE SCAN') === 0 && strpos($x['banner'], 'NOT certify') !== false
                && strpos($x['row'], 'absence of a row is not evidence a record is clean') !== false);
        }

        $s = new ArrayScanStore();
        $p = xplant($s, XPID, [[], [], []], 'I');
        $rep = xreport($s, XPID, $p['run']);
        $marks = $m($cases[0][1]);
        $x = xstream($s, $rep, XPID, $p['gen'], ['dag' => null], true, $marks);
        check('marks: the banner is the file\'s first line, before anything else',
            $x['lines'][0] === $rep->csvMetaLine($marks['banner'], ''));
        $terminal = $rep->csvNoticeLine(ScanService::INCOMPLETE_LABEL, $marks['row']);
        check('marks: the terminal row is the last data row, before the trailer',
            $x['lines'][count($x['lines']) - 2] === $terminal
            && end($x['lines']) === $rep->csvMetaLine('export_complete=1', 'rows=3'));
        check('marks: the terminal row is data, not a comment, and as wide as the header',
            strpos($terminal, '"# ') !== 0 && count(str_getcsv($terminal)) === count(str_getcsv($rep->csvHeaderLine())));
        $kept = array_values(array_filter($x['lines'], function ($l) { return strpos($l, '"# ') !== 0; }));
        sort($kept);
        check('marks: deleting the comment lines and sorting the sheet leaves the terminal row in it',
            in_array($terminal, $kept, true) && count($kept) === 1 + 3 + 1);
        $plain = xstream($s, $rep, XPID, $p['gen'], ['dag' => null], true, $found);
        check('marks (control): a complete run\'s file has no banner and no terminal row',
            strpos($plain['text'], 'INCOMPLETE') === false && strpos($plain['lines'][0], '"# schema"') === 0);
    }

    /* =====================================================================
     * 5. 50,000 FINDINGS, AND WHAT THE REQUEST HOLDS
     * ===================================================================== */
    {
        // A store that makes each page up from the cursor, so what is measured
        // is the export alone: an array store holding 50,000 findings is
        // itself most of the memory a PHP test is given.
        $inner = new ArrayScanStore();
        $run = xplant($inner, XPID, [], 'B');
        $made = function ($count) use ($inner) {
            $s = new class($inner) extends FaultyStore {
                public $count = 0;
                public function findings($projectId, $generationId, array $scope, array $filter, $afterId,
                                         $limit, &$through = null)
                {
                    $through = null;
                    $rows = [];
                    for ($id = (int) $afterId + 1; $id <= $this->count; $id++) {
                        if (count($rows) === (int) $limit) { $through = $id - 1; break; }
                        $rows[] = ['finding_id' => $id, 'project_id' => $projectId,
                            'record_id_bin' => sprintf('B-%06d', intdiv($id, 50)), 'event_id' => 1, 'instance' => 1,
                            'host_form' => 'fa', 'field' => 'f' . ($id % 400), 'rule_source_id' => 'r1',
                            'rule_ord' => 1, 'check_type' => 'required', 'reason_code' => 'required-blank',
                            'reason_bits' => 0, 'dag_key' => null, 'value_bin' => str_repeat('v', 40),
                            'value_len' => 40, 'value_truncated' => 0, 'value_expires_at' => null,
                            'as_of' => null, 'z_score' => null, 'growth_reference' => null, 'justified' => 0];
                    }
                    return $rows;
                }
            };
            $s->count = $count;
            return $s;
        };
        $rep = xreport($inner, XPID, $run['run']);
        $x5 = xstream($made(5000), $rep, XPID, $run['gen'], ['dag' => null], false);
        $t = microtime(true);
        $x50 = xstream($made(50000), $rep, XPID, $run['gen'], ['dag' => null], false);
        $secs = microtime(true) - $t;
        echo '  50,000 findings: ' . number_format($x50['bytes']) . ' bytes in ' . $x50['out']['pages']
            . ' pages, ' . round($secs, 1) . ' s; memory rose at most ' . number_format($x50['peak'])
            . ' bytes (5,000: ' . number_format($x5['peak']) . ")\n";
        check('memory: 50,000 findings export whole', $x50['out']['ok'] === true && $x50['out']['rows'] === 50000
            && $x50['out']['pages'] === 500);
        check('memory: what the request holds while it writes stays under 2 MB',
            $x50['peak'] < 2 * 1024 * 1024);
        check('memory: and does not grow with the run: ten times the findings, within 256 KB of the same',
            $x50['peak'] < $x5['peak'] + 256 * 1024);
        check('memory (control): the file itself is far larger than what was held',
            $x50['bytes'] > 20 * $x50['peak']);
    }

    echo "scan_export_php: $n checks, $fail failure(s)\n";
    exit($fail ? 1 : 0);
}
