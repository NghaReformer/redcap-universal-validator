<?php

namespace INSPIRE\UniversalValidator;

/**
 * What this REDCap installation can actually support, and what a scan run on it
 * is therefore ALLOWED TO CLAIM.
 *
 * The scan rebuild rests on three things that are not guaranteed to exist:
 *
 *   - a way to walk the record list in BOUNDED memory, rather than exporting
 *     the project to find out what is in it;
 *   - a monotonic source of "did this record change while I was reading it",
 *     with a change log retained long enough to cover the scan;
 *   - permission to create the module's own tables.
 *
 * Each is probed here, and each probe answers in three states: available, or
 * unavailable WITH A REASON. There is no fourth state where the module guesses.
 * That is the whole point — the module's contract is that a scan which could not
 * see everything must never be presentable as a clean bill of health, and a
 * capability the module merely ASSUMED is the quietest way to break it.
 *
 * The v1.4.0 precedent is why every probe here uses is_callable() rather than
 * method_exists(): the framework serves methods through __call(), for which
 * method_exists() answers false, and that silently disabled @UVUNIQUE in
 * production while every mocked test passed.
 *
 * Read-only throughout. The schema probe asks SHOW GRANTS; it never attempts a
 * trial CREATE TABLE, because a probe with side effects is not a probe.
 */
final class ScanCapabilities
{
    /** A capability that is present. */
    const OK = 'available';
    /** A capability that is absent, degraded, or unprovable. */
    const NO = 'unavailable';

    /** @return array{state:string, via:?string, why:?string} */
    private static function yes($via)
    {
        return ['state' => self::OK, 'via' => $via, 'why' => null];
    }

    /** @return array{state:string, via:?string, why:?string} */
    private static function no($why)
    {
        return ['state' => self::NO, 'via' => null, 'why' => $why];
    }

    /**
     * Can the record list be walked in bounded memory?
     *
     * This is the HARD gate. Without it the only way to learn which records
     * exist is to export them, which is the failure the whole rebuild exists to
     * remove — so an unavailable answer here must stop a scan, never soften into
     * "export everything and hope".
     */
    public static function recordEnumeration($module, $pid)
    {
        // Preferred: REDCap's own record list, which carries record + DAG and no
        // data, so it can be walked by keyset in constant memory.
        // A table EXISTING does not mean it is populated for this project, so
        // the preferred source is probed for a row before it is trusted.
        $probe = self::tableExists($module, 'redcap_record_list');
        if ($probe['state'] === self::OK) {
            $walk = self::probeRecordList($module, $pid);
            if ($walk['state'] === self::OK) return $walk;
        }

        // Fallback: a keyset walk of redcap_data restricted to the record-id
        // field. Bounded, but it needs both a usable query API and a known
        // record-id field.
        $canQuery = self::canQuery($module);
        if ($canQuery['state'] !== self::OK) {
            return self::no('no paged record-list source, and ' . $canQuery['why']);
        }
        if (!self::recordIdField($pid)) {
            return self::no('no paged record-list source, and the record-id field could not be determined');
        }
        // Prove the walk, do not infer it. Bounded record enumeration is the
        // hard implementation gate; a gate that passes because two
        // prerequisites exist is not a gate. One bounded probe query costs
        // nothing and answers the actual question.
        return self::probeKeysetWalk($module, $pid);
    }

