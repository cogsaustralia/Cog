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

// ── POST: update trustee record ───────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['_action'] ?? '') === 'update_trustee') {
    $uuid = trim($_POST['trustee_uuid'] ?? '');
    try {
        if ($uuid === '') throw new \RuntimeException('Trustee UUID missing.');
        $fullName   = trim($_POST['full_name']   ?? '');
        $dob        = trim($_POST['date_of_birth'] ?? '');
        $mobile     = trim($_POST['mobile']      ?? '');
        $personalEmail = trim($_POST['personal_email'] ?? '');
        $address    = trim($_POST['address']     ?? '');
        $apptDate   = trim($_POST['appointment_date'] ?? '');
        $apptRef    = trim($_POST['appointment_instrument_ref'] ?? '');
        $status     = trim($_POST['status']      ?? '');
        $statusDate = trim($_POST['status_date'] ?? '');
        $email      = trim($_POST['email']       ?? '');
        $notes      = trim($_POST['notes']       ?? '');
        if ($fullName === '') throw new \RuntimeException('Full name is required.');
        if ($apptDate === '') throw new \RuntimeException('Appointment date is required.');
        if ($status   === '') throw new \RuntimeException('Status is required.');
        foreach (['date_of_birth' => $dob, 'appointment_date' => $apptDate] as $field => $val) {
            if ($val !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $val)) {
                throw new \RuntimeException("Invalid date format for {$field} — use YYYY-MM-DD.");
            }
        }
        if ($statusDate !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $statusDate)) {
            throw new \RuntimeException('Invalid date format for date ended — use YYYY-MM-DD.');
        }
        $stmt = $pdo->prepare(
            "UPDATE trustees SET
               full_name                  = ?,
               date_of_birth              = ?,
               mobile                     = ?,
               personal_email             = ?,
               address                    = ?,
               appointment_date           = ?,
               appointment_instrument_ref = ?,
               status                     = ?,
               status_date                = ?,
               email                      = ?,
               notes                      = ?,
               updated_at                 = UTC_TIMESTAMP()
             WHERE trustee_uuid = ?"
        );
        $stmt->execute([
            $fullName,
            $dob           !== '' ? $dob           : null,
            $mobile        !== '' ? $mobile        : null,
            $personalEmail !== '' ? $personalEmail : null,
            $address       !== '' ? $address       : null,
            $apptDate,
            $apptRef       !== '' ? $apptRef       : null,
            $status,
            $statusDate    !== '' ? $statusDate    : null,
            $email,
            $notes         !== '' ? $notes         : null,
            $uuid,
        ]);
        if ($stmt->rowCount() === 0) throw new \RuntimeException('No row updated — UUID not found.');
        header('Location: ./trustees.php?msg=' . urlencode('Trustee record updated.'));
        exit;
    } catch (\Throwable $e) {
        $error = $e->getMessage();
    }
}

if (isset($_GET['msg'])) $message = tr_h(urldecode((string)$_GET['msg']));

