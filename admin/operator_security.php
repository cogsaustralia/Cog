<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/admin_paths.php';
require_once __DIR__ . '/includes/ops_workflow.php';

ops_require_admin();
$pdo = ops_db();
$flash = null;
$flashType = 'ok';

$admin = function_exists('ops_current_admin_user') ? ops_current_admin_user($pdo) : null;
$adminId = (int)($admin['id'] ?? ($_SESSION['admin_id'] ?? 0));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    admin_csrf_verify();
    $action = (string)($_POST['action'] ?? '');
    try {
        if ($action === 'clear_lockouts') {
            $deleted = function_exists('ops_clear_auth_rate_limits') ? ops_clear_auth_rate_limits($pdo) : 0;
            if (function_exists('ops_record_admin_security_event')) {
                ops_record_admin_security_event($pdo, $adminId ?: null, 'auth_rate_limits_cleared', 'medium', ['deleted_rows' => $deleted]);
            }
            $flash = 'Cleared ' . $deleted . ' auth rate-limit row' . ($deleted === 1 ? '' : 's') . '.';
        } elseif ($action === 'change_password') {
            if (!$admin) {
                throw new RuntimeException('Current admin account could not be resolved.');
            }
            $current = (string)($_POST['current_password'] ?? '');
            $new = (string)($_POST['new_password'] ?? '');
            $confirm = (string)($_POST['confirm_password'] ?? '');

            if ($current === '' || $new === '' || $confirm === '') {
                throw new RuntimeException('Enter your current password and both new password fields.');
            }
            if (!password_verify($current, (string)$admin['password_hash'])) {
                throw new RuntimeException('Current password is incorrect.');
            }
            if ($new !== $confirm) {
                throw new RuntimeException('New password and confirmation do not match.');
            }
            if (strlen($new) < 12) {
                throw new RuntimeException('Admin password must be at least 12 characters.');
            }
            $hash = password_hash($new, PASSWORD_BCRYPT, ['cost' => 12]);
            $stmt = $pdo->prepare('UPDATE admin_users SET password_hash = ?, updated_at = NOW() WHERE id = ? LIMIT 1');
            $stmt->execute([$hash, (int)$admin['id']]);
            if (function_exists('ops_clear_auth_rate_limits')) {
                ops_clear_auth_rate_limits($pdo);
            }
            if (function_exists('ops_record_admin_security_event')) {
                ops_record_admin_security_event($pdo, (int)$admin['id'], 'password_rotated', 'high');
            }
            $flash = 'Password updated and stale auth lockouts cleared.';
        }
    } catch (Throwable $e) {
        $flash = $e->getMessage();
        $flashType = 'err';
    }
    $admin = function_exists('ops_current_admin_user') ? ops_current_admin_user($pdo) : null;
}

$rateRows = function_exists('ops_auth_rate_limit_rows') ? ops_auth_rate_limit_rows($pdo) : [];
$securityEvents = function_exists('ops_recent_admin_security_events') ? ops_recent_admin_security_events($pdo, 12) : [];

$fmtDt = static function (?string $v): string {
    if (!$v) return '—';
    try { return (new DateTimeImmutable($v))->format('d M Y H:i'); } catch (Throwable $e) { return (string)$v; }
};
$h = static function ($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); };