    /**
     * Can a record be proved unchanged across a read, and can changes since a
     * point in time be enumerated?
     *
     * Without this a run may still examine every record, but it cannot claim the
     * project did not move underneath it — so completion is capped and
     * incremental mode is refused outright.
     */
    public static function sourceFence($module, $pid)
    {
        $canQuery = self::canQuery($module);
        if ($canQuery['state'] !== self::OK) return self::no($canQuery['why']);

        $tbl = self::logEventTable($module, $pid);
        if ($tbl === null) {
            return self::no('the project\'s log-event table could not be resolved, so record '
                . 'changes cannot be detected');
        }
        // PROVE the fence, do not infer it from a name.
        //
        // Returning available here because a table name matched a pattern was
        // the whole check: it never asked whether the table has the columns a
        // fence needs, whether this project has any rows in it, or whether the
        // ordering column is usable. policy() turns this answer into
        // maxCompletion = 'complete-through-fence' and incremental = true, so a
        // name that merely LOOKS right would license both — which is the same
        // shape as the hard gate passing because two prerequisites exist.
        //
        // One bounded query answers it. A project with rows and a readable
        // maximum ordering value has a fence; anything else does not, and says
        // which part failed.
        try {
            $q = $module->query(
                'SELECT MAX(log_event_id), COUNT(*) FROM ' . $tbl . ' WHERE project_id = ?', [$pid]);
            if (!$q) return self::no('the change log ' . $tbl . ' could not be queried, so a '
                . 'record cannot be proved unchanged across a read');
            $row = self::fetchRow($q);
            if (!$row) return self::no('the change log ' . $tbl . ' returned no result, so a '
                . 'record cannot be proved unchanged across a read');
            $maxId = isset($row[0]) ? $row[0] : null;
            $rows  = isset($row[1]) ? (int) $row[1] : 0;
            if ($rows === 0 || $maxId === null || $maxId === '') {
                // Not an error, and not a fence either. A project with no log
                // history cannot be fenced, and saying so is the point.
                return self::no('the change log ' . $tbl . ' holds no entries for this project, so '
                    . 'there is no ordering to fence a scan against');
            }
            if (!ctype_digit((string) $maxId)) {
                return self::no('the change log ' . $tbl . ' has no usable monotonic ordering '
                    . '(log_event_id was not numeric), so reads cannot be fenced');
            }
            return self::yes($tbl . ' (fenced on log_event_id, ' . $rows . ' entries)');
        } catch (\Throwable $e) {
            return self::no('probing the change log ' . $tbl . ' failed (' . get_class($e)
                . '), so a record cannot be proved unchanged across a read');
        }
    }

    /**
     * May the module create its own tables?
     *
     * Answered from SHOW GRANTS, which is read-only and therefore honest about
     * being an inference: a grant line implies permission, it does not prove the
     * statement would succeed. An unavailable answer is not fatal — the schema
     * can be installed by an administrator — so this reports rather than blocks.
     */
    public static function schemaPrivilege($module)
    {
        $canQuery = self::canQuery($module);
        if ($canQuery['state'] !== self::OK) return self::no($canQuery['why']);
        try {
            $q = $module->query('SHOW GRANTS FOR CURRENT_USER()', []);
            if (!$q) return self::no('SHOW GRANTS returned nothing');
            $all = '';
            while ($row = self::fetchRow($q)) $all .= ' | ' . (isset($row[0]) ? $row[0] : '');
            $all = strtoupper($all);
            if ($all === '') return self::no('SHOW GRANTS returned no rows');
            // Only the privilege LIST is searched, never the ON/TO clauses: a
            // database or user name containing "CREATE" would otherwise match.
            // And bare CREATE is the only grant that permits CREATE TABLE -
            // CREATE TEMPORARY TABLES, CREATE VIEW and CREATE ROUTINE do not.
            foreach (explode(' | ', $all) as $line) {
                if (!preg_match('/^\s*GRANT\s+(.*?)\s+ON\s/i', $line, $m)) continue;
                $privs = preg_split('/\s*,\s*/', $m[1]);
                foreach ($privs as $priv) {
                    $priv = trim($priv);
                    if ($priv === 'ALL PRIVILEGES' || $priv === 'ALL') return self::yes('SHOW GRANTS');
                    if ($priv === 'CREATE') return self::yes('SHOW GRANTS');
                }
            }
            return self::no('the database user holds no plain CREATE grant, so it cannot create '
                . 'the module\'s tables (CREATE TEMPORARY TABLES, CREATE VIEW and CREATE ROUTINE '
                . 'do not suffice); an administrator must install the schema');
        } catch (\Throwable $e) {
            return self::no('SHOW GRANTS failed: ' . get_class($e));
        }
    }

    /** Is repeating-instrument metadata available for host resolution? */
    public static function repeatMetadata()
    {
        if (is_callable(['\REDCap', 'getRepeatingFormsEvents'])) return self::yes('getRepeatingFormsEvents');
        if (is_callable(['\REDCap', 'isRepeatingForm'])) return self::yes('isRepeatingForm (per form)');
        return self::no('neither repeat-metadata API is exposed; repeat state falls back to bucket presence');
    }

