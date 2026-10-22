<?php

namespace INSPIRE\UniversalValidator\Scan;

use INSPIRE\UniversalValidator\ScanColumns;
use INSPIRE\UniversalValidator\ScanDimensions;
use INSPIRE\UniversalValidator\ScanPageView;

/**
 * A stored run's findings, as the rows a page or a file shows.
 *
 * THE ONE PLACE A STORED FINDING BECOMES A CELL. The table on the page and the
 * CSV somebody downloads from it have to be the same report: the same columns,
 * the same wording, the same withheld values, the same record names. They are
 * the same when both are built here, from one column list, through one mapper -
 * and they drift the first time either of them formats a cell itself.
 *
 * WHAT THIS IS NOT. It reads no table and writes no response. The rows arrive
 * already read (wave 8 gives the stores that read) and leave as strings; the
 * page and the export that call this are wave 11. Until then every public
 * method here is inert on purpose, and tests/scan_wiring_php.php says so.
 *
 * THE PUBLIC METHODS DO NOT CALL EACH OTHER. The wiring test finds a caller by
 * method name anywhere in the shipped tree, so a public method called only by
 * its neighbour would count as wired while nothing outside the class used
 * either. Shared work is private.
 */
final class ScanReport
{
    /** Names the file format, so a parser can refuse one it does not know. */
    const SCHEMA = 'uv-scan-report/1';

    /** What opens a line of the file that is about the report, not in it. */
    const META_PREFIX = '# ';

    private $mapper;
    private $dims;
    private $cols;
    private $run;

    /**
     * @param FindingRowMapper $mapper built for this project, THIS reader, and
     *                                 the values_state the run row holds
     * @param ScanDimensions   $dims   the labels, as the run saw the project
     * @param array            $run    the run row, keyed by ScanStore::RUN_COLUMNS
     * @throws \RuntimeException         when the run is another project's
     * @throws \InvalidArgumentException when the row is short, or kept other values
     */
    public function __construct(FindingRowMapper $mapper, ScanDimensions $dims, array $run)
    {
        foreach (['project_id', 'terminal', 'coverage', 'detail', 'clean', 'values_state'] as $k) {
            if (!array_key_exists($k, $run)) {
                throw new \InvalidArgumentException('a report needs the run it reports on; the row has no ' . $k);
            }
        }
        // The run row was taken on trust: nothing compared its project or what
        // it kept with the mapper that fills the cells. See checkRun().
        $mapper->checkRun($run);
        $this->mapper = $mapper;
        $this->dims = $dims;
        $this->run = $run;
        // Resolved ONCE. Which columns exist is a fact about the project's
        // shape, not about a finding, and re-deciding it per row is fifteen
        // closures called for every line of a large export.
        $this->cols = ScanColumns::all($dims);
    }

    /**
     * Rows for a table: the finding's id, and its cells keyed by column key.
     *
     * @param array[] $storedRows rows keyed by FindingRowMapper::INPUT_KEYS
     * @return array[] each ['id' => mixed, 'cells' => [key => string]]
     */
    public function present(array $storedRows)
    {
        $out = [];
        foreach ($storedRows as $stored) {
            // One row is finished before the next is started: MessageCatalog
            // remembers the last template it resolved, and findings arrive
            // grouped by rule far more often than not.
            $f = $this->mapper->toFinding($stored);
            $out[] = ['id' => $f['findingId'], 'cells' => ScanColumns::row($f, $this->dims, $this->cols)];
        }
        return $out;
    }

    /** The header line: machine keys, never labels. See ScanColumns::headers(). */
    public function csvHeaderLine()
    {
        return ScanPageView::csvRow(ScanColumns::headers($this->cols));
    }

    /** One finding as one line of the file. A caller streams these; nothing is buffered. */
    public function csvLine(array $storedRow)
    {
        $f = $this->mapper->toFinding($storedRow);
        return ScanPageView::csvRow(array_values(ScanColumns::row($f, $this->dims, $this->cols)));
    }

    /**
     * A line about the report: `# key`, the value, then empty cells.
     *
     * AS WIDE AS EVERY OTHER LINE. A comment line of a different width makes the
     * file ragged, and the importers that matter here (R, pandas, REDCap's own)
     * either reject a ragged file or silently re-guess the column count from
     * its first line - which would be this one. Both cells go through the same
     * quoting and formula defence as data, because a coverage sentence is
     * fixed text today and a rule name tomorrow.
     */
    public function csvMetaLine($key, $value)
    {
        $cells = [self::META_PREFIX . (string) $key, (string) $value];
        $width = max(count($this->cols), count($cells));
        return ScanPageView::csvRow(array_pad($cells, $width, ''));
    }

