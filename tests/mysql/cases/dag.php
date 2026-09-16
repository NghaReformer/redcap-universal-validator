<?php
/**
 * tests/mysql/cases/dag.php — one group axis, and the two halves of a run
 * agreeing about the same record.
 *
 * B3 was the page producing a friendly DAG name where every consumer compared a
 * numeric group id. The mocked suites could not see it: scan_security_php was
 * internally consistent on names, the planning matrix internally consistent on
 * ids, and neither ever held both sides of one comparison. This case holds both
 * sides against a real server.
 *
 * B3b IS THE HALF ONLY A DATABASE CAN SETTLE. RecordManifestSource resolves a
 * record's group two ways: from redcap_record_list.dag_id on the record index,
 * and from the __GROUPID__ rows of the data table on the fallback. page() always
 * did both; dagsOf() and inScope() short-circuited on the fallback and answered
 * "no group" for every record. So on a project with no record index, planning
 * admitted a record and catch-up refused the same record on the same run - while
 * the run still claimed a proved fence over the window in which it admitted
 * nothing. Proving the two agree needs two real sources over the same data, and
 * a fake of either would prove only that the fake agrees with itself.
 *
 * TWO PROJECTS AGAIN. 900 carries a record index; 902 carries none, so it falls
 * through to the data table. Both hold the same three records in the same two
 * groups, which is what makes "the two sources agree" a comparison rather than a
 * coincidence.
 */

use INSPIRE\UniversalValidator\Scan\Schema;
use INSPIRE\UniversalValidator\Scan\RecordManifestSource;

$PID   = 900;
$FBPID = 902;          // deliberately not uv_neighbour($PID) - 901 is the planted neighbour