ob_start();
ops_admin_help_assets_once();
?>
<style>
.ops-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:18px}
.ops-card{background:linear-gradient(160deg,#17212b,#1f2c38);border:1px solid rgba(255,255,255,.08);border-radius:18px;overflow:hidden}
.ops-card-head{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:16px 20px;border-bottom:1px solid rgba(255,255,255,.08)}
.ops-card-head h2{font-size:14px;font-weight:700;margin:0}
.ops-body{padding:18px 20px}
.meta-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px;margin-bottom:14px}
.meta-grid .label{display:block;font-size:11px;text-transform:uppercase;letter-spacing:.06em;color:#9fb0c1;margin-bottom:4px}
.meta-grid strong{font-size:14px;color:#eef2f7}
.hint{font-size:12px;line-height:1.6;color:#9fb0c1}
.form-stack{display:grid;gap:12px}
.form-stack label span{display:block;font-size:11px;text-transform:uppercase;letter-spacing:.06em;color:#9fb0c1;margin-bottom:6px}
.form-stack input{width:100%;padding:10px 12px;border-radius:10px;border:1px solid var(--line);background:var(--panel2);color:var(--text)}
.actions{display:flex;justify-content:flex-start}
.btn{display:inline-block;padding:9px 14px;border-radius:10px;font-size:13px;font-weight:700;border:1px solid rgba(255,255,255,.08);cursor:pointer}
.btn-gold{background:#d4b25c;color:#201507;border-color:rgba(212,178,92,.3)}
.btn-ghost{background:rgba(255,255,255,.06);color:#eef2f7}
.ops-chip{display:inline-flex;align-items:center;padding:5px 10px;border-radius:999px;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.06em}
.ops-chip.ok{background:rgba(82,184,122,.12);color:#52b87a;border:1px solid rgba(82,184,122,.22)}
.ops-chip.warn{background:rgba(200,144,26,.12);color:#c8901a;border:1px solid rgba(200,144,26,.22)}
.table-wrap{overflow:auto}
table{width:100%;border-collapse:collapse;font-size:12px}
th,td{text-align:left;padding:10px 8px;border-bottom:1px solid rgba(255,255,255,.08)}
th{font-size:11px;text-transform:uppercase;letter-spacing:.06em;color:#9fb0c1}
.muted{color:#9fb0c1}
@media (max-width: 980px){.ops-grid{grid-template-columns:1fr}.meta-grid{grid-template-columns:1fr}}
</style>
<?<?= ops_admin_collapsible_help('Page guide & workflow', [
  ops_admin_info_panel(
    'Stage 7 — Audit, diagnostics, and control review',
    'What this page does',
    'Operator Security is the live admin page for password rotation, login lockout review, and recent admin security events. Use it to keep operator access healthy and controlled.',
    [
        'Use this page when an admin password needs to be rotated through the application rather than directly in the database.',
        'Clear login lockouts only after confirming the block is stale or accidental.',
        'Review security events here before escalating to deeper audit or infrastructure investigation.',
    ]
),
]) ?>
<?<?= ops_admin_collapsible_help('Page guide & workflow', [
  ops_admin_workflow_panel(
    'Typical workflow',
    'Operator security actions are usually short control tasks. They should be documented through the event log and used carefully because they affect who can access the admin plane.',
    [
        ['title' => 'Confirm operator identity', 'body' => 'Review the current admin account details and 2FA state before making any changes.'],
        ['title' => 'Rotate password if needed', 'body' => 'Use the form on this page to replace the live admin password and clear stale lockouts in one controlled step.'],
        ['title' => 'Review lockouts', 'body' => 'Check auth rate limits before clearing them so active abuse is not accidentally ignored.'],
        ['title' => 'Check event history', 'body' => 'Use recent security events to confirm what happened and when.'],
    ]
),
]) ?>
<?<?= ops_admin_collapsible_help('Page guide & workflow', [
  ops_admin_guide_panel(
    'How to use this page',
    'This page is for operator access hygiene, not for broader infrastructure hardening. It is most useful when login or password issues are blocking a real admin user.',
    [
        ['title' => 'Operator security card', 'body' => 'Shows the current admin identity, last login, role, and 2FA state.'],
        ['title' => 'Rotate password', 'body' => 'Use this when the password must be changed inside admin without going to phpMyAdmin or a shell.'],
        ['title' => 'Auth rate limits', 'body' => 'Shows lockouts and login throttling rows so you can see whether access problems are caused by rate limiting.'],
        ['title' => 'Recent security events', 'body' => 'Provides quick traceability for password rotations and other security-related actions recorded by admin.'],
    ]
),
]) ?>
<?<?= ops_admin_collapsible_help('Page guide & workflow', [
  ops_admin_status_panel(
    'Status guide',
    'Security statuses on this page are intentionally simple so operators can tell whether the current access state is healthy or needs attention.',
    [
        ['label' => '2FA enabled', 'body' => 'The current operator account has two-factor authentication active.'],
        ['label' => '2FA disabled', 'body' => 'The current operator account does not have two-factor authentication enabled and should be reviewed.'],
        ['label' => 'Auth rate-limit rows', 'body' => 'These rows show recent login pressure and any active temporary lockouts.'],
        ['label' => 'Security events', 'body' => 'These are the recent operator-security actions already recorded by the system.'],
    ]
),
]) ?>
<div class="ops-grid">
  <section class="ops-card">
    <div class="ops-card-head"><h2>Operator security<?= ops_admin_help_button('Operator security', 'This card identifies the admin account currently using the control plane and its immediate security posture.') ?></h2><span class="ops-chip <?= !empty($admin['two_factor_enabled']) ? 'ok' : 'warn' ?>"><?= !empty($admin['two_factor_enabled']) ? '2FA enabled' : '2FA disabled' ?></span></div>
    <div class="ops-body">
      <div class="meta-grid">
        <div><span class="label">Username</span><strong><?= $h($admin['username'] ?? '—') ?></strong></div>
        <div><span class="label">Email</span><strong><?= $h($admin['email'] ?? '—') ?></strong></div>
        <div><span class="label">Role<?= ops_admin_help_button('Role', 'This is the current admin role profile resolved for the logged-in operator.') ?></span><strong><?= $h($admin['role_name'] ?? '—') ?></strong></div>
        <div><span class="label">Last login<?= ops_admin_help_button('Last login', 'Use this to confirm when the current account last authenticated successfully.') ?></span><strong><?= $h($fmtDt($admin['last_login_at'] ?? null)) ?></strong></div>
      </div>
      <p class="hint">This page lets you rotate the live <code>admin_users</code> password from inside admin and clear stale login lockouts without going back to phpMyAdmin.</p>
    </div>
  </section>

  <section class="ops-card">
    <div class="ops-card-head"><h2>Rotate password<?= ops_admin_help_button('Rotate password', 'Changes the current admin password and records a security event. This is the normal application-level password update path.') ?></h2></div>
    <div class="ops-body">
      <form method="post" class="form-stack">
        <input type="hidden" name="_csrf" value="<?= ops_h(admin_csrf_token()) ?>">
        <input type="hidden" name="action" value="change_password">
        <label><span>Current password</span><input type="password" name="current_password" required></label>
        <label><span>New password<?= ops_admin_help_button('New password requirements', 'The new password must be at least 12 characters and should be unique to this admin account.') ?></span><input type="password" name="new_password" minlength="12" required></label>
        <label><span>Confirm new password</span><input type="password" name="confirm_password" minlength="12" required></label>
        <div class="actions"><button class="btn btn-gold" type="submit">Update password</button></div>
      </form>
    </div>
  </section>

  <section class="ops-card">
    <div class="ops-card-head"><h2>Auth rate limits<?= ops_admin_help_button('Auth rate limits', 'These rows show login throttling and temporary lockouts. Clear them only when you have confirmed the block is stale or accidental.') ?></h2></div>
    <div class="ops-body">
      <form method="post" style="margin-bottom:14px">
        <input type="hidden" name="_csrf" value="<?= ops_h(admin_csrf_token()) ?>">
        <input type="hidden" name="action" value="clear_lockouts">
        <button class="btn btn-ghost" type="submit">Clear login lockouts<?= ops_admin_help_button('Clear login lockouts', 'Removes current auth rate-limit rows. Use this after confirming the account should be allowed to try again.') ?></button>
      </form>
      <div class="table-wrap">
        <table>
          <thead><tr><th>Action<?= ops_admin_help_button('Action', 'The auth path being rate-limited, such as admin-login or related login flows.') ?></th><th>Attempts</th><th>Window start</th><th>Locked until</th></tr></thead>
          <tbody>
          <?php if (!$rateRows): ?>
            <tr><td colspan="4" class="muted">No current admin/login rate-limit rows.</td></tr>
          <?php else: foreach ($rateRows as $row): ?>
            <tr>
              <td><?= $h($row['action'] ?? '') ?></td>
              <td><?= $h((string)($row['attempts'] ?? '0')) ?></td>
              <td><?= $h($fmtDt($row['window_start'] ?? null)) ?></td>
              <td><?= $h($fmtDt($row['locked_until'] ?? null)) ?></td>
            </tr>
          <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </section>

  <section class="ops-card">
    <div class="ops-card-head"><h2>Recent security events<?= ops_admin_help_button('Recent security events', 'This event table provides a quick operator-security trail so you can see what access-related actions the system has already recorded.') ?></h2></div>
    <div class="ops-body">
      <div class="table-wrap">
        <table>
          <thead><tr><th>Time</th><th>Event</th><th>Severity<?= ops_admin_help_button('Event severity', 'Severity indicates how operationally significant the event is. High-severity events usually deserve closer review.') ?></th><th>Admin</th></tr></thead>
          <tbody>
          <?php if (!$securityEvents): ?>
            <tr><td colspan="4" class="muted">No admin security events recorded yet.</td></tr>
          <?php else: foreach ($securityEvents as $ev): ?>
            <tr>
              <td><?= $h($fmtDt($ev['created_at'] ?? null)) ?></td>
              <td><?= $h($ev['event_type'] ?? '') ?></td>
              <td><?= $h($ev['severity'] ?? '') ?></td>
              <td><?= $h($ev['display_name'] ?? $ev['username'] ?? '—') ?></td>
            </tr>
          <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </section>
</div>
<?php
$body = (string)ob_get_clean();
ops_render_page('Admin Security', 'operator_security', $body, $flash, $flashType);
