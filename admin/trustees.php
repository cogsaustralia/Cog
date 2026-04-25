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
        $opFocus       = trim($_POST['operational_focus']          ?? 'all');
        $apptDate      = trim($_POST['appointment_date']           ?? '');
        $apptRef       = trim($_POST['appointment_instrument_ref'] ?? '');
        $notes         = trim($_POST['notes']                      ?? '');

        if ($fullName    === '') throw new \RuntimeException('Full name is required.');
        if ($otpEmail    === '') throw new \RuntimeException('Execution email is required.');
        if ($trusteeType === '') throw new \RuntimeException('Trustee type is required.');
        if ($apptDate    === '') throw new \RuntimeException('Appointment date is required.');
        if ($apptRef     === '') throw new \RuntimeException('Appointment instrument reference is required — a TDR or deed must authorise this appointment.');

        if ($dob !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dob)) {
            throw new \RuntimeException('Invalid date of birth format — use YYYY-MM-DD.');
        }

        $allowedFocus = ['sub_trust_a','sub_trust_b','sub_trust_c','all','none'];
        if (!in_array($opFocus, $allowedFocus, true)) $opFocus = 'all';

        // Duplicate detection: prevent the same person being added twice as active Trustee
        $dup = $pdo->prepare("SELECT id FROM trustees WHERE full_name = ? AND status = 'active' LIMIT 1");
        $dup->execute([$fullName]);
        if ($dup->fetch()) {
            throw new \RuntimeException("An active Trustee named '{$fullName}' already exists. A Trustee is appointed to the entire Hybrid Trust — only one active row per person is permitted.");
        }

        $uuid = sprintf('%04x%04x-%04x-4%03x-%04x-%04x%04x%04x',
            mt_rand(0,0xffff), mt_rand(0,0xffff), mt_rand(0,0xffff),
            mt_rand(0,0x0fff), mt_rand(0,0x3fff)|0x8000,
            mt_rand(0,0xffff), mt_rand(0,0xffff), mt_rand(0,0xffff));

        $pdo->prepare(
            "INSERT INTO trustees
               (trustee_uuid, full_name, trustee_type, sub_trust_context, operational_focus,
                email, personal_email, mobile, date_of_birth, address,
                appointment_date, appointment_instrument_ref,
                status, notes, created_at, updated_at)
             VALUES (?, ?, ?, 'all', ?, ?, ?, ?, ?, ?, ?, ?, 'active', ?, current_timestamp(), current_timestamp())"
        )->execute([
            $uuid, $fullName, $trusteeType, $opFocus,
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

$stmt = $pdo->prepare('SELECT * FROM trustees ORDER BY status DESC, appointment_date ASC');
$stmt->execute();
$trustees = $stmt->fetchAll(PDO::FETCH_ASSOC);

$editUuid = trim((string)($_GET['edit'] ?? ''));

$typeLabels = [
    'caretaker_trustee'  => 'Caretaker Trustee',
    'individual_trustee' => 'Individual Trustee',
    'managing_director'  => 'Managing Director',
    'director'           => 'Director',
];
$opFocusLabels = [
    'sub_trust_a' => 'Sub-Trust A (operational focus)',
    'sub_trust_b' => 'Sub-Trust B (operational focus)',
    'sub_trust_c' => 'Sub-Trust C (operational focus)',
    'all'         => 'All Sub-Trusts',
    'none'        => 'None specified',
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
      <p>Full record of all current and former Trustees of the COGS of Australia Foundation
         Community Joint Venture Mainspring Hybrid Trust. Each Trustee holds capacity over
         the entire Hybrid Trust including all Sub-Trusts. Click <strong>Edit</strong> to update any record.</p>
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
        <label>Operational Focus <span style="color:var(--dim)">(optional annotation)</span></label>
        <select name="operational_focus">
          <?php foreach ($opFocusLabels as $val => $lbl): ?>
            <option value="<?= tr_h($val) ?>" <?= (($_POST['operational_focus'] ?? 'all') === $val) ? 'selected' : '' ?>>
              <?= tr_h($lbl) ?></option>
          <?php endforeach; ?>
        </select>
        <div style="font-size:.71rem;color:var(--dim);margin-top:3px">
          A Trustee holds capacity over the entire Hybrid Trust. This field is an internal annotation only.
        </div>
      </div>
    </div>
    <div class="fg">
      <label>Execution Email (TDR OTP delivery address) <span class="required">*</span></label>
      <input type="email" name="email" required value="<?= tr_h($_POST['email'] ?? '') ?>"
             placeholder="trustee@cogsaustralia.org">
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

<?php foreach ($trustees as $t):
  [$bc,$bl] = $statusBadge[$t['status']] ?? ['badge-warn',$t['status']];
  $isEditing = ($editUuid === $t['trustee_uuid']); ?>

<div class="trustee-card <?= $t['status']==='active'?'active':'' ?> <?= $isEditing?'editing':'' ?>">
  <div class="trustee-head">
    <div>
      <h3><?= tr_h($t['full_name']) ?></h3>
      <div class="sub">
        <?= tr_h($typeLabels[$t['trustee_type']] ?? $t['trustee_type']) ?>
        &nbsp;·&nbsp; CJVM Hybrid Trust (incl. all Sub-Trusts)
        <?php if (!empty($t['operational_focus']) && $t['operational_focus'] !== 'all' && $t['operational_focus'] !== 'none'): ?>
          &nbsp;·&nbsp; <?= tr_h($opFocusLabels[$t['operational_focus']] ?? $t['operational_focus']) ?>
        <?php endif; ?>
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

      <span class="dg-l">Execution Email</span>
      <span class="dg-v gold"><?= tr_h($t['email']) ?></span>

      <span class="dg-l">Operational Focus</span>
      <span class="dg-v"><?= tr_h($opFocusLabels[$t['operational_focus'] ?? 'all'] ?? '—') ?></span>

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

<?php if (empty($trustees)): ?>
  <p style="color:var(--sub);font-size:.85rem">No trustees found.</p>
<?php endif; ?>

<!-- ── Trustee Decision Records ────────────────────────────────────────────── -->
<?php
// Load all TDRs grouped by sub_trust_context
// Priority map: lower number = higher priority
$tdrPriority = [
    // Tier 1 — Immediate / blocking
    'TDR-20260422-001' => 1,  // A-1 Sub-Trust A bank account (executed)
    'TDR-20260425-002' => 2,  // A-2 CHESS Registration Policy
    'TDR-20260425-003' => 3,  // A-3 Ratification of LGM holdings
    'TDR-20260425-004' => 4,  // A-4 Stockbroker appointment
    'TDR-20260425-006' => 5,  // B-1 Sub-Trust B bank account
    'TDR-20260425-009' => 6,  // C-1 Sub-Trust C bank account
    'TDR-20260425-013' => 7,  // X-1 Indemnity & cost allocation policy
    // Tier 2 — Before Governance Foundation Day (14 May 2026)
    'TDR-20260425-005' => 8,  // A-5 Non-MIS Sub-Trust A
    'TDR-20260425-007' => 9,  // B-2 Non-MIS Sub-Trust B
    'TDR-20260425-008' => 10, // B-3 Beneficial Unit Register
    'TDR-20260425-010' => 11, // C-2 Non-MIS Sub-Trust C
    'TDR-20260425-011' => 12, // C-3 ACNC Registration
    'TDR-20260425-012' => 13, // C-4 DGR Application
    'TDR-20260425-014' => 14, // X-2 Inaugural Meeting timetable
    'TDR-20260425-015' => 15, // X-3 Auditor appointment
    // Tier 3 — Before Expansion Day
    'TDR-20260425-016' => 16, // X-4 Privacy policy
    'TDR-20260425-017' => 17, // X-5 AML/CTF procedure
];
$tdrTierLabels = [
    1  => '🔴 Tier 1 — Immediate',
    8  => '🟡 Tier 2 — Before Foundation Day',
    16 => '🟢 Tier 3 — Before Expansion Day',
    99 => '⚪ Tier 4 — Event-triggered',
];

$tdrStmt = $pdo->query(
    "SELECT decision_uuid, decision_ref, sub_trust_context, decision_category,
            title, effective_date, status
     FROM trustee_decisions
     ORDER BY sub_trust_context, decision_ref ASC"
);
$allTdrs   = $tdrStmt->fetchAll(PDO::FETCH_ASSOC);

// Sort by priority within each sub-trust group
usort($allTdrs, function($a, $b) use ($tdrPriority) {
    $pa = $tdrPriority[$a['decision_ref']] ?? 99;
    $pb = $tdrPriority[$b['decision_ref']] ?? 99;
    if ($pa !== $pb) return $pa - $pb;
    return strcmp($a['decision_ref'], $b['decision_ref']);
});

$tdrGrouped = [];
foreach ($allTdrs as $tdr) {
    $tdrGrouped[$tdr['sub_trust_context']][] = $tdr;
}
$tdrContextOrder = ['sub_trust_a','sub_trust_b','sub_trust_c','all'];
$tdrContextLabels = [
    'sub_trust_a' => 'Sub-Trust A (Members Asset Pool)',
    'sub_trust_b' => 'Sub-Trust B (Dividend Distribution)',
    'sub_trust_c' => 'Sub-Trust C (Discretionary Charitable)',
    'all'         => 'All Sub-Trusts',
];
$tdrCategoryLabels = [
    'bank_account'                  => 'Bank Account',
    'investment_instruction'        => 'Investment',
    'distribution'                  => 'Distribution',
    'operational_amendment'         => 'Operational Amendment',
    'regulatory_compliance'         => 'Regulatory Compliance',
    'fnac_engagement'               => 'FNAC Engagement',
    'member_poll_implementation'    => 'Poll Implementation',
    'fiduciary_conflict_invocation' => 'Fiduciary Conflict',
    'record_keeping'                => 'Record Keeping',
    'governance_instrument'         => 'Governance',
    'other'                         => 'Other',
];
$tdrStatusBadge = [
    'draft'             => ['badge-warn', 'Draft'],
    'pending_execution' => ['badge-warn', 'Pending Execution'],
    'fully_executed'    => ['badge-ok',   'Executed'],
    'superseded'        => ['badge-err',  'Superseded'],
];
?>

<div class="sub-heading" style="margin-top:32px">Trustee Decision Records</div>

<p style="font-size:.81rem;color:var(--sub);margin-bottom:16px">
  All TDRs required or anticipated across all three sub-trusts.
  Click a reference to view or execute the full record.
</p>

<?php foreach ($tdrContextOrder as $ctx):
  if (empty($tdrGrouped[$ctx])) continue;
  $ctxLabel = $tdrContextLabels[$ctx] ?? $ctx; ?>

<div style="margin-bottom:20px">
  <div style="font-size:.72rem;letter-spacing:.08em;text-transform:uppercase;
              color:var(--sub);font-weight:700;margin-bottom:8px;padding-bottom:4px;
              border-bottom:1px solid var(--line2)">
    <?= tr_h($ctxLabel) ?>
    <span style="color:var(--dim);font-weight:400;margin-left:8px">
      — <?= count($tdrGrouped[$ctx]) ?> record<?= count($tdrGrouped[$ctx]) !== 1 ? 's' : '' ?>
    </span>
  </div>

  <table style="width:100%;border-collapse:collapse;font-size:.8rem">
    <thead>
      <tr>
        <th style="text-align:left;padding:6px 10px;color:var(--gold);font-size:.7rem;
                   text-transform:uppercase;letter-spacing:.07em;background:var(--panel2);
                   border-bottom:1px solid var(--line);width:30px">#</th>
        <th style="text-align:left;padding:6px 10px;color:var(--gold);font-size:.7rem;
                   text-transform:uppercase;letter-spacing:.07em;background:var(--panel2);
                   border-bottom:1px solid var(--line)">Reference</th>
        <th style="text-align:left;padding:6px 10px;color:var(--gold);font-size:.7rem;
                   text-transform:uppercase;letter-spacing:.07em;background:var(--panel2);
                   border-bottom:1px solid var(--line)">Title</th>
        <th style="text-align:left;padding:6px 10px;color:var(--gold);font-size:.7rem;
                   text-transform:uppercase;letter-spacing:.07em;background:var(--panel2);
                   border-bottom:1px solid var(--line)">Category</th>
        <th style="text-align:left;padding:6px 10px;color:var(--gold);font-size:.7rem;
                   text-transform:uppercase;letter-spacing:.07em;background:var(--panel2);
                   border-bottom:1px solid var(--line)">Status</th>
      </tr>
    </thead>
    <tbody>
    <?php
    $lastTier = null;
    foreach ($tdrGrouped[$ctx] as $tdr):
      [$tbc, $tbl] = $tdrStatusBadge[$tdr['status']] ?? ['badge-warn', $tdr['status']];
      $pri  = $tdrPriority[$tdr['decision_ref']] ?? 99;
      $tier = $pri >= 16 ? 16 : ($pri >= 8 ? 8 : 1);
      if ($tier !== $lastTier):
        $tierLabel = $tdrTierLabels[$tier] ?? 'Tier 4';
        $lastTier  = $tier;
    ?>
      <tr>
        <td colspan="5" style="padding:8px 10px 4px;background:var(--panel);
            font-size:.7rem;font-weight:700;letter-spacing:.06em;color:var(--sub);
            border-bottom:1px solid var(--line2)">
          <?= tr_h($tierLabel) ?>
        </td>
      </tr>
    <?php endif; ?>
      <tr style="border-bottom:1px solid var(--line2)">
        <td style="padding:7px 10px;color:var(--dim);font-family:monospace;font-size:.72rem">
          <?= $pri < 99 ? $pri : '—' ?>
        </td>
        <td style="padding:7px 10px">
          <a href="./trustee_decisions.php?id=<?= urlencode($tdr['decision_uuid']) ?>"
             style="color:var(--gold);font-family:monospace;font-size:.78rem;
                    font-weight:700;text-decoration:none">
            <?= tr_h($tdr['decision_ref']) ?>
          </a>
        </td>
        <td style="padding:7px 10px;color:var(--text);max-width:320px">
          <?= tr_h($tdr['title']) ?>
        </td>
        <td style="padding:7px 10px;color:var(--sub)">
          <?= tr_h($tdrCategoryLabels[$tdr['decision_category']] ?? $tdr['decision_category']) ?>
        </td>
        <td style="padding:7px 10px">
          <span class="badge <?= $tbc ?>"><?= $tbl ?></span>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php endforeach; ?>

<?php if (empty($allTdrs)): ?>
  <p style="color:var(--sub);font-size:.82rem">No Trustee Decision Records found.</p>
<?php endif; ?>

<p style="font-size:.75rem;color:var(--dim);margin-top:28px">
  New trustee rows require an executed appointment instrument reference (TDR or deed).
  Contact the system administrator to add a new trustee row after the instrument is executed.
</p>

</div><!-- .main -->
</div><!-- .admin-shell -->
</body>
</html>
