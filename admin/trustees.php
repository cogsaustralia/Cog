<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/admin_paths.php';
require_once __DIR__ . '/includes/ops_workflow.php';
require_once __DIR__ . '/includes/admin_sidebar.php';

ops_require_admin();
$pdo = ops_db();

function tr_h(string $v): string { return htmlspecialchars($v, ENT_QUOTES, 'UTF-8'); }

$message = '';
$error   = '';
if (isset($_GET['msg'])) $message = tr_h(urldecode((string)$_GET['msg']));

// ── Fetch all trustees ────────────────────────────────────────────────────────
$stmt = $pdo->prepare('SELECT * FROM trustees ORDER BY sub_trust_context, status, appointment_date');
$stmt->execute();
$trustees = $stmt->fetchAll(PDO::FETCH_ASSOC);

$typeLabels = [
    'caretaker_trustee'  => 'Caretaker Trustee',
    'individual_trustee' => 'Individual Trustee',
    'managing_director'  => 'Managing Director',
    'director'           => 'Director',
];
$subTrustLabels = [
    'sub_trust_a' => 'Sub-Trust A',
    'sub_trust_b' => 'Sub-Trust B',
    'sub_trust_c' => 'Sub-Trust C',
    'all'         => 'All Sub-Trusts',
];
$statusBadge = [
    'active'    => ['badge-ok',   'Active'],
    'resigned'  => ['badge-warn', 'Resigned'],
    'removed'   => ['badge-err',  'Removed'],
    'suspended' => ['badge-err',  'Suspended'],
];
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Trustees Register | COG$ Admin</title>
<?php if (function_exists('ops_admin_help_assets_once')) ops_admin_help_assets_once(); ?>
<style>
.main { padding: 24px 28px; }
.topbar h2 { font-size: 1.1rem; font-weight: 700; margin: 0 0 4px; }
.topbar p  { color: var(--sub); font-size: 13px; max-width: 680px; }
.badge { font-size: .7rem; font-weight: 700; padding: 3px 9px; border-radius: 20px; white-space: nowrap; }
.badge-ok   { background: var(--okb);   color: var(--ok);   border: 1px solid rgba(82,184,122,.3); }
.badge-warn { background: var(--warnb); color: var(--warn); border: 1px solid rgba(212,148,74,.3); }
.badge-err  { background: var(--errb);  color: var(--err);  border: 1px solid rgba(192,85,58,.3); }
.trustee-card {
  background: var(--panel2); border: 1px solid var(--line2);
  border-radius: 10px; padding: 0; margin-bottom: 16px; overflow: hidden;
}
.trustee-card.active { border-color: rgba(82,184,122,.25); }
.trustee-head {
  display: flex; justify-content: space-between; align-items: center;
  padding: 13px 18px; border-bottom: 1px solid var(--line);
  flex-wrap: wrap; gap: 8px;
}
.trustee-head h3 { font-size: .88rem; font-weight: 700; margin: 0; color: var(--text); }
.trustee-head .sub { font-size: .75rem; color: var(--sub); margin-top: 2px; }
.trustee-body { padding: 14px 18px; }
.dg { display: grid; grid-template-columns: 190px 1fr; gap: 5px 12px; font-size: .81rem; }
.dg-l { color: var(--dim); }
.dg-v { color: var(--text); word-break: break-word; }
.dg-v.mono { font-family: monospace; font-size: .78rem; }
.dg-v.gold { color: var(--gold); }
.msg-ok  { background: var(--okb);  border: 1px solid rgba(82,184,122,.3); color: var(--ok);  border-radius: 7px; padding: 10px 14px; font-size: .83rem; margin-bottom: 14px; }
.msg-err { background: var(--errb); border: 1px solid rgba(192,85,58,.3);  color: var(--err); border-radius: 7px; padding: 10px 14px; font-size: .83rem; margin-bottom: 14px; }
.notice { background: var(--warnb); border: 1px solid rgba(212,148,74,.3); border-radius: 8px; padding: 12px 16px; font-size: .82rem; color: var(--warn); margin-bottom: 18px; }
</style>
</head>
<body>
<div class="admin-shell">
<?php admin_sidebar_render('trustees_register'); ?>
<div class="main">

<?php if ($message): ?><div class="msg-ok"><?= $message ?></div><?php endif; ?>
<?php if ($error):   ?><div class="msg-err"><?= $error ?></div><?php endif; ?>

<div class="topbar" style="margin-bottom:18px">
  <h2>👔 Trustees Register</h2>
  <p>
    All trustees of the COGS of Australia Foundation CJVM Hybrid Trust, including current and historical.
    New trustees may only be added after an executed TDR or deed authorising their appointment.
    Appointment references must resolve to an executed record.
  </p>
</div>

<div class="notice">
  ℹ Board meetings are not applicable while a single Caretaker Trustee is in office.
  The Board Meeting infrastructure activates on appointment of a second Trustee under Declaration cl.1.8.
</div>

<?php foreach ($trustees as $t):
  [$bc, $bl] = $statusBadge[$t['status']] ?? ['badge-warn', $t['status']];
?>
<div class="trustee-card <?= $t['status'] === 'active' ? 'active' : '' ?>">
  <div class="trustee-head">
    <div>
      <h3><?= tr_h($t['full_name']) ?></h3>
      <div class="sub">
        <?= tr_h($typeLabels[$t['trustee_type']] ?? $t['trustee_type']) ?>
        &nbsp;·&nbsp;
        <?= tr_h($subTrustLabels[$t['sub_trust_context']] ?? $t['sub_trust_context']) ?>
      </div>
    </div>
    <span class="badge <?= $bc ?>"><?= $bl ?></span>
  </div>
  <div class="trustee-body">
    <div class="dg">
      <span class="dg-l">UUID</span><span class="dg-v mono"><?= tr_h($t['trustee_uuid']) ?></span>
      <span class="dg-l">OTP Email</span><span class="dg-v gold"><?= tr_h($t['email']) ?></span>
      <span class="dg-l">Address</span><span class="dg-v"><?= tr_h($t['address'] ?? '—') ?></span>
      <span class="dg-l">Appointment Date</span><span class="dg-v"><?= tr_h($t['appointment_date']) ?></span>
      <span class="dg-l">Appointment Instrument</span>
      <span class="dg-v mono"><?= tr_h($t['appointment_instrument_ref'] ?? '—') ?></span>
      <?php if ($t['status'] !== 'active' && $t['status_date']): ?>
        <span class="dg-l">Status Date</span><span class="dg-v"><?= tr_h($t['status_date']) ?></span>
      <?php endif; ?>
      <?php if ($t['notes']): ?>
        <span class="dg-l">Notes</span><span class="dg-v"><?= tr_h($t['notes']) ?></span>
      <?php endif; ?>
    </div>
  </div>
</div>
<?php endforeach; ?>

<?php if (empty($trustees)): ?>
  <p style="color:var(--sub);font-size:.85rem">No trustees found.</p>
<?php endif; ?>

<p style="font-size:.75rem;color:var(--dim);margin-top:24px">
  New Trustee addition requires an executed appointment instrument reference (TDR or deed).
  Contact the system administrator to add a new trustee row after the instrument is executed.
</p>

</div>
</div>
</body>
</html>