{
    uv_redcap_schema($A);
    uv_redcap_log_schema($A);
    uv_redcap_project($A, $PID);
    uv_redcap_project($A, $FBPID);
    uv_redcap_neighbour_records($A, $PID);

    // THE SAME THREE RECORDS IN THE SAME TWO GROUPS, ONE PROJECT PER SOURCE.
    // R2 is in another group and R3 is in none, so "agrees" has to survive a
    // match, a mismatch and an absence rather than just a match.
    $rows = array(array('R1', '7'), array('R2', '31'), array('R3', null));
    foreach ($rows as $r) {
        $A->query("INSERT INTO redcap_record_list (project_id, record, dag_id) VALUES ("
            . $PID . ", '" . $r[0] . "', " . ($r[1] === null ? 'NULL' : $r[1]) . ")");
        // The fallback project gets NO record-index row, only data rows.
        $A->query("INSERT INTO redcap_data (project_id, event_id, record, field_name, `value`)
            VALUES (" . $FBPID . ", 1, '" . $r[0] . "', 'record_id', '" . $r[0] . "')");
        if ($r[1] !== null) {
            $A->query("INSERT INTO redcap_data (project_id, event_id, record, field_name, `value`)
                VALUES (" . $FBPID . ", 1, '" . $r[0] . "', '__GROUPID__', '" . $r[1] . "')");
        }
    }

    $viaIndex = RecordManifestSource::open($dbA, $PID, array('pk' => 'record_id'));
    $viaData  = RecordManifestSource::open($dbA, $FBPID, array('pk' => 'record_id'));
    check('dag: the index project uses the record index',
        $viaIndex['ok'] === true && $viaIndex['source']->via() === 'redcap_record_list');
    check('dag: and the index-less project falls through to the data table',
        $viaData['ok'] === true && strpos($viaData['source']->via(), 'redcap_data') === 0);
    // hasDag() gates whether a group-scoped run may START. Both sources answer
    // yes, which is what makes the disagreement below reachable in production
    // rather than theoretical: a run IS allowed to begin on the fallback.
    check('dag: both sources report that they can place a record in a group',
        $viaIndex['source']->hasDag() === true && $viaData['source']->hasDag() === true);

    /* -----------------------------------------------------------------------
     * CASE 1  the two sources resolve the same record to the same group id
     * -------------------------------------------------------------------- */
    $ids = array('R1', 'R2', 'R3');
    $byIndex = $viaIndex['source']->dagsOf($ids);
    $byData  = $viaData['source']->dagsOf($ids);
    check('dag: the record index and the data-table fallback resolve the same record to '
        . 'the same group id', $byIndex === $byData);
    // NAMED, not just compared. Two identical wrong answers are also equal, and
    // the shipped bug produced exactly that shape on one side: all-null.
    check('dag: and the values are the group ids REDCap stores, not names or nulls',
        $byIndex === array('R1' => '7', 'R2' => '31', 'R3' => null));

    // The same question page() answers, asked of the same rows. This is the
    // disagreement itself: page() resolved R1 to '7' on the fallback while
    // dagsOf() returned null for it.
    $pg = $viaData['source']->page(null, array(), 10);
    $byPage = array();
    foreach ($pg['rows'] as $r) $byPage[$r['id']] = $r['dag'];
    check('dag: and the walk that BUILDS the manifest agrees with the one that CHECKS it',
        $byPage === $byData);

    /* -----------------------------------------------------------------------
     * CASE 2  planning and catch-up admit the same records, on both sources
     *
     * inScope() is what catch-up asks about a record created DURING a run.
     * stream() is what planning asked about the same record a moment earlier.
     * A run whose two halves disagree admits nothing at catch-up and still
     * promotes on a proved fence, so this is the property, stated twice
     * because the defect existed on only one of the two shapes.
     * -------------------------------------------------------------------- */
    foreach (array('record index' => $viaIndex['source'],
                   'data-table fallback' => $viaData['source']) as $shape => $src) {
        $manifest = array('R1');            // group 7
        $excluded = array('R2', 'R3');      // another group, and no group at all
        check('dag: every record the planner admitted, inScope() also admits (' . $shape . ')',
            $src->inScope($manifest, '7') === array_fill_keys($manifest, true));
        check('dag: and every record it excluded, inScope() also excludes (' . $shape . ')',
            $src->inScope($excluded, '7') === array_fill_keys($excluded, false));
        // THE CONTROL. Without it, an inScope() that returned false for
        // everything would pass the second check and fail only the first, and
        // an unscoped run is the case that must still admit the lot.
        check('dag: an unscoped run still admits everything (' . $shape . ')',
            $src->inScope(array_merge($manifest, $excluded), null)
            === array_fill_keys(array_merge($manifest, $excluded), true));
    }

    /* -----------------------------------------------------------------------
     * CASE 3  the group id crosses into the frozen manifest unchanged
     *
     * scope_dag and uv_scan_record.dag are what every later comparison reads,
     * and a value that was correct in the walk and normalised on the way to the
     * store would reopen the same seam one layer down.
     * -------------------------------------------------------------------- */
    $store   = new \INSPIRE\UniversalValidator\Scan\SqlScanStore($dbA);
    $planner = new \INSPIRE\UniversalValidator\Scan\ScanPlanner($store, 'test-secret-key');
    $req = uv_plan_request(array(
        'source'    => $viaIndex['source'],
        'fence'     => null,
        'dagFilter' => '7',
        'rules'     => array(array('type' => 'required', 'fields' => array('record_id'))),
        'ownership' => array('record_id' => 'demographics'),
    ));
    $p = $planner->plan($PID, $req);
    check('dag: a group-scoped plan freezes only the records in that group',
        $p['ok'] === true && (int) $p['run']['manifest_total'] === 1);
    check('dag: and stores the scope as the id it was given',
        $p['run']['scope_dag'] === '7');
    // dag_at_fence, NOT dag: the column records the group the record was in WHEN
    // THE MANIFEST FROZE, and the whole point of freezing it is that a later
    // move out of the group cannot retroactively change what the run covered.
    // record_id_bin is VARBINARY, so it comes back as bytes and is compared as
    // bytes - a CAST here would be this file testing its own coercion.
    $stored = $dbA->select('SELECT record_id_bin, dag_at_fence FROM ' . Schema::table('scan_record')
        . ' WHERE run_id = ?', array((int) $p['run']['run_id']));
    check('dag: and the manifest row carries the same id, unaltered',
        count($stored) === 1 && (string) $stored[0][0] === 'R1'
        && (string) $stored[0][1] === '7');

    /* -----------------------------------------------------------------------
     * CASE 4  the wedged run, and the one thing that unwedges it (M3)
     *
     * A run started before the axis fix carries a DAG NAME in scope_dag. No
     * group id can equal it, so nobody - not even the designer who started it -
     * can work, read or cancel it, and it holds the project's one active slot
     * with nothing to release it. Schema::upgradeDataV2 retires exactly this
     * shape on an installation migrating from version 1, but an installation
     * already AT version 2 never re-enters that path, and version 2 is every
     * installation carrying this build.
     *
     * PROVED HERE AND NOT IN THE MOCKED SUITES, because retiring a run means
     * calling store()->finish(): the page fixture answers null to that UPDATE,
     * the store raises ScanStoreUnavailable, and the reaper swallows it. A
     * control asserting "nothing was retired" against a store that can retire
     * nothing is green for a reason unconnected to the code.
     * -------------------------------------------------------------------- */
    $A->query('CREATE TABLE IF NOT EXISTS redcap_data_access_groups (
        group_id INT NOT NULL, project_id INT NOT NULL, group_name VARCHAR(150) NULL,
        KEY (project_id)) ENGINE=InnoDB');
    $A->query('DELETE FROM redcap_data_access_groups');
    $A->query('INSERT INTO redcap_data_access_groups (group_id, project_id, group_name) VALUES ('
        . '7, ' . $PID . ", 'north'), (31, " . $PID . ", 'south')");
    // The NEIGHBOUR has a group 42 that this project does not. A reaper that
    // dropped its project predicate would call 42 valid here.
    $A->query('INSERT INTO redcap_data_access_groups (group_id, project_id, group_name) VALUES ('
        . '42, ' . uv_neighbour($PID) . ", 'elsewhere')");

    $rights = array('design' => true, 'data_export_tool' => '1', 'group_id' => 7,
                    'forms' => array('demographics' => '1'));
    $scope7 = array('ok' => true, 'dag' => '7', 'rights' => $rights);
    $svc = new \INSPIRE\UniversalValidator\Scan\ScanService(new \UvDagModule($A));

    // A LIVE, WORKABLE RUN FIRST: the control has to run against the same store
    // and the same reaper, or "left alone" means nothing.
    $store->finish((int) $p['run']['run_id'],
        \INSPIRE\UniversalValidator\Scan\ScanOutcome::derive(array('cancelled' => true)));
    $live = $planner->plan($PID, array_merge($req, array('dagFilter' => '7')));
    $liveId = (int) $live['run']['run_id'];
    $seen = $svc->activeRun($PID, $scope7);
    check('dag: your own group id is a scope you may work, and you get the run id',
        $seen['state'] === 'yours' && $seen['run_id'] === $liveId);
    $still = $dbA->select('SELECT terminal FROM ' . Schema::table('scan_run')
        . ' WHERE run_id = ?', array($liveId));
    check('dag: and a live run in a group that still exists is NOT retired',
        isset($still[0]) && $still[0][0] === null);

    // NOW THE WEDGE. The same run, restamped with the friendly name the page
    // used to produce - which is exactly what a pre-fix run carries on disk.
    $dbA->exec('UPDATE ' . Schema::table('scan_run') . " SET scope_dag = 'north'
        WHERE run_id = ?", array($liveId));
    $wedged = $svc->activeRun($PID, $scope7);
    check('dag: a run stamped with a DAG name is retired rather than held forever',
        $wedged['state'] === 'none' && $wedged['run_id'] === null);
    $gone = $dbA->select('SELECT terminal, coverage, active_slot FROM '
        . Schema::table('scan_run') . ' WHERE run_id = ?', array($liveId));
    // `expired`, matching what Schema::upgradeDataV2 writes for the same
    // condition: the two migrations must not leave rows a reader can tell apart.
    check('dag: retired as expired, the same terminal state the 1-to-2 migration writes',
        isset($gone[0]) && $gone[0][0] === 'expired');
    // THE SLOT IS THE POINT. A terminal row that still held active_slot = 1
    // would leave the project just as busy as before.
    check('dag: and the slot is released, so the project can scan again',
        isset($gone[0]) && $gone[0][2] === null);
    $next = $planner->plan($PID, array_merge($req, array('dagFilter' => '7')));
    check('dag: proved by starting one, which is what the operator was blocked from',
        $next['ok'] === true && $next['busy'] === false);
    $store->finish((int) $next['run']['run_id'],
        \INSPIRE\UniversalValidator\Scan\ScanOutcome::derive(array('cancelled' => true)));

    // A GROUP THAT BELONGS TO THE PROJECT NEXT DOOR IS NOT THIS PROJECT'S
    // GROUP. This is the check that fails if the group-list read loses its
    // project predicate - which is what \REDCap::getGroupNames() would have
    // done, answering for the ambient project rather than for $pid.
    // A RECORD IN 42, or the plan is refused before the reaper can be asked
    // about it: an empty manifest is now a refusal rather than a certificate
    // (ScanOutcome::EMPTY_SCOPE), so a run scoped to a group holding nothing
    // never reaches active_slot at all. The point of this case is a run that
    // DID start against a scope this project cannot work, which is what a
    // deleted group leaves behind.
    $A->query("INSERT INTO redcap_record_list (project_id, record, dag_id) VALUES ("
        . $PID . ", 'R4', 42)");
    $foreign = $planner->plan($PID, array_merge($req, array('dagFilter' => '42')));
    check('dag: a run may be planned against any scope the planner is handed',
        $foreign['ok'] === true);
    $svc->activeRun($PID, $scope7);
    $fg = $dbA->select('SELECT terminal FROM ' . Schema::table('scan_run')
        . ' WHERE run_id = ?', array((int) $foreign['run']['run_id']));
    check('dag: but a group belonging to another project is not a scope this one can work',
        isset($fg[0]) && $fg[0][0] === 'expired');

    // AND THE FAIL-SAFE. With no group table at all the read throws, and a
    // reaper that retired runs on a failed lookup would destroy live work -
    // the one outcome worse than leaving a wedged run for the next request.
    $safe = $planner->plan($PID, array_merge($req, array('dagFilter' => '7')));
    $safeId = (int) $safe['run']['run_id'];
    $A->query('DROP TABLE redcap_data_access_groups');
    $svc->activeRun($PID, array('ok' => true, 'dag' => '31',
        'rights' => array_merge($rights, array('group_id' => 31))));
    $alive = $dbA->select('SELECT terminal FROM ' . Schema::table('scan_run')
        . ' WHERE run_id = ?', array($safeId));
    check('dag: a group list that cannot be read at all retires nothing',
        isset($alive[0]) && $alive[0][0] === null);
    $store->finish($safeId,
        \INSPIRE\UniversalValidator\Scan\ScanOutcome::derive(array('cancelled' => true)));
}

/**
 * The thinnest module ScanService will accept: a query() over the case's own
 * connection, and a log() that keeps what it was told.
 *
 * ModuleDb reads a mysqli_result through fetch_row(), and exec() asks for
 * SELECT ROW_COUNT() straight afterwards on the same connection - so a shim
 * that hands back the raw result of a real query satisfies both halves without
 * reimplementing either.
 */
class UvDagModule
{
    private $c;
    public $logCalls = array();
    public function __construct($c) { $this->c = $c; }
    public function query($sql, $params = array())
    {
        if (!$params) return $this->c->query($sql);
        $st = $this->c->prepare($sql);
        bindAll($st, array_values($params));
        $st->execute();
        $r = $st->get_result();
        // get_result() answers false for a statement with no result set, which
        // is what every UPDATE here is. ModuleDb reads that as "no rows", and
        // its own ROW_COUNT() probe is where the count comes from.
        return $r === false ? true : $r;
    }
    public function log($m, $p = array()) { $this->logCalls[] = array($m, $p); return count($this->logCalls); }
}