// ── POST: add new trustee ─────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['_action'] ?? '') === 'add_trustee') {
    try {
        $fullName      = trim($_POST['full_name']                  ?? '');
        $dob           = trim($_POST['date_of_birth']              ?? '');
        $mobile        = trim($_POST['mobile']                     ?? '');
        $personalEmail = trim($_POST['personal_email']             ?? '');
        $address       = trim($_POST['address']                    ?? '');
        $otpEmail      = trim($_POST['email']                      ?? '');
        $trusteeType   = trim($_POST['trustee_type']               ?? '');
        $subTrust      = trim($_POST['sub_trust_context']          ?? '');
        $apptDate      = trim($_POST['appointment_date']           ?? '');
        $apptRef       = trim($_POST['appointment_instrument_ref'] ?? '');
        $notes         = trim($_POST['notes']                      ?? '');

        if ($fullName    === '') throw new \RuntimeException('Full name is required.');
        if ($otpEmail    === '') throw new \RuntimeException('OTP email is required.');
        if ($trusteeType === '') throw new \RuntimeException('Trustee type is required.');
        if ($subTrust    === '') throw new \RuntimeException('Sub-trust context is required.');
        if ($apptDate    === '') throw new \RuntimeException('Appointment date is required.');
        if ($apptRef     === '') throw new \RuntimeException('Appointment instrument reference is required — a TDR or deed must authorise this appointment.');

        if ($dob !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dob)) {
            throw new \RuntimeException('Invalid date of birth format — use YYYY-MM-DD.');
        }

        // Check for duplicate OTP email within sub-trust
        $dup = $pdo->prepare('SELECT id FROM trustees WHERE sub_trust_context = ? AND email = ? LIMIT 1');
        $dup->execute([$subTrust, $otpEmail]);
        if ($dup->fetch()) {
            throw new \RuntimeException("A trustee with OTP email {$otpEmail} already exists for that sub-trust.");
        }

        $uuid = sprintf('%04x%04x-%04x-4%03x-%04x-%04x%04x%04x',
            mt_rand(0,0xffff), mt_rand(0,0xffff), mt_rand(0,0xffff),
            mt_rand(0,0x0fff), mt_rand(0,0x3fff)|0x8000,
            mt_rand(0,0xffff), mt_rand(0,0xffff), mt_rand(0,0xffff));

        $pdo->prepare(
            "INSERT INTO trustees
               (trustee_uuid, full_name, trustee_type, sub_trust_context,
                email, personal_email, mobile, date_of_birth, address,
                appointment_date, appointment_instrument_ref,
                status, notes, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'active', ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())"
        )->execute([
            $uuid, $fullName, $trusteeType, $subTrust,
            $otpEmail,
            $personalEmail !== '' ? $personalEmail : null,
            $mobile        !== '' ? $mobile        : null,
            $dob           !== '' ? $dob           : null,
            $address       !== '' ? $address       : null,
            $apptDate, $apptRef,
            $notes         !== '' ? $notes         : null,
        ]);

        header('Location: ./trustees.php?msg=' . urlencode("Trustee {$fullName} added successfully."));
        exit;
    } catch (\Throwable $e) {
        $error  = $e->getMessage();
        $action = 'add';
    }
}

// ── POST: change trustee status (resign / remove / suspend / reinstate) ───────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['_action'] ?? '') === 'change_status') {
    $uuid = trim($_POST['trustee_uuid'] ?? '');
    try {
        $newStatus  = trim($_POST['new_status']  ?? '');
        $statusDate = trim($_POST['status_date'] ?? '');
        $notes      = trim($_POST['status_notes'] ?? '');
        $allowed    = ['active','resigned','removed','suspended'];
        if (!in_array($newStatus, $allowed, true)) {
            throw new \RuntimeException('Invalid status.');
        }
        if ($newStatus !== 'active' && $statusDate === '') {
            throw new \RuntimeException('A date is required when changing status to ' . $newStatus . '.');
        }
        $pdo->prepare(
            "UPDATE trustees SET
               status      = ?,
               status_date = ?,
               notes       = CASE WHEN ? != '' THEN CONCAT(COALESCE(notes,''), '\n[', UTC_TIMESTAMP(), '] ', ?) ELSE notes END,
               updated_at  = UTC_TIMESTAMP()
             WHERE trustee_uuid = ?"
        )->execute([
            $newStatus,
            $statusDate !== '' ? $statusDate : null,
            $notes, $notes,
            $uuid,
        ]);
        header('Location: ./trustees.php?msg=' . urlencode('Trustee status updated.'));
        exit;
    } catch (\Throwable $e) {
        $error = $e->getMessage();
    }
}

$action          = trim((string)($_GET['action']        ?? ''));
$statusChangeUuid = trim((string)($_GET['status_change'] ?? ''));

$stmt = $pdo->prepare('SELECT * FROM trustees ORDER BY sub_trust_context, appointment_date, status');
$stmt->execute();
$trustees = $stmt->fetchAll(PDO::FETCH_ASSOC);

