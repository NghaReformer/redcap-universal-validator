<?php
/**
 * Validation scan — run every configured rule over every saved record.
 *
 * The retrospective sweep the per-save audit cannot give you: legacy data,
 * Data Import Tool and API writes (save-hook coverage is version-dependent),
 * and records entered before a rule existed. Read-only: this page never
 * writes data, so it runs on GET.
 *
 * Access: design rights required (re-checked here; the sidebar link is
 * already limited by redcap_module_link_check_display). A user working
 * inside a Data Access Group only ever sees their own group's records.
 * Stored VALUES are deliberately not shown — the report names where the
 * problem is (record / event / instance / field / reason); the value itself
 * stays behind REDCap's own access control on the record pages.
 *
 * All logic lives in UniversalValidator::scanProject() (unit-tested via
 * tests/hook_php.php); this file is presentation only.
 */

namespace INSPIRE\UniversalValidator;

/** @var UniversalValidator $module */

$pid = $module->getProjectId();
if (!$pid) { echo 'This page only works inside a project.'; return; }

// -- rights: design rights, and DAG confinement ------------------------------
$dagFilter = null;
try {
    $user = $module->getUser();
    if (!$user || !method_exists($user, 'hasDesignRights') || !$user->hasDesignRights()) {
        echo '<div class="red" style="margin:20px;padding:10px">You need project design rights to run the validation scan.</div>';
        return;
    }
    if (method_exists($user, 'getRights')) {
        $rights = $user->getRights($pid);
        if (is_array($rights) && !empty($rights['group_id'])) {
            $gd = null;
            try {
                if (is_callable(['\REDCap', 'getGroupNames'])) {
                    $g = \REDCap::getGroupNames(true, $rights['group_id']);
                    if (is_string($g) && $g !== '') $gd = $g;
                }
            } catch (\Throwable $e) {
            }
            // A DAG-bound user with an unresolvable DAG name scans NOTHING
            // rather than everything — the conservative direction.
            $dagFilter = ($gd !== null) ? $gd : '__unresolvable__';
        }
    }
} catch (\Throwable $e) {
    echo '<div class="red" style="margin:20px;padding:10px">Could not verify your rights — scan not run.</div>';
    return;
}

$run = isset($_GET['run']) && $_GET['run'] === '1';
$csv = isset($_GET['csv']) && $_GET['csv'] === '1';

/** HTML-escape helper. */
function uv_h($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }
/** CSV cell: quote, and defuse spreadsheet formula injection. */
function uv_csv($s)
{
    $s = (string) $s;
    if ($s !== '' && strpos('=+-@', $s[0]) !== false) $s = "'" . $s;
    return '"' . str_replace('"', '""', $s) . '"';
}

if ($run || $csv) {
    $result = $module->scanProject($pid, $dagFilter);
}

if ($csv) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="validation_scan_pid' . (int) $pid . '_' . date('Ymd_His') . '.csv"');
    $rows = ['record,event_id,instance,field,rule,type,reason'];
    foreach ($result['violations'] as $v) {
        $rows[] = implode(',', [uv_csv($v['record']), uv_csv($v['event_id']), uv_csv($v['instance']),
            uv_csv($v['field']), uv_csv($v['rule']), uv_csv($v['type']), uv_csv($v['reason'])]);
    }
    echo implode("\n", $rows) . "\n";
    exit;
}

$self = $module->getUrl('pages/scan.php');
?>
<h4 style="margin-top:12px"><i class="fas fa-magnifying-glass"></i> Validation scan — Universal Regex &amp; Check-Character Validator</h4>
<p style="max-width:760px">
Runs <b>every configured rule</b> (check-character / format, constraint, required, unique)
over <b>every saved record</b> and lists each violation. This covers what live
form validation cannot: values imported through the Data Import Tool or the API,
and records entered before a rule existed. The report shows <i>where</i> each
problem is — never the stored value itself.
<?php if ($dagFilter !== null) { ?>
<br><b>Scope:</b> records in your Data Access Group only.
<?php } ?>
</p>

<?php if (!$run) { ?>
<p>
  <a class="btn btn-primary btn-sm" href="<?php echo uv_h($self . '&run=1'); ?>">Run the scan now</a>
</p>
<p style="color:#666;max-width:700px;font-size:12px">Records are read in chunks; on a very large
project the scan may take a while — leave the page open until the table appears.</p>
<?php } else { ?>

<p>
  Scanned <b><?php echo (int) $result['stats']['records']; ?></b> record(s),
  <b><?php echo (int) $result['stats']['contexts']; ?></b> row(s), against
  <b><?php echo (int) $result['stats']['rules']; ?></b> rule(s) —
  <b style="color:<?php echo $result['violations'] ? '#c62828' : '#2e7d32'; ?>">
    <?php echo count($result['violations']); ?> violation(s)</b>.
  <?php if ($result['violations']) { ?>
    &nbsp;<a class="btn btn-defaultrc btn-xs" href="<?php echo uv_h($self . '&csv=1'); ?>">Download CSV</a>
  <?php } ?>
  &nbsp;<a class="btn btn-defaultrc btn-xs" href="<?php echo uv_h($self . '&run=1'); ?>">Re-run</a>
</p>

<?php if ($result['unconfigurable']) { ?>
<div style="margin:8px 0;padding:8px 12px;border:1px solid #e0b4b0;background:#fbeceb;color:#c62828;border-radius:4px;max-width:760px">
  <b>&#9888; Rule problems (these rules could not be fully evaluated):</b>
  <ul style="margin:4px 0 0 18px">
  <?php foreach ($result['unconfigurable'] as $u) { ?>
    <li>Rule <?php echo (int) $u['rule']; ?> (<?php echo uv_h(implode(', ', $u['fields'])); ?>):
        <?php echo uv_h($u['why']); ?></li>
  <?php } ?>
  </ul>
</div>
<?php } ?>

<?php if ($result['violations']) { ?>
<table class="table table-striped table-sm" style="max-width:900px">
  <thead><tr>
    <th>Record</th><th>Event</th><th>Instance</th><th>Field</th><th>Rule</th><th>Kind</th><th>Reason</th>
  </tr></thead>
  <tbody>
  <?php foreach ($result['violations'] as $v) { ?>
    <tr>
      <td><?php echo uv_h($v['record']); ?></td>
      <td><?php echo uv_h($v['event_id']); ?></td>
      <td><?php echo uv_h($v['instance']); ?></td>
      <td><?php echo uv_h($v['field']); ?></td>
      <td><?php echo (int) $v['rule']; ?></td>
      <td><?php echo uv_h($v['type']); ?></td>
      <td><?php echo uv_h($v['reason']); ?></td>
    </tr>
  <?php } ?>
  </tbody>
</table>
<?php } else { ?>
<p style="color:#2e7d32"><b>&#10003; No violations found.</b></p>
<?php } ?>

<?php } ?>