    /**
     * A DATA line about the report: the label in the first column, the text
     * in the second, the rest empty.
     *
     * NOT A COMMENT, ON PURPOSE. README promises that an incomplete file says
     * so in a row that survives deleting the comment lines and sorting the
     * sheet. A `# ` line goes with the first and is lost among the rows by the
     * second; a data row whose Issue cell says what it is does neither. As
     * wide as every other line, and through the same quoting and defence.
     */
    public function csvNoticeLine($label, $text)
    {
        $cells = [(string) $label, (string) $text];
        return ScanPageView::csvRow(array_pad($cells, max(count($this->cols), count($cells)), ''));
    }

    /**
     * What is true of the whole report, for its heading and its file header.
     *
     * THERE IS NO KEY HERE A CALLER COULD PRINT AS "CLEAN". Whether the run may
     * be called that is `mayCertify`, a boolean, and it is false for every run
     * that did not finish complete - including every run finished before the
     * decision was stored, whose row says NULL, which means NOT CERTIFIED and
     * not "found something".
     *
     * @param int|null $expectedRows how many findings the caller is about to
     *        write, so a reader of a cut-off file can tell that it was cut off
     */
    public function runFacts($expectedRows = null)
    {
        $outcome = [
            'terminal' => $this->run['terminal'],
            'coverage' => $this->run['coverage'],
            'detail'   => $this->run['detail'],
            'clean'    => ($this->run['clean'] !== null && (int) $this->run['clean'] === 1),
        ];
        return [
            'schema'           => self::SCHEMA,
            'columns'          => ScanColumns::headers($this->cols),
            'legend'           => ScanColumns::keyLegend($this->cols),
            'coverage'         => (string) $outcome['coverage'],
            'detail'           => (string) $outcome['detail'],
            'coverageSentence' => ScanPageView::coverageSentence($outcome['coverage'], $outcome['detail']),
            'mayCertify'       => ScanOutcome::mayClaimClean($outcome),
            // THE FILENAME'S SUFFIX, from the one place that makes it (review
            // rv11a2-5). This was ScanOutcome::suffix() alone, so one answer
            // carried `_CANCELLED` here and `_INCOMPLETE_CANCELLED` in `marks`,
            // and every terminal state but a certified one disagreed.
            'suffix'           => self::marksOf($this->run)['suffix'],
            'valuesState'      => (string) $this->run['values_state'],
            'labelsDegraded'   => $this->dims->isDegraded(),
            'degradedSummary'  => $this->dims->degradedSummary(),
            'expectedRows'     => $expectedRows === null ? null : max(0, (int) $expectedRows),
        ];
    }

    /**
     * README's three ways of saying a report is incomplete, from the run row:
     * a banner above it, a terminal row inside it that survives sorting and
     * deleting comment lines, and a filename suffix that survives renaming.
     * The page and the export take them from here (through
     * ScanService::incompleteMarks()), and runFacts() its suffix.
     *
     * Incomplete is a run that did not finish complete, or that finished
     * complete and did not keep every finding (the detail limit). A complete
     * run that may still not be called clean is not incomplete: its suffix
     * says _NOT-CERTIFIED and it carries no banner.
     *
     * @return array{incomplete:bool, certified:bool, banner:?string, row:?string, suffix:string}
     */
    public static function incompleteMarks(array $run)
    {
        return self::marksOf($run);
    }

    /** @see incompleteMarks() — the work, shared with runFacts(). */
    private static function marksOf(array $run)
    {
        $o = ['terminal' => isset($run['terminal']) ? $run['terminal'] : null,
              'coverage' => isset($run['coverage']) ? $run['coverage'] : null,
              'detail'   => isset($run['detail']) ? $run['detail'] : null,
              'clean'    => isset($run['clean']) && $run['clean'] !== null && (int) $run['clean'] === 1];
        $incomplete = $o['terminal'] !== ScanOutcome::COMPLETE || $o['detail'] === ScanOutcome::DETAIL_TRUNCATED;
        $certified = ScanOutcome::mayClaimClean($o);
        $suffix = ScanOutcome::suffix($o);
        if ($incomplete && strpos($suffix, '_INCOMPLETE') === false) $suffix = '_INCOMPLETE' . $suffix;
        if (!$incomplete && !$certified && $suffix === '') $suffix = '_NOT-CERTIFIED';
        return [
            'incomplete' => $incomplete,
            'certified'  => $certified,
            'banner'     => $incomplete ? 'INCOMPLETE SCAN - this report does NOT certify the project as clean' : null,
            'row'        => $incomplete
                ? 'This scan did not cover the whole project. Rows above are real findings over the records '
                  . 'that WERE examined; absence of a row is not evidence a record is clean. '
                  . ScanPageView::coverageSentence($o['coverage'], $o['detail'])
                : null,
            'suffix'     => $suffix,
        ];
    }
}