    /** Are event names resolvable, so a report can name an event rather than an id? */
    public static function eventNames()
    {
        if (is_callable(['\REDCap', 'getEventNames'])) return self::yes('getEventNames');
        return self::no('getEventNames is not exposed; events can only be identified by id');
    }

    /** Are DAG names resolvable, so a report can name a group rather than an id? */
    public static function dagNames()
    {
        if (is_callable(['\REDCap', 'getGroupNames'])) return self::yes('getGroupNames');
        return self::no('getGroupNames is not exposed; groups can only be identified by id');
    }

    /** Every probe, in one call. */
    public static function all($module, $pid)
    {
        return [
            'recordEnumeration' => self::recordEnumeration($module, $pid),
            'sourceFence'       => self::sourceFence($module, $pid),
            'schemaPrivilege'   => self::schemaPrivilege($module),
            'repeatMetadata'    => self::repeatMetadata(),
            'eventNames'        => self::eventNames(),
            'dagNames'          => self::dagNames(),
        ];
    }

    /**
     * What the capabilities permit a run to claim.
     *
     * The only direction this function moves is DOWN. A missing capability can
     * lower what a run may say about itself and can never raise it, which is the
     * property that makes "we did not check" impossible to launder into "there
     * was nothing to find".
     *
     * maxCompletion:
     *   'complete-through-fence'  every record examined AND proved stable
     *   'manifest-complete'       every record in the opening list examined,
     *                             with no claim about what moved underneath
     *   'partial'                 neither
     *
     * @return array{mayScan:bool, maxCompletion:string, incremental:bool, limits:string[]}
     */
    public static function policy(array $caps)
    {
        $limits = [];
        $ok = function ($c) use ($caps) {
            return isset($caps[$c]['state']) && $caps[$c]['state'] === self::OK;
        };

        // The hard gate. Nothing downstream is meaningful without it.
        $mayScan = $ok('recordEnumeration');
        if (!$mayScan) {
            $limits[] = 'the record list cannot be read in bounded memory, so no scan may run: '
                . (isset($caps['recordEnumeration']['why']) ? $caps['recordEnumeration']['why'] : 'unknown');
            return ['mayScan' => false, 'maxCompletion' => 'partial', 'incremental' => false,
                    'limits' => $limits];
        }

        $fenced = $ok('sourceFence');
        if (!$fenced) {
            $limits[] = 'records cannot be proved unchanged during the scan, so a run can claim at '
                . 'most that it examined every record on its opening list';
        }
        if (!$ok('repeatMetadata')) {
            $limits[] = 'repeating-instrument metadata is unavailable, so a repeating form with no '
                . 'instances yet cannot be distinguished from an absent one';
        }
        if (!$ok('eventNames')) $limits[] = 'events will be reported by id rather than by name';
        if (!$ok('dagNames'))   $limits[] = 'groups will be reported by id rather than by name';
        if (!$ok('schemaPrivilege')) {
            $limits[] = 'the module cannot create its own tables; an administrator must install the schema';
        }

        return [
            'mayScan'       => true,
            'maxCompletion' => $fenced ? 'complete-through-fence' : 'manifest-complete',
            'incremental'   => $fenced,
            'limits'        => $limits,
        ];
    }

    // -- probes -------------------------------------------------------------

    /** Is $module->query() usable at all on this framework version? */
    private static function canQuery($module)
    {
        if (!is_object($module) || !is_callable([$module, 'query'])) {
            return self::no('the framework does not expose query()');
        }
        return self::yes('query()');
    }