$editUuid = trim((string)($_GET['edit'] ?? ''));

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
$statusOptions = [
    'active'    => 'Active',
    'resigned'  => 'Resigned',
    'removed'   => 'Removed',
    'suspended' => 'Suspended',
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
  border-radius: 10px; margin-bottom: 16px; overflow: hidden;
}
.trustee-card.active  { border-color: rgba(82,184,122,.25); }
.trustee-card.editing { border-color: rgba(212,178,92,.35); }
.trustee-head {
  display: flex; justify-content: space-between; align-items: center;
  padding: 13px 18px; border-bottom: 1px solid var(--line);
  flex-wrap: wrap; gap: 8px;
}
.trustee-head h3   { font-size: .88rem; font-weight: 700; margin: 0; color: var(--text); }
.trustee-head .sub { font-size: .75rem; color: var(--sub); margin-top: 2px; }
.trustee-body { padding: 16px 18px; }
.dg { display: grid; grid-template-columns: 200px 1fr; gap: 6px 14px; font-size: .81rem; }
.dg-l { color: var(--dim); padding-top: 1px; }
.dg-v { color: var(--text); word-break: break-word; }
.dg-v.mono    { font-family: monospace; font-size: .73rem; }
.dg-v.gold    { color: var(--gold); }
.dg-v.missing { color: var(--err); font-style: italic; }
.edit-form { margin-top: 14px; border-top: 1px solid var(--line); padding-top: 16px; }
.fg { margin-bottom: 12px; }
.fg label { display: block; font-size: .75rem; color: var(--sub); margin-bottom: 4px; }
.fg input, .fg select, .fg textarea {
  width: 100%; box-sizing: border-box;
  background: var(--input); border: 1px solid var(--line2); border-radius: 6px;
  color: var(--text); font-size: .82rem; padding: 6px 9px;
}
.fg textarea { min-height: 60px; resize: vertical; }
.fg-row { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }
.required { color: var(--err); }
.form-actions { display: flex; gap: 10px; align-items: center; margin-top: 14px; }
.btn { display: inline-block; padding: 6px 14px; border-radius: 7px; font-size: .78rem;
       font-weight: 700; cursor: pointer; border: none; text-decoration: none; }
.btn-primary { background: rgba(212,178,92,.18); border: 1px solid rgba(212,178,92,.4); color: var(--gold); }
.btn-primary:hover { background: rgba(212,178,92,.32); }
.btn-ghost { background: none; border: 1px solid var(--line2); color: var(--sub); }
.btn-ghost:hover { border-color: var(--gold); color: var(--gold); }
.btn-sm { padding: 4px 10px; font-size: .73rem; }
.msg-ok  { background: var(--okb);  border: 1px solid rgba(82,184,122,.3); color: var(--ok);
           border-radius: 7px; padding: 10px 14px; font-size: .83rem; margin-bottom: 14px; }
.msg-err { background: var(--errb); border: 1px solid rgba(192,85,58,.3);  color: var(--err);
           border-radius: 7px; padding: 10px 14px; font-size: .83rem; margin-bottom: 14px; }
.notice  { background: var(--warnb); border: 1px solid rgba(212,148,74,.3);
           border-radius: 8px; padding: 12px 16px; font-size: .82rem; color: var(--warn); margin-bottom: 18px; }
.sub-heading {
  font-size: .7rem; letter-spacing: .1em; text-transform: uppercase;
  color: var(--gold); font-weight: 700; margin: 20px 0 10px;
}
.add-form-card {
  background: var(--panel2); border: 1px solid rgba(212,178,92,.3);
  border-radius: 10px; padding: 22px 24px; margin-bottom: 22px;
}
.add-form-card h3 { font-size: .88rem; font-weight: 700; color: var(--gold); margin: 0 0 16px; }
.status-panel {
  background: var(--warnb); border: 1px solid rgba(212,148,74,.3);
  border-radius: 8px; padding: 14px 16px; margin-top: 12px;
}
.status-panel h4 { font-size: .8rem; font-weight: 700; color: var(--warn); margin: 0 0 12px; }
</style>
</head>
<body>
<div class="admin-shell">
<?php admin_sidebar_render('trustees_register'); ?>
<div class="main">

<?php if ($message): ?><div class="msg-ok"><?= $message ?></div><?php endif; ?>
<?php if ($error):   ?><div class="msg-err"><?= tr_h($error) ?></div><?php endif; ?>

<div class="topbar" style="margin-bottom:18px">
  <div style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:10px">
    <div>
      <h2>👔 Trustees Register</h2>
      <p>Full record of all current and former trustees of each sub-trust — names, dates of birth,
         addresses, appointment dates, and cessation dates. Click <strong>Edit</strong> to update any record.</p>
    </div>
    <?php if ($action !== 'add'): ?>
      <a href="./trustees.php?action=add" class="btn btn-primary">+ Add Trustee</a>
    <?php else: ?>
      <a href="./trustees.php" class="btn btn-ghost">✕ Cancel</a>
    <?php endif; ?>
  </div>
</div>

<div class="notice">
  ℹ Board meetings are not applicable while a single Caretaker Trustee is in office.
  The Board Meeting infrastructure activates on appointment of a second Trustee under Declaration cl.1.8.
