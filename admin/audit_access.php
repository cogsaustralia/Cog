<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/admin_paths.php';
require_once __DIR__ . '/includes/ops_workflow.php';
require_once __DIR__ . '/includes/admin_sidebar.php';

ops_require_admin();
$pdo = ops_db();

$canManage = ops_admin_can($pdo, 'infrastructure.manage') || ops_admin_can($pdo, 'admin.full');
$canAudit  = ops_admin_can($pdo, 'audit.read') || $canManage;

function aa_h(string $v): string { return htmlspecialchars($v, ENT_QUOTES, 'UTF-8'); }
function aa_rows(PDO $p, string $q, array $params = []): array {
    try { $s = $p->prepare($q); $s->execute($params); return $s->fetchAll(PDO::FETCH_ASSOC) ?: []; }
    catch (Throwable $e) { return []; }
}
function aa_val(PDO $p, string $q, array $params = []): mixed {
    try { $s = $p->prepare($q); $s->execute($params); return $s->fetchColumn(); }
    catch (Throwable $e) { return null; }
}

// ── Check required tables exist ───────────────────────────────────────────────
$hasAccessLog   = function_exists('ops_has_table') && ops_has_table($pdo, 'audit_access_log');
$hasAdminRoles  = function_exists('ops_has_table') && ops_has_table($pdo, 'admin_roles');
$hasAdminUsers  = function_exists('ops_has_table') && ops_has_table($pdo, 'admin_users');
$hasRolePerms   = function_exists('ops_has_table') && ops_has_table($pdo, 'admin_role_permissions');

$flash = null; $flashType = 'ok';
if (isset($_GET['flash'])) { $flash = (string)$_GET['flash']; $flashType = (string)($_GET['type'] ?? 'ok'); }

