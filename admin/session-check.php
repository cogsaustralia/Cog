<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/ops_workflow.php';
require_once __DIR__ . '/includes/admin_sidebar.php';

$pdo = ops_db();
ops_require_admin();

$adminUserId = ops_current_admin_user_id($pdo);
$legacyId = ops_current_legacy_admin_id($pdo);
$roles = ops_current_admin_roles($pdo);
$bridgeEnabled = ops_legacy_admin_bridge_enabled($pdo);
$deps = ops_bridge_dependency_counts($pdo);
$activeDeps = 0;
foreach ($deps as $row) {
    if (($row['count'] ?? 0) > 0) {
        $activeDeps++;
    }
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Session Check | COG$ Admin</title>
<style>
:root{--bg:#0c1319;--panel:#17212b;--panel2:#1f2c38;--text:#eef2f7;--sub:#9fb0c1;--line:rgba(255,255,255,.08);--warn:#c8901a;--warnb:rgba(200,144,26,.12);--ok:#52b87a;--okb:rgba(82,184,122,.12);--r:18px}
*{box-sizing:border-box} body{margin:0;font-family:Inter,Arial,sans-serif;background:linear-gradient(160deg,var(--bg),#101b25 60%,var(--bg));color:var(--text)}
a{color:#d4b25c;text-decoration:none}.main{padding:24px 28px}.card{background:linear-gradient(160deg,var(--panel),var(--panel2));border:1px solid var(--line);border-radius:var(--r);overflow:hidden;margin-bottom:18px}.card-head{padding:16px 20px;border-bottom:1px solid var(--line);display:flex;justify-content:space-between;gap:12px;align-items:center}.card-body{padding:18px 20px}.pill{display:inline-flex;align-items:center;gap:6px;padding:6px 10px;border-radius:999px;font-size:12px;font-weight:700}.pill.warn{background:var(--warnb);color:var(--warn);border:1px solid rgba(200,144,26,.25)}.pill.ok{background:var(--okb);color:var(--ok);border:1px solid rgba(82,184,122,.25)}
.meta{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px;margin-bottom:16px}.meta .box{padding:14px;border:1px solid var(--line);border-radius:12px;background:rgba(255,255,255,.02)}.meta .k{font-size:11px;color:var(--sub);text-transform:uppercase;letter-spacing:.06em;margin-bottom:6px}.meta .v{font-size:1.2rem;font-weight:800} table{width:100%;border-collapse:collapse;font-size:13px}th,td{padding:10px 12px;border-bottom:1px solid var(--line);text-align:left}th{font-size:11px;text-transform:uppercase;letter-spacing:.06em;color:var(--sub)} .note{padding:14px;border-radius:12px;border:1px solid rgba(200,144,26,.25);background:var(--warnb);margin-bottom:16px;color:var(--text)}
@media(max-width:1100px){.meta{grid-template-columns:1fr 1fr}} @media(max-width:700px){.meta{grid-template-columns:1fr}}
</style>
</head>
<body>
<div class="admin-shell">
<?php admin_sidebar_render('session_check'); ?>
<main class="main">
<?php ops_admin_help_assets_once(); ?>
<?= ops_admin_collapsible_help('Page guide & workflow', [
  ops_admin_info_panel(
    'Stage 7 — Audit, diagnostics, and control review',
    'What this page does',
    'Session Check is a read-only diagnostic page that shows which admin identity is currently resolved, whether legacy admin-id mapping is still in use, and how the bridge-linked role view currently looks.',
    [
        'Use this page when admin access appears inconsistent between new and legacy views.',
        'Treat it as a diagnostic status page, not as an operational control surface.',
        'Use it before retiring bridge-linked auth behavior so you can confirm what is still mapped.',
    ]
),
  ops_admin_workflow_panel(
    'Typical workflow',
    'This page helps you verify the current admin identity and bridge state before making auth-retirement decisions.',
    [
        ['title' => 'Confirm admin user', 'body' => 'Check the current admin_user_id and current roles.'],
        ['title' => 'Check legacy mapping', 'body' => 'See whether a legacy admin_id is still resolved for this session.'],
        ['title' => 'Review dependency counts', 'body' => 'Use the table to see where bridge-linked rows still exist.'],
        ['title' => 'Move to Legacy Bridge Status if needed', 'body' => 'Use the related page for the broader retirement-readiness view.'],
    ]
),
  ops_admin_status_panel(
    'Status guide',
    'The labels on this page tell you whether the current admin auth state is fully modern or still partially transitional.',
    [
        ['label' => 'Bridge-linked / Transitional', 'body' => 'A legacy admin-id mapping is still present or the bridge mode is still enabled.'],
        ['label' => 'None / retired', 'body' => 'No legacy admin-id is currently attached to the active session.'],
        ['label' => 'Active dependency rows', 'body' => 'Bridge-linked rows still exist somewhere in the tracked schema.'],
    ]
),
]) ?>
<div class="card">
    <div class="card-head"><div><div style="font-size:11px;color:var(--sub);text-transform:uppercase;letter-spacing:.06em;margin-bottom:6px">Legacy bridge diagnostics</div><h1 style="margin:0;font-size:1.8rem">Session Check<?= ops_admin_help_button('Session Check', 'Use this to verify who the admin session currently resolves to and whether legacy bridge mapping is still attached.') ?></h1></div><a href="./legacy-dependencies.php">Legacy Bridge Status →</a></div>
    <div class="card-body">
      <div class="note"><strong>Read-only diagnostic page.</strong> Use this screen to confirm the current authenticated admin user, legacy bridge mapping, and role resolution before retiring transitional auth paths.</div>
      <div class="meta">
        <div class="box"><div class="k">admin_user_id<?= ops_admin_help_button('admin_user_id', 'The primary admin_users identifier resolved for the current session.') ?></div><div class="v"><?php echo (int)$adminUserId; ?></div></div>
        <div class="box"><div class="k">legacy admin_id<?= ops_admin_help_button('legacy admin_id', 'If present, this means the current session still resolves a legacy admin identifier as part of the bridge path.') ?></div><div class="v"><?php echo $legacyId === null ? '—' : (int)$legacyId; ?></div><div><?php echo $legacyId === null ? '<span class="pill ok">None / retired</span>' : '<span class="pill warn">Bridge-linked</span>'; ?></div></div>
        <div class="box"><div class="k">legacy bridge<?= ops_admin_help_button('legacy bridge', 'Shows whether legacy bridge mode is still enabled for auth and related compatibility paths.') ?></div><div class="v"><?php echo $bridgeEnabled ? 'Enabled' : 'Disabled'; ?></div><div><?php echo $bridgeEnabled ? '<span class="pill warn">Transitional</span>' : '<span class="pill ok">Retired</span>'; ?></div></div>
        <div class="box"><div class="k">roles<?= ops_admin_help_button('roles', 'The current resolved role set for the active admin session.') ?></div><div class="v" style="font-size:1rem"><?php echo htmlspecialchars(implode(', ', $roles) ?: 'none'); ?></div></div>
      </div>
      <table>
        <tr><th>Table<?= ops_admin_help_button('Table', 'The table where a bridge-linked column is being counted.') ?></th><th>Column<?= ops_admin_help_button('Column', 'The specific legacy-linked column being checked in that table.') ?></th><th>Count<?= ops_admin_help_button('Count', 'How many rows currently still use the tracked bridge-linked column.') ?></th></tr>
        <?php foreach ($deps as $row): ?>
        <tr>
          <td><?php echo htmlspecialchars($row['table']); ?></td>
          <td><?php echo htmlspecialchars($row['column']); ?></td>
          <td><?php echo htmlspecialchars((string)$row['count']); ?></td>
        </tr>
        <?php endforeach; ?>
      </table>
      <p style="margin-top:12px;color:var(--sub);font-size:12px">Tracked bridge-linked rows with non-zero counts: <strong style="color:var(--text)"><?= (int)$activeDeps ?></strong></p>
    </div>
  </div>
</main>
</div>
</body>
</html>