</div>

<?php if ($action === 'add'): ?>
<!-- ── Add Trustee Form ────────────────────────────────────────────── -->
<div class="add-form-card">
  <h3>Add Trustee</h3>
  <p style="font-size:.8rem;color:var(--sub);margin-bottom:16px">
    An executed appointment instrument reference (TDR or deed) is required before a trustee can be added.
  </p>
  <form method="POST">
    <input type="hidden" name="_action" value="add_trustee">
    <div class="fg-row">
      <div class="fg">
        <label>Full Name <span class="required">*</span></label>
        <input type="text" name="full_name" required value="<?= tr_h($_POST['full_name'] ?? '') ?>">
      </div>
      <div class="fg">
        <label>Date of Birth</label>
        <input type="date" name="date_of_birth" value="<?= tr_h($_POST['date_of_birth'] ?? '') ?>">
      </div>
    </div>
    <div class="fg-row">
      <div class="fg">
        <label>Mobile</label>
        <input type="tel" name="mobile" value="<?= tr_h($_POST['mobile'] ?? '') ?>" placeholder="04xx xxx xxx">
      </div>
      <div class="fg">
        <label>Personal Email</label>
        <input type="email" name="personal_email" value="<?= tr_h($_POST['personal_email'] ?? '') ?>" placeholder="personal@example.com">
      </div>
    </div>
    <div class="fg">
      <label>Address</label>
      <input type="text" name="address" value="<?= tr_h($_POST['address'] ?? '') ?>"
             placeholder="Full street address including suburb, state, postcode">
    </div>
    <div class="fg-row">
      <div class="fg">
        <label>Trustee Type <span class="required">*</span></label>
        <select name="trustee_type" required>
          <option value="">— select —</option>
          <?php foreach ($typeLabels as $val => $lbl): ?>
            <option value="<?= tr_h($val) ?>" <?= (($_POST['trustee_type'] ?? '') === $val) ? 'selected' : '' ?>>
              <?= tr_h($lbl) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="fg">
        <label>Sub-Trust Context <span class="required">*</span></label>
        <select name="sub_trust_context" required>
          <option value="">— select —</option>
          <?php foreach ($subTrustLabels as $val => $lbl): ?>
            <option value="<?= tr_h($val) ?>" <?= (($_POST['sub_trust_context'] ?? '') === $val) ? 'selected' : '' ?>>
              <?= tr_h($lbl) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>
    <div class="fg">
      <label>OTP Email (sub-trust execution address) <span class="required">*</span></label>
      <input type="email" name="email" required value="<?= tr_h($_POST['email'] ?? '') ?>"
             placeholder="sub-trust-x@cogsaustralia.org">
    </div>
    <div class="fg-row">
      <div class="fg">
        <label>Date Appointed <span class="required">*</span></label>
        <input type="date" name="appointment_date" required value="<?= tr_h($_POST['appointment_date'] ?? '') ?>">
      </div>
      <div class="fg">
        <label>Appointment Instrument Reference <span class="required">*</span></label>
        <input type="text" name="appointment_instrument_ref" required
               value="<?= tr_h($_POST['appointment_instrument_ref'] ?? '') ?>"
               placeholder="e.g. TDR-20260425-002 or CJVM_Declaration_v1.0">
      </div>
    </div>
    <div class="fg">
      <label>Notes</label>
      <textarea name="notes"><?= tr_h($_POST['notes'] ?? '') ?></textarea>
    </div>
    <div class="form-actions">
      <button type="submit" class="btn btn-primary">Add Trustee</button>
      <a href="./trustees.php" class="btn btn-ghost">Cancel</a>
    </div>
  </form>
</div>
<?php endif; ?>

<?php
$grouped    = [];
foreach ($trustees as $t) $grouped[$t['sub_trust_context']][] = $t;
$groupOrder = ['sub_trust_a','sub_trust_b','sub_trust_c','all'];
?>

<?php foreach ($groupOrder as $ctx):
  if (empty($grouped[$ctx])) continue; ?>

<div class="sub-heading"><?= tr_h($subTrustLabels[$ctx] ?? $ctx) ?></div>

<?php foreach ($grouped[$ctx] as $t):
  [$bc,$bl] = $statusBadge[$t['status']] ?? ['badge-warn',$t['status']];
  $isEditing = ($editUuid === $t['trustee_uuid']); ?>