// ── POST actions ──────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $canManage) {
    if (function_exists('admin_csrf_verify')) { admin_csrf_verify(); }
    $action = trim((string)($_POST['action'] ?? ''));

    try {
        // ── Create auditor admin account ──────────────────────────────────────
        if ($action === 'create_auditor') {
            if (!$hasAdminUsers || !$hasAdminRoles) throw new RuntimeException('admin_users or admin_roles table not found.');

            $username    = trim((string)($_POST['username'] ?? ''));
            $email       = trim((string)($_POST['email'] ?? ''));
            $displayName = trim((string)($_POST['display_name'] ?? ''));
            $tempPass    = trim((string)($_POST['temp_password'] ?? ''));

            if ($username === '' || $email === '' || $displayName === '' || $tempPass === '') {
                throw new RuntimeException('All fields are required.');
            }
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new RuntimeException('Invalid email address.');
            }
            if (strlen($tempPass) < 12) {
                throw new RuntimeException('Temporary password must be at least 12 characters.');
            }

            $existing = aa_val($pdo, 'SELECT id FROM admin_users WHERE email = ? OR username = ? LIMIT 1', [$email, $username]);
            if ($existing) throw new RuntimeException('An admin user with this email or username already exists.');

            $hash = password_hash($tempPass, PASSWORD_BCRYPT, ['cost' => 12]);
            $pdo->prepare(
                "INSERT INTO admin_users (username, email, display_name, role_name, password_hash, is_active, created_at, updated_at)
                 VALUES (?, ?, ?, 'auditor', ?, 1, UTC_TIMESTAMP(), UTC_TIMESTAMP())"
            )->execute([$username, $email, $displayName, $hash]);

            $newUserId = (int)$pdo->lastInsertId();

            // Link to auditor role via admin_user_roles if table exists
            if (function_exists('ops_has_table') && ops_has_table($pdo, 'admin_user_roles')) {
                $auditorRoleId = aa_val($pdo, "SELECT id FROM admin_roles WHERE role_key = 'auditor' LIMIT 1");
                if ($auditorRoleId) {
                    $pdo->prepare(
                        "INSERT IGNORE INTO admin_user_roles (admin_user_id, role_id, created_at) VALUES (?, ?, UTC_TIMESTAMP())"
                    )->execute([$newUserId, (int)$auditorRoleId]);
                }
            }

            // Log the creation
            if ($hasAccessLog) {
                $pdo->prepare(
                    "INSERT INTO audit_access_log (admin_user_id, username, access_type, page_or_view, ip_address, notes, created_at)
                     VALUES (?, ?, 'login', 'account_created', ?, ?, UTC_TIMESTAMP())"
                )->execute([
                    $newUserId, $username,
                    (string)($_SERVER['REMOTE_ADDR'] ?? ''),
                    'Auditor account created by admin ' . (string)($_SESSION['admin_user']['display_name'] ?? 'unknown'),
                ]);
            }

            $flash = "Auditor account created: {$username} ({$email}). They can log in with the temporary password.";
            $flashType = 'ok';
        }

        // ── Reset auditor password ────────────────────────────────────────────
        if ($action === 'reset_password') {
            if (!$hasAdminUsers) throw new RuntimeException('admin_users table not found.');
            $userId   = (int)($_POST['user_id'] ?? 0);
            $newPass  = trim((string)($_POST['new_password'] ?? ''));
            if (!$userId || $newPass === '') throw new RuntimeException('User ID and new password required.');
            if (strlen($newPass) < 12) throw new RuntimeException('Password must be at least 12 characters.');

            $user = aa_rows($pdo, "SELECT id, username, role_name FROM admin_users WHERE id = ? AND role_name = 'auditor' LIMIT 1", [$userId]);
            if (empty($user)) throw new RuntimeException('Auditor account not found.');

            $hash = password_hash($newPass, PASSWORD_BCRYPT, ['cost' => 12]);
            $pdo->prepare("UPDATE admin_users SET password_hash = ?, updated_at = UTC_TIMESTAMP() WHERE id = ?")->execute([$hash, $userId]);

            if ($hasAccessLog) {
                $pdo->prepare(
                    "INSERT INTO audit_access_log (admin_user_id, username, access_type, page_or_view, ip_address, notes, created_at)
                     VALUES (?, ?, 'login', 'password_reset', ?, ?, UTC_TIMESTAMP())"
                )->execute([
                    $userId, $user[0]['username'],
                    (string)($_SERVER['REMOTE_ADDR'] ?? ''),
                    'Password reset by admin ' . (string)($_SESSION['admin_user']['display_name'] ?? 'unknown'),
                ]);
            }

            $flash = "Password reset for " . aa_h($user[0]['username']) . ".";
            $flashType = 'ok';
        }

        // ── Deactivate / reactivate auditor ───────────────────────────────────
        if ($action === 'toggle_active') {
            $userId = (int)($_POST['user_id'] ?? 0);
            if (!$userId) throw new RuntimeException('User ID required.');
            $user = aa_rows($pdo, "SELECT id, username, is_active, role_name FROM admin_users WHERE id = ? AND role_name = 'auditor' LIMIT 1", [$userId]);
            if (empty($user)) throw new RuntimeException('Auditor account not found.');
            $newActive = $user[0]['is_active'] ? 0 : 1;
            $pdo->prepare("UPDATE admin_users SET is_active = ?, updated_at = UTC_TIMESTAMP() WHERE id = ?")->execute([$newActive, $userId]);
            $flash = "Account " . $user[0]['username'] . " " . ($newActive ? 'activated' : 'deactivated') . ".";
            $flashType = 'ok';
        }

        // ── Log manual access event ───────────────────────────────────────────
        if ($action === 'log_access' && $hasAccessLog) {
            $userId     = (int)($_POST['log_user_id'] ?? 0);
            $accessType = trim((string)($_POST['log_access_type'] ?? 'view_invariants'));
            $notes      = trim((string)($_POST['log_notes'] ?? ''));
            if (!$userId) throw new RuntimeException('User ID required.');
            $user = aa_rows($pdo, "SELECT username FROM admin_users WHERE id = ? LIMIT 1", [$userId]);
            if (empty($user)) throw new RuntimeException('User not found.');
            $validTypes = ['login','view_invariants','view_ledger','view_balance_sheet','view_reconciliation','export','logout'];
            if (!in_array($accessType, $validTypes, true)) $accessType = 'view_invariants';
            $pdo->prepare(
                "INSERT INTO audit_access_log (admin_user_id, username, access_type, page_or_view, ip_address, notes, created_at)
                 VALUES (?, ?, ?, 'manual_log', ?, ?, UTC_TIMESTAMP())"
            )->execute([$userId, $user[0]['username'], $accessType, (string)($_SERVER['REMOTE_ADDR'] ?? ''), $notes]);
            $flash = "Access event logged for " . aa_h($user[0]['username']) . ".";
            $flashType = 'ok';
        }

    } catch (Throwable $e) {
        $flash = $e->getMessage();
        $flashType = 'err';
    }

    header('Location: ' . admin_url('audit_access.php') . '?flash=' . urlencode($flash) . '&type=' . $flashType);
    exit;
}

