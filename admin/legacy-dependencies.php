<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/includes/admin_sidebar.php';
ops_require_admin($pdo, 'audit.read');

$bridgeEnabled = ops_legacy_admin_bridge_enabled($pdo);
$deps = ops_bridge_dependency_counts($pdo);
$totalActive = 0;
foreach ($deps as $row) {
    if ($row['count'] > 0) {
        $totalActive += $row['count'];
    }
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Legacy Bridge Status | COG$ Admin</title>
<style>
:root{--bg:#0c1319;--panel:#17212b;--panel2:#1f2c38;--text:#eef2f7;--sub:#9fb0c1;--line:rgba(255,255,255,.08);--warn:#c8901a;--warnb:rgba(200,144,26,.12);--ok:#52b87a;--okb:rgba(82,184,122,.12);--r:18px}
*{box-sizing:border-box} body{margin:0;font-family:Inter,Arial,sans-serif;background:linear-gradient(160deg,var(--bg),#101b25 60%,var(--bg));color:var(--text)}
a{color:#d4b25c;text-decoration:none}
.main{padding:24px 28px}.card{background:linear-gradient(160deg,var(--panel),var(--panel2));border:1px solid var(--line);border-radius:var(--r);overflow:hidden}.card-head{padding:16px 20px;border-bottom:1px solid var(--line);display:flex;justify-content:space-between;gap:12px;align-items:center}.card-body{padding:18px 20px}.pill{display:inline-flex;align-items:center;gap:6px;padding:6px 10px;border-radius:999px;font-size:12px;font-weight:700}.pill.warn{background:var(--warnb);color:var(--warn);border:1px solid rgba(200,144,26,.25)}.pill.ok{background:var(--okb);color:var(--ok);border:1px solid rgba(82,184,122,.25)}
.meta{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px;margin-bottom:16px}.meta .box{padding:14px;border:1px solid var(--line);border-radius:12px;background:rgba(255,255,255,.02)}.meta .k{font-size:11px;color:var(--sub);text-transform:uppercase;letter-spacing:.06em;margin-bottom:6px}.meta .v{font-size:1.4rem;font-weight:800}
table{width:100%;border-collapse:collapse;font-size:13px}th,td{padding:10px 12px;border-bottom:1px solid var(--line);text-align:left}th{font-size:11px;text-transform:uppercase;letter-spacing:.06em;color:var(--sub)} .note{padding:14px;border-radius:12px;border:1px solid rgba(200,144,26,.25);background:var(--warnb);color:var(--text);margin-bottom:16px} ol{margin:0;padding-left:18px;line-height:1.7;color:var(--sub)}
@media(max-width:900px){.meta{grid-template-columns:1fr}}
</style>
</head>
<body>
<div class="admin-shell">
<?php admin_sidebar_render('legacy_dependencies'); ?>
<main class="main">
<?php ops_admin_help_assets_once(); ?>
<?= ops_admin_info_panel(
    'Stage 7 — Audit, diagnostics, and control review',
    'What this page does',
    'Legacy Bridge Status is the retirement-readiness page for old admin-id and bridge-linked write paths. Use it to see whether transitional compatibility is still active and where old references still remain in the schema.',
    [
        'This page is diagnostic only. It does not disable the bridge by itself.',
        'Use it before retiring or tightening transitional auth and write paths.',
        'Counts here help confirm whether the system has truly stopped depending on old identifiers.',
    ]
) ?>

<?= ops_admin_workflow_panel(
    'Typical workflow',
    'This page is usually used late in a remediation cycle, when the operator wants to know whether the bridge can be retired safely.',
    [
        ['title' => 'Check bridge mode', 'body' => 'See whether bridge mode is still enabled at all.'],
        ['title' => 'Review active legacy writes', 'body' => 'Use the counts to see whether old columns still contain live operational references.'],
        ['title' => 'Cross-check Session Check', 'body' => 'Use the related page to verify the current session mapping and role resolution.'],
        ['title' => 'Follow the disable checklist', 'body' => 'Only retire the bridge once the listed conditions are truly met.'],
    ]
) ?>

<?= ops_admin_status_panel(
    'Status guide',
    'The labels here tell you whether the bridge is still acting as a live compatibility layer or is ready to be retired.',
    [
        ['label' => 'Enabled / Transitional', 'body' => 'Compatibility paths are still active and should be treated as part of the live environment.'],
        ['label' => 'Disabled / Retired', 'body' => 'The bridge is no longer active and is no longer needed for normal operation.'],
        ['label' => 'Active legacy writes', 'body' => 'Rows still exist in old bridge-linked columns and need review before retirement.'],
    ]
) ?>

  <div class="card" style="margin-bottom:18px">
    <div class="card-head"><div><div style="font-size:11px;color:var(--sub);text-transform:uppercase;letter-spacing:.06em;margin-bottom:6px">Legacy bridge diagnostics</div><h1 style="margin:0;font-size:1.8rem">Legacy Bridge Status<?= ops_admin_help_button('Legacy Bridge Status', 'This page shows whether bridge mode is still active and where old bridge-linked references are still present in the tracked schema.') ?></h1></div><a href="./session-check.php">Session Check →</a></div>
    <div class="card-body">
      <div class="note"><strong>Read-only diagnostic page.</strong> Use this screen to assess retirement readiness for legacy admin-id and bridge-linked write paths. Do not use it as an operational control surface.</div>
      <div class="meta">
        <div class="box"><div class="k">Bridge mode<?= ops_admin_help_button('Bridge mode', 'Shows whether the legacy bridge is still active as a compatibility layer.') ?></div><div class="v"><?php echo $bridgeEnabled ? 'Enabled' : 'Disabled'; ?></div><div><?php echo $bridgeEnabled ? '<span class="pill warn">Transitional</span>' : '<span class="pill ok">Retired</span>'; ?></div></div>
        <div class="box"><div class="k">Active legacy writes<?= ops_admin_help_button('Active legacy writes', 'The total number of rows still using tracked bridge-linked legacy columns.') ?></div><div class="v"><?php echo number_format($totalActive); ?></div><div style="color:var(--sub)"><?php echo $totalActive === 0 ? 'No active legacy admin-id writes detected.' : 'Legacy admin-id writes still exist in old tables.'; ?></div></div>
        <div class="box"><div class="k">Dependency rows tracked<?= ops_admin_help_button('Dependency rows tracked', 'How many table/column dependency checks are being reported below.') ?></div><div class="v"><?php echo number_format(count($deps)); ?></div><div style="color:var(--sub)">Column-level bridge scan across current schema.</div></div>
      </div>
      <table>
        <tr><th>Legacy table<?= ops_admin_help_button('Legacy table', 'The table that still contains a tracked bridge-linked column.') ?></th><th>Legacy column<?= ops_admin_help_button('Legacy column', 'The exact tracked column being counted for bridge dependency purposes.') ?></th><th>Rows using it<?= ops_admin_help_button('Rows using it', 'The current row count where that legacy-linked column still has active data.') ?></th></tr>
        <?php foreach ($deps as $row): ?>
        <tr>
          <td><?php echo htmlspecialchars($row['table']); ?></td>
          <td><?php echo htmlspecialchars($row['column']); ?></td>
          <td><?php echo htmlspecialchars((string)$row['count']); ?></td>
        </tr>
        <?php endforeach; ?>
      </table>
    </div>
  </div>
  <div class="card">
    <div class="card-head"><h2 style="margin:0;font-size:14px">Safe disable checklist<?= ops_admin_help_button('Safe disable checklist', 'Use this checklist before disabling bridge-linked behavior. It is meant to stop premature retirement of the bridge.') ?></h2></div>
    <div class="card-body">
      <ol>
        <li>Admin login and 2FA succeed via <code>admin_users</code>.</li>
        <li>Approvals, execution, governance, infrastructure, zones, and audit pages all load.</li>
        <li>No critical legacy admin-id write path remains in active operational pages.</li>
        <li><code>legacy_admin_auth_status</code> can be switched to <code>disabled</code>.</li>
      </ol>
    </div>
  </div>
</main>
</div>
</body>
</html>