    private static function tableExists($module, $name)
    {
        $canQuery = self::canQuery($module);
        if ($canQuery['state'] !== self::OK) return self::no($canQuery['why']);
        // The name is a literal here, never caller-supplied, but validate anyway:
        // a table name cannot be a bound parameter, so the habit matters more
        // than this one call site.
        if (!preg_match('/^[a-z_][a-z0-9_]*\z/', $name)) return self::no('unusable table name');
        try {
            // information_schema rather than `SHOW TABLES LIKE ?`: SHOW is not
            // preparable in the client protocol, so binding a parameter to it
            // fails on MySQL 5.7 and 8.0 rather than matching. Found by the
            // database matrix on its first run, in Schema::health(); this call
            // site had the same latent bug and is fixed with it.
            $q = $module->query('SELECT COUNT(*) FROM information_schema.tables
                WHERE table_schema = DATABASE() AND table_name = ?', [$name]);
            if (!$q) return self::no($name . ' not found');
            $row = self::fetchRow($q);
            return $row ? self::yes($name) : self::no($name . ' not found');
        } catch (\Throwable $e) {
            return self::no($name . ' could not be checked: ' . get_class($e));
        }
    }

    /** The project's log-event table, validated before it could ever be interpolated. */
    private static function logEventTable($module, $pid)
    {
        try {
            $q = $module->query('SELECT log_event_table FROM redcap_projects WHERE project_id = ?', [$pid]);
            if (!$q) return null;
            $row = self::fetchRow($q);
            // NOT trimmed. A log-table name arriving from redcap_projects with
            // surrounding whitespace is anomalous, and trimming it would both
            // hide that and make the anchor below unobservable — with trim in
            // front of it, '$' and '\z' accept exactly the same set, so nothing
            // could tell a correct pattern from a subtly wrong one.
            $tbl = ($row && isset($row[0])) ? (string) $row[0] : '';
            // REDCap shards this table on large installations. A table name can
            // never be a bound parameter, so it is whitelisted by shape rather
            // than trusted because of where it came from.
            // Anchored with \z rather than $, because PHP's $ ALSO matches
            // immediately before a trailing newline: with $ a value ending in a
            // newline would be accepted. This value cannot be a bound parameter
            // — a table name never can — so it is interpolated, and the anchor
            // is the only thing standing between the log table and the query.
            if ($tbl === '' || !preg_match('/^redcap_log_event[0-9]*\z/', $tbl)) return null;
            return $tbl;
        } catch (\Throwable $e) {
            return null;
        }
    }

    private static function recordIdField($pid)
    {
        try {
            if (is_callable(['\REDCap', 'getRecordIdField'])) {
                $pk = \REDCap::getRecordIdField();
                if (is_string($pk) && $pk !== '') return $pk;
            }
        } catch (\Throwable $e) {
        }
        return null;
    }

    /** mysqli_result, or anything else that can hand back one row. */
    private static function fetchRow($q)
    {
        try {
            if (is_object($q) && is_callable([$q, 'fetch_row'])) return $q->fetch_row();
            if (is_array($q)) return array_shift($q);
        } catch (\Throwable $e) {
        }
        return null;
    }

    /** One bounded read of redcap_record_list, to prove it answers for THIS project. */
    private static function probeRecordList($module, $pid)
    {
        try {
            $q = $module->query('SELECT record FROM redcap_record_list WHERE project_id = ? LIMIT 1', [$pid]);
            if (!$q) return self::no('redcap_record_list exists but returned nothing for this project');
            $row = self::fetchRow($q);
            if (!$row) {
                return self::no('redcap_record_list exists but holds no row for this project, so it '
                    . 'cannot be used to enumerate records here');
            }
            return self::yes('redcap_record_list (probed)');
        } catch (\Throwable $e) {
            return self::no('reading redcap_record_list failed: ' . get_class($e));
        }
    }

    /** One bounded keyset read of redcap_data, to prove the fallback walk works. */
    private static function probeKeysetWalk($module, $pid)
    {
        $pk = self::recordIdField($pid);
        try {
            $q = $module->query(
                'SELECT record FROM redcap_data WHERE project_id = ? AND field_name = ? '
                . 'ORDER BY record LIMIT 1', [$pid, $pk]);
            if (!$q) return self::no('the redcap_data keyset walk returned nothing');
            self::fetchRow($q);      // an empty project is legitimate; the QUERY working is the point
            return self::yes('redcap_data keyset walk (probed)');
        } catch (\Throwable $e) {
            return self::no('the redcap_data keyset walk failed: ' . get_class($e));
        }
    }
}