// ── Data loads ────────────────────────────────────────────────────────────────
$auditorAccounts = $hasAdminUsers
    ? aa_rows($pdo, "SELECT id, username, email, display_name, is_active, last_login_at, created_at FROM admin_users WHERE role_name = 'auditor' ORDER BY created_at DESC")
    : [];

$recentAccessLog = $hasAccessLog
    ? aa_rows($pdo, "SELECT al.*, au.display_name FROM audit_access_log al LEFT JOIN admin_users au ON au.id = al.admin_user_id ORDER BY al.id DESC LIMIT 50")
    : [];

$auditorPermissions = $hasRolePerms
    ? aa_rows($pdo, "SELECT permission_key FROM admin_role_permissions WHERE role_id = (SELECT id FROM admin_roles WHERE role_key = 'auditor' LIMIT 1) ORDER BY permission_key")
    : [];

// Invariant view read scope
$auditScope = [
    'Invariant views (I1–I12)' => [
        'v_invariant_i1_ringfence','v_invariant_i2_split_exactness','v_invariant_i3_5bizday_transfer',
        'v_invariant_i4_60day_distribution','v_invariant_i5_2bizday_direct_c','v_invariant_i6_partners_pool_nondisposal',
        'v_invariant_i7_anti_capture_cap','v_invariant_i8_fixed_price','v_invariant_i9_no_fiat_redemption',
        'v_invariant_i10_fn_grant_minimum','v_invariant_i11_social_justice','v_invariant_i12_stewardship_lock',
    ],
    'Balance sheet views' => [
        'v_godley_st_a','v_godley_st_b','v_godley_st_c','v_godley_consolidated',
        'v_godley_invariant_status','v_godley_accounts',
    ],
    'Base ledger tables' => [
        'stewardship_accounts','ledger_entries','reconciliation_snapshots',
        'audit_access_log','v_fn_grant_compliance',
    ],
    'Explicitly excluded (PII)' => [
        'members','snft_memberships','payments','kyc_medicare_submissions',
        'wallet_events','admin_users','member_reservation_lines',
    ],
];

// Auto-log this page view if current user is auditor
$currentUser = function_exists('ops_current_admin_user') ? ops_current_admin_user($pdo) : [];
if ($hasAccessLog && !empty($currentUser) && ($currentUser['role_name'] ?? '') === 'auditor') {
    try {
        $pdo->prepare(
            "INSERT INTO audit_access_log (admin_user_id, username, access_type, page_or_view, ip_address, created_at)
             VALUES (?, ?, 'view_invariants', 'admin/audit_access.php', ?, UTC_TIMESTAMP())"
        )->execute([(int)$currentUser['id'], (string)$currentUser['username'], (string)($_SERVER['REMOTE_ADDR'] ?? '')]);
    } catch (Throwable $e) {}
}