<div class="trustee-card <?= $t['status']==='active'?'active':'' ?> <?= $isEditing?'editing':'' ?>">
  <div class="trustee-head">
    <div>
      <h3><?= tr_h($t['full_name']) ?></h3>
      <div class="sub">
        <?= tr_h($typeLabels[$t['trustee_type']] ?? $t['trustee_type']) ?>
        &nbsp;·&nbsp;
        <?= tr_h($subTrustLabels[$t['sub_trust_context']] ?? $t['sub_trust_context']) ?>
      </div>
    </div>
    <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
      <span class="badge <?= $bc ?>"><?= $bl ?></span>
      <?php if (!$isEditing): ?>
        <a href="./trustees.php?edit=<?= urlencode($t['trustee_uuid']) ?>" class="btn btn-ghost btn-sm">✎ Edit</a>
        <a href="./trustees.php?status_change=<?= urlencode($t['trustee_uuid']) ?>" class="btn btn-ghost btn-sm"
           style="border-color:rgba(212,148,74,.4);color:var(--warn)">⬤ Status</a>
      <?php else: ?>
        <a href="./trustees.php" class="btn btn-ghost btn-sm">✕ Cancel</a>
      <?php endif; ?>
    </div>
  </div>
  <?php $isStatusChange = ($statusChangeUuid === $t['trustee_uuid'] && !$isEditing); ?>

  <?php if (!$isEditing): ?>
  <div class="trustee-body">
    <div class="dg">
      <span class="dg-l">Full Name</span>
      <span class="dg-v"><?= tr_h($t['full_name']) ?></span>

      <span class="dg-l">Date of Birth</span>
      <span class="dg-v <?= empty($t['date_of_birth'])?'missing':'' ?>">
        <?= !empty($t['date_of_birth']) ? tr_h($t['date_of_birth']) : 'Not recorded — click Edit' ?>
      </span>

      <span class="dg-l">Mobile</span>
      <span class="dg-v <?= empty($t['mobile'])?'missing':'' ?>">
        <?= !empty($t['mobile']) ? tr_h($t['mobile']) : 'Not recorded — click Edit' ?>
      </span>

      <span class="dg-l">Personal Email</span>
      <span class="dg-v <?= empty($t['personal_email'])?'missing':'' ?>">
        <?= !empty($t['personal_email']) ? tr_h($t['personal_email']) : 'Not recorded — click Edit' ?>
      </span>

      <span class="dg-l">Address</span>
      <span class="dg-v"><?= !empty($t['address']) ? tr_h($t['address']) : '—' ?></span>

      <span class="dg-l">OTP Email</span>
      <span class="dg-v gold"><?= tr_h($t['email']) ?></span>

      <span class="dg-l">Date Appointed</span>
      <span class="dg-v"><?= tr_h($t['appointment_date']) ?></span>

      <span class="dg-l">Appointment Instrument</span>
      <span class="dg-v mono"><?= tr_h($t['appointment_instrument_ref'] ?? '—') ?></span>

      <span class="dg-l">Status</span>
      <span class="dg-v"><span class="badge <?= $bc ?>"><?= $bl ?></span></span>

      <?php if ($t['status_date']): ?>
        <span class="dg-l">Date Ended</span>
        <span class="dg-v"><?= tr_h($t['status_date']) ?></span>
      <?php endif; ?>

      <?php if ($t['notes']): ?>
        <span class="dg-l">Notes</span>
        <span class="dg-v"><?= tr_h($t['notes']) ?></span>
      <?php endif; ?>

      <span class="dg-l">UUID</span>
      <span class="dg-v mono"><?= tr_h($t['trustee_uuid']) ?></span>
    </div>

    <?php if ($isStatusChange): ?>
    <div class="status-panel">
      <h4>Change Trustee Status</h4>
      <form method="POST" style="display:grid;grid-template-columns:1fr 1fr 1fr auto;gap:10px;align-items:end">
        <input type="hidden" name="_action"      value="change_status">
        <input type="hidden" name="trustee_uuid" value="<?= tr_h($t['trustee_uuid']) ?>">
        <div class="fg" style="margin:0">
          <label>New Status <span class="required">*</span></label>
          <select name="new_status" required>
            <option value="">— select —</option>
            <?php foreach ($statusOptions as $val => $lbl): ?>
              <option value="<?= tr_h($val) ?>" <?= $t['status']===$val ? 'disabled' : '' ?>>
                <?= tr_h($lbl) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="fg" style="margin:0">
          <label>Effective Date</label>
          <input type="date" name="status_date" value="<?= tr_h(date('Y-m-d')) ?>">
        </div>
        <div class="fg" style="margin:0">
          <label>Reason / Note <span style="color:var(--dim)">(appended to notes)</span></label>
          <input type="text" name="status_notes" placeholder="e.g. Resigned by letter dated…">
        </div>
        <div style="display:flex;gap:8px;padding-bottom:1px">
          <button type="submit" class="btn btn-primary">Save</button>
          <a href="./trustees.php" class="btn btn-ghost">Cancel</a>
        </div>
      </form>
    </div>
    <?php endif; ?>

  </div>

  <?php else: ?>
  <div class="trustee-body">
    <form method="POST">
      <input type="hidden" name="_action"      value="update_trustee">
      <input type="hidden" name="trustee_uuid" value="<?= tr_h($t['trustee_uuid']) ?>">
      <div class="edit-form">
        <div class="fg-row">
          <div class="fg">
            <label>Full Name <span class="required">*</span></label>
            <input type="text" name="full_name" required value="<?= tr_h($t['full_name']) ?>">
          </div>
          <div class="fg">
            <label>Date of Birth</label>
            <input type="date" name="date_of_birth" value="<?= tr_h($t['date_of_birth'] ?? '') ?>">
          </div>
        </div>
        <div class="fg-row">
          <div class="fg">
            <label>Mobile</label>
            <input type="tel" name="mobile" value="<?= tr_h($t['mobile'] ?? '') ?>"
                   placeholder="04xx xxx xxx">
          </div>
          <div class="fg">
            <label>Personal Email</label>
            <input type="email" name="personal_email" value="<?= tr_h($t['personal_email'] ?? '') ?>"
                   placeholder="personal@example.com">
          </div>
        </div>
        <div class="fg">
          <label>Address</label>
          <input type="text" name="address" value="<?= tr_h($t['address'] ?? '') ?>"
                 placeholder="Full street address including suburb, state, postcode">
        </div>
        <div class="fg">
          <label>OTP Email</label>
          <input type="email" name="email" required value="<?= tr_h($t['email']) ?>">
        </div>
        <div class="fg-row">
          <div class="fg">
            <label>Date Appointed <span class="required">*</span></label>
            <input type="date" name="appointment_date" required value="<?= tr_h($t['appointment_date']) ?>">
          </div>
          <div class="fg">
            <label>Appointment Instrument Reference</label>
            <input type="text" name="appointment_instrument_ref"
                   value="<?= tr_h($t['appointment_instrument_ref'] ?? '') ?>"
                   placeholder="e.g. CJVM_Hybrid_Trust_Declaration_v1.0">
          </div>
        </div>
        <div class="fg-row">
          <div class="fg">
            <label>Status <span class="required">*</span></label>
            <select name="status" required>
              <?php foreach ($statusOptions as $val => $lbl): ?>
                <option value="<?= tr_h($val) ?>" <?= $t['status']===$val?'selected':'' ?>>
                  <?= tr_h($lbl) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="fg">
            <label>Date Ended <span style="color:var(--dim)">(blank if still active)</span></label>
            <input type="date" name="status_date" value="<?= tr_h($t['status_date'] ?? '') ?>">
          </div>
        </div>
        <div class="fg">
          <label>Notes</label>
          <textarea name="notes"><?= tr_h($t['notes'] ?? '') ?></textarea>
        </div>
        <div class="form-actions">
          <button type="submit" class="btn btn-primary">Save Record</button>
          <a href="./trustees.php" class="btn btn-ghost">Cancel</a>
        </div>
      </div>
    </form>
  </div>
  <?php endif; ?>

</div><!-- .trustee-card -->
<?php endforeach; ?>
<?php endforeach; ?>

<?php if (empty($trustees)): ?>
  <p style="color:var(--sub);font-size:.85rem">No trustees found.</p>
<?php endif; ?>

<p style="font-size:.75rem;color:var(--dim);margin-top:28px">
  New trustee rows require an executed appointment instrument reference (TDR or deed).
  Contact the system administrator to add a new trustee row after the instrument is executed.
</p>

</div><!-- .main -->
</div><!-- .admin-shell -->
</body>
</html>
