<?php
/**
 * tests/mysql/cases/slots.php — the installation-wide worker semaphore.
 *
 * The pool is one resource shared by every project on the server, which is the
 * whole point of it: a cron that ignored browser leases would let the server run
 * 2N workers whenever someone had a tab open. So there is no project axis here
 * to get wrong — and the two states that ARE easy to confuse, an empty pool and
 * a full one, are indistinguishable at the acquire() call and only one of them
 * is something an administrator should wait out.
 *
 * The last block drives a real ScanWorker against a real store on project 950,
 * with 951 planted beside it, because "the server is busy" and "nobody ever
 * provisioned a slot" reached the first live pilot as the same sentence.
 */

use INSPIRE\UniversalValidator\Scan\Schema;

$PID = 950;
$NEIGHBOUR = uv_neighbour($PID);

// -- WorkerSlots: provisioning, renewal, and browser/cron overlap -------------
{
    $slotsA = new \INSPIRE\UniversalValidator\Scan\WorkerSlots($dbA);
    $slotsB = new \INSPIRE\UniversalValidator\Scan\WorkerSlots($dbB);
    $A->query('DELETE FROM ' . Schema::table('scan_worker_slot'));

    // AN UNPROVISIONED POOL IS A LIMIT OF ZERO, not a busy server. The table
    // exists here and holds no rows, which is precisely the state the first live
    // pilot ran in: the scan planned a 39-record manifest and could not do one
    // batch, while the page said the server was busy with other scans.
    check('slots: an empty pool refuses every worker',
        $slotsA->acquire('w1', 1, 60) === null);
    check('slots: and the census says the pool is empty rather than full',
        $slotsA->census()['total'] === 0 && $slotsA->census()['held'] === 0);

    check('slots: provisioning creates the configured number', $slotsA->provision(3) === 3);
    check('slots: and is idempotent - saving settings twice adds nothing',
        $slotsA->provision(3) === 0);
    check('slots: raising the limit adds only the difference', $slotsA->provision(5) === 2);
    // Additive only: lowering must not delete a row that may be leased right now.
    check('slots: LOWERING the limit deletes nothing', $slotsA->provision(2) === 0);
    $c = $slotsA->census();
    check('slots: the census reports what exists', $c['total'] === 5 && $c['held'] === 0);

    // TWO SAVES AT ONCE (W6-N3). Another administrator's save inserts slot 6
    // between this one's MAX read and its INSERT IGNORE. The ignored row was
    // counted as added: the count was of statements sent, not rows the server
    // kept. Connection B writes the row from inside A's read.
    $racing = new class($A, $B) extends MysqliDb {
        private $other; public $raced = false;
        public function __construct($conn, $other) { parent::__construct($conn); $this->other = $other; }
        public function select($sql, array $params = []) {
            $out = parent::select($sql, $params);
            if (!$this->raced && strpos($sql, 'MAX(slot_no)') !== false) {
                $this->raced = true;
                $this->other->query('INSERT INTO ' . Schema::table('scan_worker_slot')
                    . ' (slot_no, epoch) VALUES (6, 0)');
            }
            return $out;
        }
    };
    $raced = (new \INSPIRE\UniversalValidator\Scan\WorkerSlots($racing))->provision(7);
    check('slots: a slot another save wrote first is not counted as added',
        $racing->raced === true && $raced === 1 && $slotsA->census()['total'] === 7);
    // Seven slots by now: the racing save above provisioned up to 7.
    check('slots: and names which are idle above a reduced limit',
        $slotsA->idleAbove(2) === [3, 4, 5, 6, 7]);

    // A LIMIT TYPED INTO A TEXT BOX (R8-4). The setting had no ceiling, and
    // provisioning sent one placeholder a slot in one statement: 70000 was
    // refused by the server ("Prepared statement contains too many
    // placeholders") and no slot was written. The policy now holds it at
    // MAX_PROJECTS and the rows go in WorkerSlots::CHUNK at a time, so the
    // gap here takes more than one statement and every row is counted.
    $A->query('DELETE FROM ' . Schema::table('scan_worker_slot'));
    $cap = \INSPIRE\UniversalValidator\Scan\ScanPolicy::resolve(
        ['scan-system-max-concurrent-projects' => '70000'], [])['maxProjects'];
    check('slots R8-4: a limit of 70000 is held at the ceiling',
        $cap === \INSPIRE\UniversalValidator\Scan\ScanPolicy::MAX_PROJECTS
        && $cap > \INSPIRE\UniversalValidator\Scan\WorkerSlots::CHUNK);
    check('slots R8-4: and provisions exactly that many rows, counted across statements',
        $slotsA->provision($cap) === $cap && $slotsA->census()['total'] === $cap);
    $A->query('DELETE FROM ' . Schema::table('scan_worker_slot') . ' WHERE slot_no > 250');
    check('slots R8-4: a gap that crosses a statement boundary is filled and counted',
        $slotsA->provision($cap) === $cap - 250 && $slotsA->census()['total'] === $cap);
    // Handed straight to provision(), past the policy: the column's range,
    // never a refused statement or a fatal error.
    $A->query('DELETE FROM ' . Schema::table('scan_worker_slot') . ' WHERE slot_no > 10');
    $big = $slotsA->provision(1000000000);
    $top = $A->query('SELECT COUNT(*), MAX(slot_no) FROM ' . Schema::table('scan_worker_slot'))->fetch_row();
    check('slots R8-4: a billion handed to provision() stops at the slot_no range',
        $big === \INSPIRE\UniversalValidator\Scan\WorkerSlots::MAX_SLOT_NO - 10
        && (int) $top[0] === \INSPIRE\UniversalValidator\Scan\WorkerSlots::MAX_SLOT_NO
        && (int) $top[1] === \INSPIRE\UniversalValidator\Scan\WorkerSlots::MAX_SLOT_NO);
    $A->query('DELETE FROM ' . Schema::table('scan_worker_slot'));

    // BROWSER AND CRON COMPETE FOR THE SAME POOL. That is the point of an
    // installation-wide semaphore: a cron that ignored browser leases would let
    // the server run 2N workers whenever someone had a tab open.
    $A->query('DELETE FROM ' . Schema::table('scan_worker_slot'));
    $slotsA->provision(2);
    $browser = $slotsA->acquire('browser-1', 1, 60);
    $cron    = $slotsB->acquire('cron-1', 1, 60);
    check('slots: a browser worker and a cron worker share one pool',
        $browser !== null && $cron !== null);
    check('slots: and the third is refused whichever kind it is',
        $slotsA->acquire('cron-2', 1, 60) === null);

    check('slots: the holder may renew',
        $slotsA->renew($browser['slot_no'], 'browser-1', $browser['epoch'], 60) === true);
    check('slots: a stale epoch may not renew',
        $slotsA->renew($browser['slot_no'], 'browser-1', $browser['epoch'] - 1, 60) === false);
    check('slots: nor may someone else',
        $slotsB->renew($browser['slot_no'], 'cron-1', $browser['epoch'], 60) === false);
    check('slots: the census counts held leases',
        $slotsA->census()['held'] === 2);

    // EXPIRY IS WHAT MAKES THIS A SEMAPHORE RATHER THAN A LEAK: the browser
    // closes and nobody runs any cleanup.
    $A->query('UPDATE ' . Schema::table('scan_worker_slot')
        . " SET expires_at = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 MINUTE)
            WHERE owner = 'browser-1'");
    $taken = $slotsB->acquire('cron-2', 1, 60);
    check('slots: an abandoned lease returns to the pool on its own', $taken !== null);
    check('slots: and the worker that lost it can no longer renew',
        $slotsA->renew($browser['slot_no'], 'browser-1', $browser['epoch'], 60) === false);
    check('slots: nor release it, which would hand away a LIVE lease',
        $slotsA->release($browser['slot_no'], 'browser-1', $browser['epoch']) === false);

    // AND WHAT THE WORKER SAYS ABOUT IT. Real store, real slots, empty pool -
    // no mock can get this wrong, because there is no mock. The distinction
    // matters because the two states are indistinguishable at the acquire()
    // call and only one of them is something an administrator should wait out.
    {
        $A->query('DELETE FROM ' . Schema::table('scan_worker_slot'));
        $store = new \INSPIRE\UniversalValidator\Scan\SqlScanStore($dbA);
        foreach (array('scan_record', 'scan_run') as $t) {
            $A->query('DELETE FROM ' . Schema::table($t));
        }
        // The neighbour first, so the pool this worker finds empty is empty for
        // the whole installation rather than empty only because one project is
        // the only thing in the schema. Its run holds no slot - nothing has been
        // provisioned yet - which is exactly the state the first live pilot was
        // in when the page told an administrator the server was busy.
        $nb = uv_plant_neighbour($dbA, $PID);
        check('slots: a second project is running beside this one',
            is_array($nb) && uv_neighbour_findings($dbA, $PID) === 2);
        $r = $store->startRun($PID, array('created_by' => 'alice'));
        $rid = (int) $r['run']['run_id'];
        $store->writeManifest($rid, array(
            array('id_bin' => 'R1', 'hash' => hash('sha256', 'R1', true), 'dag' => null)));
        $w = new \INSPIRE\UniversalValidator\Scan\ScanWorker($store, array(
            'slots' => $slotsA, 'owner' => 'w1',
            'read' => function ($ids) { return array('ok' => true, 'data' => array(), 'why' => null); },
            'evaluate' => function ($id, $node) {
                return array('findings' => array(), 'bytes' => 0, 'contexts' => 1, 'why' => null);
            }));
        $res = $w->work($PID, $rid);
        check('slots: with no pool the worker stops rather than reporting contention',
            $res['stop'] === 'unprovisioned');
        check('slots: and says how to fix it, not that the server is busy',
            strpos($res['why'], 'no scan worker slots') !== false
            && strpos($res['why'], 'busy') === false);
        check('slots: and it did no work, so nothing is marked examined',
            $res['worked'] === 0 && (int) $store->run($PID, $rid)['manifest_done'] === 0);

        // Provision one slot and the same worker proceeds: the refusal really
        // was about the pool and not about anything else in the run.
        $slotsA->provision(1);
        $res2 = $w->work($PID, $rid);
        check('slots: provisioning the pool lets the same run proceed',
            $res2['stop'] !== 'unprovisioned');
    }
}