$csrfToken = function_exists('admin_csrf_token') ? admin_csrf_token() : '';
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Audit Access | COG$ Admin</title>
<?php ops_admin_help_assets_once(); ?>
<style>
:root {
  --bg:#0c1319; --panel:#17212b; --panel2:#1f2c38;
  --text:#eef2f7; --sub:#9fb0c1; --dim:#6b7f8f;
  --line:rgba(255,255,255,.08); --line2:rgba(255,255,255,.14);
  --gold:#d4b25c; --ok:#52b87a; --okb:rgba(82,184,122,.12);
  --warn:#c8901a; --warnb:rgba(200,144,26,.12);
  --err:#c46060; --errb:rgba(196,96,96,.12);
  --blue:#5a9ed4; --purple:#9b7dd4;
  --r:18px; --r2:12px;
}
* { box-sizing:border-box; margin:0; padding:0; }
body { font-family:Inter,Arial,sans-serif; background:linear-gradient(160deg,var(--bg),#101b25 60%,var(--bg)); color:var(--text); min-height:100vh; }
a { color:inherit; text-decoration:none; }
.main { padding:24px 28px; }
.topbar { display:flex; justify-content:space-between; align-items:flex-start; gap:16px; margin-bottom:26px; flex-wrap:wrap; }
.topbar h1 { font-size:1.9rem; font-weight:700; margin-bottom:6px; }
.topbar p { color:var(--sub); font-size:13px; max-width:580px; }
.btn { display:inline-block; padding:8px 16px; border-radius:10px; font-size:13px; font-weight:700; border:1px solid var(--line2); background:var(--panel2); color:var(--text); cursor:pointer; }
.btn-gold { background:rgba(212,178,92,.15); border-color:rgba(212,178,92,.3); color:var(--gold); }
.btn-sm { padding:5px 12px; font-size:12px; border-radius:8px; }
.btn-danger { background:rgba(196,96,96,.12); border-color:rgba(196,96,96,.3); color:var(--err); }
.card { background:linear-gradient(180deg,var(--panel),var(--panel2)); border:1px solid var(--line); border-radius:var(--r); overflow:hidden; margin-bottom:18px; }
.card-head { display:flex; justify-content:space-between; align-items:center; padding:16px 20px; border-bottom:1px solid var(--line); }
.card-head h2 { font-size:1rem; font-weight:700; }
.card-body { padding:16px 20px; }
.grid2 { display:grid; grid-template-columns:1.2fr .8fr; gap:18px; }
@media(max-width:980px) { .grid2 { grid-template-columns:1fr; } }
table { width:100%; border-collapse:collapse; }
th, td { text-align:left; padding:9px 10px; font-size:13px; border-top:1px solid var(--line); }
th { color:var(--dim); font-weight:600; font-size:.72rem; text-transform:uppercase; letter-spacing:.05em; border-top:none; }
.mono { font-family:monospace; font-size:11.5px; }
.st { display:inline-block; padding:2px 8px; border-radius:5px; font-size:10.5px; font-weight:700; text-transform:uppercase; }
.st-ok { background:var(--okb); color:var(--ok); }
.st-dim { background:rgba(255,255,255,.04); color:var(--dim); }
.st-err { background:var(--errb); color:var(--err); }
.alert { padding:12px 16px; border-radius:var(--r2); margin-bottom:18px; font-size:13px; font-weight:600; }
.alert-ok  { background:var(--okb);   border:1px solid rgba(82,184,122,.3);  color:#a0e8b8; }
.alert-err { background:var(--errb);  border:1px solid rgba(196,96,96,.3);   color:#f0a0a0; }
.alert-warn{ background:var(--warnb); border:1px solid rgba(200,144,26,.3);  color:#e8cc80; }
.form-group { margin-bottom:14px; }
.form-group label { display:block; font-size:11.5px; font-weight:700; color:var(--sub); text-transform:uppercase; letter-spacing:.05em; margin-bottom:5px; }
.form-group input, .form-group select, .form-group textarea { width:100%; background:var(--panel); border:1px solid var(--line2); border-radius:8px; color:var(--text); font-size:13px; padding:8px 12px; font-family:inherit; }
.form-group input:focus, .form-group select:focus { outline:1px solid rgba(212,178,92,.35); }
.scope-group { margin-bottom:14px; }
.scope-group-title { font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.07em; color:var(--sub); margin-bottom:6px; }
.scope-item { display:inline-block; padding:3px 9px; border-radius:6px; font-size:11px; font-family:monospace; margin:2px 3px 2px 0; }
.scope-allowed { background:rgba(82,184,122,.1); border:1px solid rgba(82,184,122,.2); color:var(--ok); }
.scope-denied  { background:rgba(196,96,96,.08); border:1px solid rgba(196,96,96,.2);  color:var(--err); text-decoration:line-through; opacity:.6; }
.empty { color:var(--dim); font-size:13px; padding:20px 0; text-align:center; }
.setup-banner { padding:14px 18px; border-radius:var(--r2); margin-bottom:18px; font-size:13px; background:rgba(200,144,26,.08); border:1px solid rgba(200,144,26,.25); color:#e8cc80; }
.access-type-badge { display:inline-block; padding:2px 7px; border-radius:4px; font-size:10px; font-weight:700; font-family:monospace; background:rgba(90,158,212,.1); color:var(--blue); }
</style>
</head>
<body>
<div class="admin-shell">
<?php admin_sidebar_render('audit_access'); ?>
<main class="main">

<div class="topbar">
  <div>
    <h1>Audit access provisioning<?php echo ops_admin_help_button('Audit access provisioning', 'Godley Spec §7 Phase 6 — provision read-only access to invariant views, balance sheet views, and ledger tables for Foundation auditors and counsel. No PII is exposed. All access is logged.'); ?></h1>
    <p>Read-only access to the 12 constitutional invariant views and 4 Godley balance sheet views for auditors and counsel. Per Godley Specification §7 Phase 6.</p>
  </div>
  <a class="btn" href="<?php echo aa_h(admin_url('accounting.php')); ?>">← Accounting</a>
</div>

<?php if ($flash !== null): ?>
  <div class="alert alert-<?php echo $flashType === 'ok' ? 'ok' : 'err'; ?>"><?php echo aa_h($flash); ?></div>
<?php endif; ?>

<?php if (!$hasAccessLog): ?>
  <div class="setup-banner">
    ⚠ <strong>SQL patch not yet applied.</strong> Run <code>phase6_audit_access.sql</code> in phpMyAdmin to create the <code>audit_access_log</code> table and provision the <code>cogs_auditor</code> database user before proceeding.
  </div>
<?php endif; ?>

<div class="grid2">
<div>

  <!-- Auditor accounts -->
  <div class="card">
    <div class="card-head">
      <h2>Auditor accounts<?php echo ops_admin_help_button('Auditor accounts', 'These admin panel accounts use the Auditor role — read-only access to invariant views, ledger, and balance sheet. They cannot modify any trust data.'); ?></h2>
      <span style="font-size:12px;color:var(--dim)"><?php echo count($auditorAccounts); ?> account<?php echo count($auditorAccounts) !== 1 ? 's' : ''; ?></span>
    </div>
    <?php if (empty($auditorAccounts)): ?>
      <div class="card-body"><p class="empty">No auditor accounts yet. Create one below.</p></div>
    <?php else: ?>
      <div style="overflow-x:auto">
        <table>
          <thead><tr><th>Username</th><th>Name / Email</th><th>Status</th><th>Last login</th><?php if ($canManage): ?><th>Actions</th><?php endif; ?></tr></thead>
          <tbody>
          <?php foreach ($auditorAccounts as $au): ?>
            <tr>
              <td class="mono"><?php echo aa_h($au['username']); ?></td>
              <td>
                <div style="font-weight:600;font-size:12px"><?php echo aa_h($au['display_name']); ?></div>
                <div style="font-size:11px;color:var(--sub)"><?php echo aa_h($au['email']); ?></div>
              </td>
              <td><span class="st <?php echo $au['is_active'] ? 'st-ok' : 'st-err'; ?>"><?php echo $au['is_active'] ? 'Active' : 'Inactive'; ?></span></td>
              <td style="font-size:11.5px;color:var(--sub)"><?php echo $au['last_login_at'] ? aa_h($au['last_login_at']) : '—'; ?></td>
              <?php if ($canManage): ?>
              <td>
                <div style="display:flex;gap:6px;flex-wrap:wrap">
                  <form method="post" style="display:inline">
                    <?php if ($csrfToken): ?><input type="hidden" name="csrf_token" value="<?php echo aa_h($csrfToken); ?>"><?php endif; ?>
                    <input type="hidden" name="action" value="toggle_active">
                    <input type="hidden" name="user_id" value="<?php echo (int)$au['id']; ?>">
                    <button type="submit" class="btn btn-sm <?php echo $au['is_active'] ? 'btn-danger' : ''; ?>"
                      <?php if ($au['is_active']): ?>onclick="return confirm('Deactivate <?php echo aa_h($au['username']); ?>? They will no longer be able to log in.')"<?php endif; ?>>
                      <?php echo $au['is_active'] ? 'Deactivate' : 'Activate'; ?></button>
                  </form>
                  <button class="btn btn-sm" type="button" onclick="openResetForm(<?php echo (int)$au['id']; ?>, '<?php echo aa_h($au['username']); ?>')">Reset PW</button>
                </div>
              </td>
              <?php endif; ?>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>

  <!-- Access log -->
  <div class="card">
    <div class="card-head">
      <h2>Access log<?php echo ops_admin_help_button('Access log', 'Immutable log of auditor access events. Auto-populated on login and page views. Manual entries can be added to record out-of-band access (e.g. direct DB tool access by counsel).'); ?></h2>
      <span style="font-size:12px;color:var(--dim)">Last 50 events</span>
    </div>
    <?php if (!$hasAccessLog): ?>
      <div class="card-body"><p class="empty">audit_access_log table not found. Run SQL patch first.</p></div>
    <?php elseif (empty($recentAccessLog)): ?>
      <div class="card-body"><p class="empty">No access events recorded yet.</p></div>
    <?php else: ?>
      <div style="overflow-x:auto">
        <table>
          <thead><tr><th>Time</th><th>User</th><th>Event</th><th>Notes</th></tr></thead>
          <tbody>
          <?php foreach ($recentAccessLog as $ev): ?>
            <tr>
              <td style="font-size:11px;color:var(--sub);white-space:nowrap"><?php echo aa_h($ev['created_at']); ?></td>
              <td class="mono" style="font-size:11.5px"><?php echo aa_h($ev['username']); ?></td>
              <td><span class="access-type-badge"><?php echo aa_h(str_replace('_', ' ', $ev['access_type'])); ?></span></td>
              <td style="font-size:11.5px;color:var(--sub)"><?php echo aa_h($ev['notes'] ?? $ev['page_or_view'] ?? ''); ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>

</div>
<div>

  <?php if ($canManage): ?>
  <!-- Create auditor account -->
  <div class="card">
    <div class="card-head"><h2>Create auditor account</h2></div>
    <div class="card-body">
      <form method="post">
        <?php if ($csrfToken): ?><input type="hidden" name="csrf_token" value="<?php echo aa_h($csrfToken); ?>"><?php endif; ?>
        <input type="hidden" name="action" value="create_auditor">
        <div class="form-group"><label>Username</label><input type="text" name="username" required placeholder="e.g. auditor_smith" autocomplete="off"></div>
        <div class="form-group"><label>Email</label><input type="email" name="email" required placeholder="auditor@example.com" autocomplete="off"></div>
        <div class="form-group"><label>Display name</label><input type="text" name="display_name" required placeholder="e.g. J. Smith — External Auditor" autocomplete="off"></div>
        <div class="form-group">
          <label>Temporary password <span style="color:var(--dim);font-weight:400">(min 12 chars — share securely)</span></label>
          <input type="text" name="temp_password" required minlength="12" autocomplete="new-password" placeholder="min 12 characters">
        </div>
        <button type="submit" class="btn btn-gold">Create auditor account</button>
      </form>
    </div>
  </div>

  <!-- Reset password panel (JS-toggled) -->
  <div class="card" id="reset-pw-card" style="display:none">
    <div class="card-head">
      <h2 id="reset-pw-title">Reset password</h2>
      <button class="btn btn-sm" type="button" onclick="document.getElementById('reset-pw-card').style.display='none'">Cancel</button>
    </div>
    <div class="card-body">
      <form method="post">
        <?php if ($csrfToken): ?><input type="hidden" name="csrf_token" value="<?php echo aa_h($csrfToken); ?>"><?php endif; ?>
        <input type="hidden" name="action" value="reset_password">
        <input type="hidden" name="user_id" id="reset-user-id" value="">
        <div class="form-group"><label>New password <span style="color:var(--dim);font-weight:400">(min 12 chars)</span></label><input type="text" name="new_password" required minlength="12" autocomplete="new-password" placeholder="min 12 characters"></div>
        <button type="submit" class="btn btn-gold">Reset password</button>
      </form>
    </div>
  </div>

  <!-- Log manual access event -->
  <?php if ($hasAccessLog && !empty($auditorAccounts)): ?>
  <div class="card">
    <div class="card-head"><h2>Log manual access event<?php echo ops_admin_help_button('Log manual access', 'Use this to record direct DB tool access by auditors or counsel outside the admin panel — e.g. MySQL Workbench sessions, DBeaver connections, or email queries.'); ?></h2></div>
    <div class="card-body">
      <form method="post">
        <?php if ($csrfToken): ?><input type="hidden" name="csrf_token" value="<?php echo aa_h($csrfToken); ?>"><?php endif; ?>
        <input type="hidden" name="action" value="log_access">
        <div class="form-group">
          <label>Auditor</label>
          <select name="log_user_id">
            <?php foreach ($auditorAccounts as $au): ?>
              <option value="<?php echo (int)$au['id']; ?>"><?php echo aa_h($au['display_name']); ?> (<?php echo aa_h($au['username']); ?>)</option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group">
          <label>Access type</label>
          <select name="log_access_type">
            <option value="view_invariants">View invariants</option>
            <option value="view_ledger">View ledger</option>
            <option value="view_balance_sheet">View balance sheet</option>
            <option value="view_reconciliation">View reconciliation</option>
            <option value="export">Export</option>
            <option value="login">Login</option>
            <option value="logout">Logout</option>
          </select>
        </div>
        <div class="form-group"><label>Notes <span style="color:var(--dim);font-weight:400">(tool used, purpose, etc.)</span></label><textarea name="log_notes" rows="2" placeholder="e.g. DBeaver connection — quarterly invariant review per deed cl.38"></textarea></div>
        <button type="submit" class="btn btn-sm btn-gold">Log event</button>
      </form>
    </div>
  </div>
  <?php endif; ?>
  <?php endif; ?>

  <!-- Read scope reference -->
  <div class="card">
    <div class="card-head"><h2>Auditor read scope<?php echo ops_admin_help_button('Auditor read scope', 'This is the complete set of database objects auditors can SELECT from via the cogs_auditor DB user or the auditor admin panel role. No PII tables are included.'); ?></h2></div>
    <div class="card-body">
      <?php foreach ($auditScope as $groupLabel => $items):
        $isDenied = str_contains($groupLabel, 'Excluded');
      ?>
        <div class="scope-group">
          <div class="scope-group-title"><?php echo aa_h($groupLabel); ?></div>
          <?php foreach ($items as $item): ?>
            <span class="scope-item <?php echo $isDenied ? 'scope-denied' : 'scope-allowed'; ?>"><?php echo aa_h($item); ?></span>
          <?php endforeach; ?>
        </div>
      <?php endforeach; ?>

      <div style="margin-top:16px;padding-top:14px;border-top:1px solid var(--line)">
        <div style="font-size:11px;font-weight:700;color:var(--sub);text-transform:uppercase;letter-spacing:.06em;margin-bottom:8px">Auditor role permissions</div>
        <?php if (empty($auditorPermissions)): ?>
          <p style="font-size:12px;color:var(--dim)">Run SQL patch to confirm permissions.</p>
        <?php else: ?>
          <?php foreach ($auditorPermissions as $p): ?>
            <span class="scope-item scope-allowed"><?php echo aa_h($p['permission_key']); ?></span>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>

      <div style="margin-top:14px;padding:10px 14px;background:rgba(255,255,255,.02);border-radius:8px;border:1px solid var(--line);font-size:12px;color:var(--sub);line-height:1.6">
        <strong style="color:var(--text);display:block;margin-bottom:4px">Direct DB access (cogs_auditor user)</strong>
        The <code>cogs_auditor</code> MariaDB user is provisioned by the SQL patch with SELECT-only grants on the objects above. Auditors and counsel can connect via MySQL Workbench, DBeaver, or similar using the hostname <code>localhost</code> and credentials provided separately. Log all direct DB sessions manually above.
      </div>
    </div>
  </div>

</div>
</div>

</main>
</div>

<script>
function openResetForm(userId, username) {
  document.getElementById('reset-user-id').value = userId;
  document.getElementById('reset-pw-title').textContent = 'Reset password — ' + username;
  document.getElementById('reset-pw-card').style.display = '';
  document.getElementById('reset-pw-card').scrollIntoView({behavior:'smooth', block:'start'});
}
</script>
</body>
</html>
